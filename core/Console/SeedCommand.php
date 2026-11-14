<?php

declare(strict_types=1);

namespace Gfh\Core\Console;

use Gfh\Core\Database\Database;
use Gfh\Core\Database\SeedRunner;
use Gfh\Core\Repository\OrganizationRepository;

/**
 * php bin/seed.php --org=<slug>          loads orgs/<slug>/database/seeds/
 * php bin/seed.php --demo [--org=<slug>] loads database/seeds/demo/ into the "demo" organization (or the one named)
 */
final class SeedCommand extends Command
{
    public function run(array $argv): int
    {
        try {
            $options = Arguments::parse($argv, ['org', 'demo', 'help']);
        } catch (\InvalidArgumentException $exception) {
            $this->err($exception->getMessage());
            $this->usage();

            return 2;
        }

        if (isset($options['help']) || (!isset($options['org']) && !isset($options['demo'])) || (isset($options['org']) && !is_string($options['org']))) {
            $this->usage();

            return isset($options['help']) ? 0 : 2;
        }

        $slug = isset($options['org']) ? (string) $options['org'] : 'demo';

        // The slug becomes part of a folder path, so it must be a plain slug.
        if (preg_match('/^[a-z][a-z0-9-]{1,30}[a-z0-9]$/', $slug) !== 1) {
            $this->err('The organization slug is not valid.');

            return 2;
        }

        $directory = isset($options['demo'])
            ? $this->app->path('database/seeds/demo')
            : $this->app->path('orgs/' . $slug . '/database/seeds');

        try {
            $database = new Database($this->app->config());
            $core = $database->coreMigrator();
            $organization = (new OrganizationRepository($core))->findBySlug($slug);

            if ($organization === null) {
                $this->err(sprintf('No organization with the slug "%s" is registered.', $slug));

                return 1;
            }

            $this->out(sprintf('Seeding %s from %s', $slug, str_replace($this->app->path() . '/', '', $directory)));
            $count = (new SeedRunner())->run(
                $directory,
                $database->organizationMigrator($organization),
                $core,
                $this->app,
                fn (string $file) => $this->out('  ' . $file),
            );
        } catch (\Throwable $exception) {
            $this->err('Seeding stopped: ' . $exception->getMessage());

            return 1;
        }

        $this->out($count === 0 ? '  no seed files found' : sprintf('  %d seed file(s) run', $count));

        return 0;
    }

    private function usage(): void
    {
        $this->out('Usage: php bin/seed.php --org=<slug> | --demo [--org=<slug>]');
        $this->out('  --org=<slug>  run orgs/<slug>/database/seeds/ against that organization');
        $this->out('  --demo        run database/seeds/demo/ against the "demo" organization (or --org)');
    }
}
