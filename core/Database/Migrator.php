<?php

declare(strict_types=1);

namespace Gfh\Core\Database;

/**
 * Applies numbered SQL files to a database and remembers what ran in schema_migrations.
 *
 * Rules:
 *  - Files are named NNNN_description.sql (for example 0003_sales.sql). The number is the version.
 *  - Each file runs once, in order, in its own transaction. Do not put BEGIN or COMMIT in a file.
 *  - Each applied file's SHA-256 checksum is stored. If an applied file is later edited or removed, the run is
 *    refused: fix problems with a new migration, never by editing an old one. Line endings are ignored, so
 *    a Windows checkout produces the same checksum.
 *  - A new file may not use a version lower than one already applied.
 *  - Running again when everything is applied changes nothing.
 */
final class Migrator
{
    /**
     * Applies every pending migration of the set.
     *
     * @param callable(int, string): void|null $onApplied called with (version, file name) after each migration
     * @return list<int> versions applied by this run
     */
    public function migrate(\PDO $pdo, MigrationSet $set, ?callable $onApplied = null): array
    {
        $this->ensureTrackingTable($pdo);

        $files = $this->discover($set);
        $applied = $this->appliedChecksums($pdo, $set->scope);

        foreach ($applied as $version => $checksum) {
            if (!isset($files[$version])) {
                throw new MigrationException(sprintf(
                    'Migration %04d of scope "%s" was applied, but its file is missing. Restore it.',
                    $version,
                    $set->scope,
                ));
            }
            if (!hash_equals($checksum, $files[$version]['checksum'])) {
                throw new MigrationException(sprintf(
                    'Migration %04d (%s) of scope "%s" was changed after it was applied. Revert the edit and add a new migration instead.',
                    $version,
                    $files[$version]['file'],
                    $set->scope,
                ));
            }
        }

        $highestApplied = $applied === [] ? 0 : max(array_keys($applied));
        $pending = array_diff_key($files, $applied);

        foreach ($pending as $version => $file) {
            if ($version < $highestApplied) {
                throw new MigrationException(sprintf(
                    'Migration %04d (%s) of scope "%s" is older than the latest applied migration (%04d). Give it a higher number.',
                    $version,
                    $file['file'],
                    $set->scope,
                    $highestApplied,
                ));
            }
        }

        $appliedNow = [];
        foreach ($pending as $version => $file) {
            if ($this->apply($pdo, $set->scope, $version, $file)) {
                $appliedNow[] = $version;
                if ($onApplied !== null) {
                    $onApplied($version, $file['file']);
                }
            }
        }

        return $appliedNow;
    }

    /** Highest applied version of a scope, 0 when none. */
    public function currentVersion(\PDO $pdo, string $scope): int
    {
        $this->ensureTrackingTable($pdo);
        $statement = $pdo->prepare('SELECT COALESCE(MAX(version), 0) FROM schema_migrations WHERE scope = :scope');
        $statement->execute(['scope' => $scope]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Rebuilds org_contract.measures as a union of the enabled extensions' ext_{key}_measures views
     * (columns measure_key, label, value, unit, as_of). With no views it becomes empty again.
     * Called when extensions are enabled or disabled (Stage 8).
     *
     * @param list<string> $extensionViews view names such as ext_batches_measures
     */
    public function rebuildMeasuresView(\PDO $pdo, array $extensionViews): void
    {
        $selects = [];

        foreach ($extensionViews as $view) {
            // The name is spliced into SQL, so only the documented pattern is accepted.
            if (preg_match('/^ext_[a-z][a-z0-9_]*_measures$/', $view) !== 1) {
                throw new MigrationException(sprintf('"%s" is not a valid measures view name (ext_{key}_measures).', $view));
            }

            $exists = $pdo->prepare('SELECT to_regclass(:name) IS NOT NULL');
            $exists->execute(['name' => $view]);
            if (!(bool) $exists->fetchColumn()) {
                throw new MigrationException(sprintf('The view %s does not exist. Migrate the extension first.', $view));
            }

            $selects[] = 'SELECT measure_key::text AS measure_key, label::text AS label, value::numeric AS value, '
                . 'unit::text AS unit, as_of::timestamptz AS as_of FROM ' . $view;
        }

        if ($selects === []) {
            $selects[] = 'SELECT NULL::text AS measure_key, NULL::text AS label, NULL::numeric AS value, '
                . 'NULL::text AS unit, NULL::timestamptz AS as_of WHERE false';
        }

        Database::transaction($pdo, static function (\PDO $pdo) use ($selects): void {
            $pdo->exec('CREATE OR REPLACE VIEW org_contract.measures AS ' . implode(' UNION ALL ', $selects));
        });
    }

    private function ensureTrackingTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'scope text NOT NULL, '
            . 'version integer NOT NULL, '
            . 'applied_at timestamptz NOT NULL DEFAULT now(), '
            . 'checksum text NOT NULL, '
            . 'PRIMARY KEY (scope, version))',
        );
    }

