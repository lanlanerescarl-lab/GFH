<?php

declare(strict_types=1);

namespace Gfh\Core\Database;

use Gfh\Core\App;

/**
 * Runs the seed files of a folder, in file-name order, against an organization database.
 *
 *  - NAME.sql  is executed as is, in one transaction.
 *  - NAME.php  must return a function (PDO $organization, PDO $core, App $app): void. It runs inside a
 *              transaction on the organization database; the core connection is for creating accounts.
 *
 * A seed function also receives the organization slug as an optional fourth argument (null when the caller does not
 * know it), so a seed that only makes sense for one organization can refuse to run anywhere else. Older seeds that
 * declare three parameters simply ignore it.
 *
 * Seeds are not tracked, so write them to be repeatable (INSERT ... ON CONFLICT DO NOTHING) or run them
 * once on a fresh database.
 */
final class SeedRunner
{
    /**
     * @param callable(string): void|null $onFile called with each file name before it runs
     * @param string|null $slug the organization's slug, handed to every PHP seed as its fourth argument
     * @return int number of files run
     */
    public function run(string $directory, \PDO $organization, \PDO $core, App $app, ?callable $onFile = null, ?string $slug = null): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $names = scandir($directory);
        if ($names === false) {
            throw new \RuntimeException(sprintf('Seed folder cannot be read: %s', $directory));
        }
        sort($names, SORT_STRING);

        $count = 0;
        foreach ($names as $name) {
            $path = $directory . '/' . $name;
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!is_file($path) || !in_array($extension, ['sql', 'php'], true)) {
                continue;
            }

            if ($onFile !== null) {
                $onFile($name);
            }

            if ($extension === 'sql') {
                $sql = (string) file_get_contents($path);
                if (trim($sql) === '') {
                    continue;
                }
                Database::transaction($organization, static function (\PDO $pdo) use ($sql): void {
                    $pdo->exec($sql);
                });
            } else {
                // Load in an isolated scope so a seed file cannot overwrite this method's variables.
                $seed = (static fn (string $file): mixed => require $file)($path);
                if (!is_callable($seed)) {
                    throw new \RuntimeException(sprintf('Seed file %s must return a function (PDO $organization, PDO $core, App $app).', $name));
                }
                Database::transaction($organization, static function (\PDO $pdo) use ($seed, $core, $app, $slug): void {
                    $seed($pdo, $core, $app, $slug);
                });
            }

            $count++;
        }

        return $count;
    }
}
