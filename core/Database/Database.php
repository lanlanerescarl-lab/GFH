<?php

declare(strict_types=1);

namespace Gfh\Core\Database;

use Gfh\Core\Config\Config;
use Gfh\Core\Security\SecretBox;
use Gfh\Core\Tenancy\Organization;

/**
 * Opens PDO connections to the core database and to organization databases, and runs transactions.
 * A connection setting is data, not code: the core connection comes from .env, an organization's
 * connection comes from the encrypted value in the core registry. A database name never comes from a request.
 * Every connection uses UTC for timestamps, native prepared statements and exceptions for errors.
 */
final class Database
{
    private ?\PDO $core = null;
    private ?\PDO $coreMigrator = null;
    private ?SecretBox $secrets = null;

    public function __construct(private readonly Config $config)
    {
    }

    /** Core database as the limited web-request role (gfh_core_app). */
    public function core(): \PDO
    {
        return $this->core ??= self::open($this->coreConnection(false), 'public', false, 'gfh-core');
    }

    /** Core database as the migrator role. Used by bin/ scripts only. Falls back to the app user when no migrator is configured. */
    public function coreMigrator(): \PDO
    {
        return $this->coreMigrator ??= self::open($this->coreConnection(true), 'public', false, 'gfh-core-migrator');
    }

    /** An organization database as the limited web-request role (gfh_app), using the stored encrypted connection. */
    public function organization(Organization $organization): \PDO
    {
        return self::open($this->storedConnection($organization), $organization->dbSchema, false, 'gfh-org-' . $organization->slug);
    }

    /**
     * An organization database for migrations and seeds. A MIGRATOR_URL_<SLUG> setting (slug upper-cased,
     * hyphens as underscores) wins; otherwise the stored connection is used.
     * Creates the organization's schema first when it is not public.
     */
    public function organizationMigrator(Organization $organization): \PDO
    {
        $override = $this->config->get(self::migratorSettingName($organization->slug));
        $connection = ($override !== null && trim($override) !== '')
            ? ConnectionString::parse($override)
            : $this->storedConnection($organization);

        return self::open($connection, $organization->dbSchema, true, 'gfh-migrator-' . $organization->slug);
    }

    /** Name of the .env setting that overrides the migrator connection for one organization. */
    public static function migratorSettingName(string $slug): string
    {
        return 'MIGRATOR_URL_' . strtoupper(str_replace('-', '_', $slug));
    }

    /** Opens a connection directly from a connection string. Used by tests and by tools that already hold one. */
    public static function connect(ConnectionString $connection, string $schema = 'public', bool $createSchema = false, string $applicationName = 'gfh'): \PDO
    {
        return self::open($connection, $schema, $createSchema, $applicationName);
    }

    /** Encrypts a connection for storage in organizations.connection_encrypted. */
    public function encryptConnection(ConnectionString $connection): string
    {
        return $this->secrets()->encrypt($connection->toUrl());
    }

    /** Opens a connection to check it works (used by bin/make-org.php before registering). */
    public function test(ConnectionString $connection, string $schema = 'public'): void
    {
        self::open($connection, $schema, false, 'gfh-connection-test')->query('SELECT 1');
    }

    /**
     * Runs $work inside a transaction. Commits when it returns, rolls back when it throws.
     * Inside an existing transaction it uses a savepoint, so callers can nest freely.
     *
     * @template T
     * @param callable(\PDO): T $work
     * @return T
     */
    public static function transaction(\PDO $pdo, callable $work): mixed
    {
        if ($pdo->inTransaction()) {
            $savepoint = 'gfh_sp_' . bin2hex(random_bytes(4));
            $pdo->exec('SAVEPOINT ' . $savepoint);

            try {
                $result = $work($pdo);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);

                return $result;
            } catch (\Throwable $exception) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                throw $exception;
            }
        }

        $pdo->beginTransaction();

        try {
            $result = $work($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function secrets(): SecretBox
    {
        return $this->secrets ??= SecretBox::fromConfig($this->config);
    }

    private function storedConnection(Organization $organization): ConnectionString
    {
        return ConnectionString::parse($this->secrets()->decrypt($organization->connectionEncrypted));
    }

    private function coreConnection(bool $migrator): ConnectionString
    {
        $user = $migrator ? $this->config->string('CORE_DB_MIGRATOR_USER') : '';
        $password = $migrator ? $this->config->string('CORE_DB_MIGRATOR_PASSWORD') : '';

        if ($user === '') {
            $user = $this->config->required('CORE_DB_USER');
            $password = $this->config->string('CORE_DB_PASSWORD');
        }

        return ConnectionString::fromParts(
            $this->config->string('CORE_DB_HOST', '127.0.0.1'),
            $this->config->int('CORE_DB_PORT', 5432),
            $this->config->required('CORE_DB_NAME'),
            $user,
            $password,
            $this->config->string('CORE_DB_SSLMODE', 'prefer'),
        );
    }

    private static function open(ConnectionString $connection, string $schema, bool $createSchema, string $applicationName): \PDO
    {
        if (!extension_loaded('pdo_pgsql')) {
            throw new \RuntimeException('The pdo_pgsql PHP extension is not enabled. Enable it in php.ini.');
        }

        // The schema name is spliced into SQL below, so it must be a plain identifier.
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $schema) !== 1) {
            throw new \InvalidArgumentException('The database schema name is not a valid identifier.');
        }

        try {
            $pdo = new \PDO($connection->dsn(), $connection->user, $connection->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_TIMEOUT => 10,
            ]);
        } catch (\PDOException $exception) {
            throw new \RuntimeException(
                'Could not connect to ' . $connection->describe() . ': ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        $pdo->exec("SET TIME ZONE 'UTC'");
        $pdo->exec('SET application_name = ' . $pdo->quote($applicationName));

        if ($createSchema && $schema !== 'public') {
            $pdo->exec('CREATE SCHEMA IF NOT EXISTS "' . $schema . '"');
        }
        $pdo->exec('SET search_path TO "' . $schema . '"');

        return $pdo;
    }
}