    /**
     * @return array<int, array{file: string, path: string, checksum: string}> keyed by version, ascending
     */
    private function discover(MigrationSet $set): array
    {
        if (!is_dir($set->directory)) {
            throw new MigrationException(sprintf('Migration folder not found: %s', $set->directory));
        }

        $names = scandir($set->directory);
        if ($names === false) {
            throw new MigrationException(sprintf('Migration folder cannot be read: %s', $set->directory));
        }

        $files = [];
        foreach ($names as $name) {
            if (!str_ends_with($name, '.sql')) {
                continue;
            }

            if (preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/', $name, $match) !== 1) {
                throw new MigrationException(sprintf(
                    'Migration file "%s" is not named like 0001_description.sql (lower-case letters, digits, underscores).',
                    $name,
                ));
            }

            $version = (int) $match[1];
            if ($version < 1) {
                throw new MigrationException(sprintf('Migration file "%s" must have a number of 0001 or higher.', $name));
            }
            if (isset($files[$version])) {
                throw new MigrationException(sprintf('Two migration files share the number %04d: %s and %s.', $version, $files[$version]['file'], $name));
            }

            $path = $set->directory . '/' . $name;
            $contents = file_get_contents($path);
            if ($contents === false || trim($contents) === '') {
                throw new MigrationException(sprintf('Migration file "%s" is empty or cannot be read.', $name));
            }

            $files[$version] = [
                'file' => $name,
                'path' => $path,
                'checksum' => self::checksum($contents),
            ];
        }

        ksort($files);

        return $files;
    }

    /** SHA-256 of the file with Windows line endings normalised. */
    public static function checksum(string $contents): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $contents));
    }

    /** @return array<int, string> version => checksum */
    private function appliedChecksums(\PDO $pdo, string $scope): array
    {
        $statement = $pdo->prepare('SELECT version, checksum FROM schema_migrations WHERE scope = :scope ORDER BY version');
        $statement->execute(['scope' => $scope]);

        $applied = [];
        foreach ($statement->fetchAll() as $row) {
            $applied[(int) $row['version']] = (string) $row['checksum'];
        }

        return $applied;
    }

    /**
     * Applies one file in its own transaction. Returns false when another runner applied it first.
     *
     * @param array{file: string, path: string, checksum: string} $file
     */
    private function apply(\PDO $pdo, string $scope, int $version, array $file): bool
    {
        $sql = (string) file_get_contents($file['path']);

        try {
            return Database::transaction($pdo, static function (\PDO $pdo) use ($scope, $version, $file, $sql): bool {
                // One runner at a time per database; the lock is released when the transaction ends.
                $pdo->exec("SELECT pg_advisory_xact_lock(hashtext('gfh_migrations'))");

                $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE scope = :scope AND version = :version');
                $check->execute(['scope' => $scope, 'version' => $version]);
                if ($check->fetchColumn() !== false) {
                    return false;
                }

                $pdo->exec($sql);

                $insert = $pdo->prepare(
                    'INSERT INTO schema_migrations (scope, version, checksum) VALUES (:scope, :version, :checksum)',
                );
                $insert->execute(['scope' => $scope, 'version' => $version, 'checksum' => $file['checksum']]);

                return true;
            });
        } catch (\PDOException $exception) {
            throw new MigrationException(
                sprintf('Migration %s of scope "%s" failed and was rolled back: %s', $file['file'], $scope, $exception->getMessage()),
                0,
                $exception,
            );
        }
    }
}
