<?php

declare(strict_types=1);

namespace Gfh\Core\Console;

use Gfh\Core\Database\Database;
use Gfh\Core\Database\MigrationSet;
use Gfh\Core\Database\Migrator;
use Gfh\Core\Repository\OrganizationRepository;
use Gfh\Core\Tenancy\Organization;

/** php bin/migrate.php --core | --org=slug | --all */
final class MigrateCommand extends Command
{
    public function run(array $argv): int
    {
        try {
            $options = Arguments::parse($argv, ['core', 'org', 'all', 'help']);
        } catch (\InvalidArgumentException $exception) {
            $this->err($exception->getMessage());
            $this->usage();

            return 2;
        }

        $modes = array_intersect(['core', 'org', 'all'], array_keys($options));
        if (isset($options['help']) || count($modes) !== 1 || (isset($options['org']) && !is_string($options['org']))) {
            $this->usage();

            return isset($options['help']) ? 0 : 2;
        }

        $database = new Database($this->app->config());
        $migrator = new Migrator();

        try {
            if (isset($options['core'])) {
                $this->migrateCore($database, $migrator);

                return 0;
            }

            if (isset($options['org'])) {
                $repository = new OrganizationRepository($database->coreMigrator());
                $organization = $repository->findBySlug((string) $options['org']);
                if ($organization === null) {
                    $this->err(sprintf('No organization with the slug "%s" is registered. Register it with bin/make-org.php first.', (string) $options['org']));

                    return 1;
                }

                $this->migrateOrganization($database, $migrator, $repository, $organization);

                return 0;
            }

            return $this->migrateEverything($database, $migrator);
        } catch (\Throwable $exception) {
            $this->err('Migration stopped: ' . $exception->getMessage());

            return 1;
        }
    }

    private function migrateCore(Database $database, Migrator $migrator): void
    {
        $this->out('Core database');
        $applied = $migrator->migrate(
            $database->coreMigrator(),
            new MigrationSet('core', $this->app->path('database/core/migrations')),
            fn (int $version, string $file) => $this->out(sprintf('  applied %s', $file)),
        );
        $this->out($applied === [] ? '  nothing to migrate' : sprintf('  %d migration(s) applied', count($applied)));
    }

    private function migrateOrganization(Database $database, Migrator $migrator, OrganizationRepository $repository, Organization $organization): void
    {
        $this->out(sprintf('Organization %s', $organization->slug));
        $pdo = $database->organizationMigrator($organization);

        $applied = $migrator->migrate(
            $pdo,
            new MigrationSet('org', $this->app->path('database/org/migrations')),
            fn (int $version, string $file) => $this->out(sprintf('  applied %s', $file)),
        );
        $this->out($applied === [] ? '  nothing to migrate' : sprintf('  %d migration(s) applied', count($applied)));

        // Keep the registry in step so the Super Admin app can see which version each organization is on.
        $repository->setMigrationVersion($organization->id, $migrator->currentVersion($pdo, 'org'));
    }

    private function migrateEverything(Database $database, Migrator $migrator): int
    {
        // Core first. If it fails, nothing else is attempted.
        $this->migrateCore($database, $migrator);

        $repository = new OrganizationRepository($database->coreMigrator());
        $failures = [];

        foreach ($repository->all() as $organization) {
            try {
                $this->migrateOrganization($database, $migrator, $repository, $organization);
            } catch (\Throwable $exception) {
                $failures[$organization->slug] = $exception->getMessage();
                $this->err(sprintf('  FAILED: %s', $exception->getMessage()));
            }
        }

        if ($failures !== []) {
            $this->err(sprintf('Migration finished with errors in: %s', implode(', ', array_keys($failures))));

            return 1;
        }

        return 0;
    }

    private function usage(): void
    {
        $this->out('Usage: php bin/migrate.php --core | --org=<slug> | --all');
        $this->out('  --core        migrate the core database');
        $this->out('  --org=<slug>  migrate one registered organization');
        $this->out('  --all         migrate the core database, then every active or suspended organization');
    }
}
