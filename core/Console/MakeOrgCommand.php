<?php

declare(strict_types=1);

namespace Gfh\Core\Console;

use Gfh\Core\Database\ConnectionString;
use Gfh\Core\Database\Database;
use Gfh\Core\Repository\OrganizationRepository;
use Gfh\Core\Security\SecretBox;
use Gfh\Core\Service\OrganizationRegistry;

/**
 * php bin/make-org.php --slug=fnb --name="Food & Beverages" [--short-name=F&B] [--connection=URL]
 *                      [--schema=public] [--features=a,b] [--branding='{"..."}'] [--no-test]
 * Registers an organization in the core registry with an encrypted connection string.
 * The connection can also come from the GFH_ORG_CONNECTION environment variable or be typed when asked,
 * which keeps the password out of your shell history.
 */
final class MakeOrgCommand extends Command
{
    public function run(array $argv): int
    {
        try {
            $options = Arguments::parse($argv, ['slug', 'name', 'short-name', 'connection', 'schema', 'features', 'branding', 'no-test', 'help']);
        } catch (\InvalidArgumentException $exception) {
            $this->err($exception->getMessage());
            $this->usage();

            return 2;
        }

        foreach (['slug', 'name', 'short-name', 'connection', 'schema', 'features', 'branding'] as $name) {
            if (isset($options[$name]) && !is_string($options[$name])) {
                $this->err(sprintf('Option --%s needs a value: --%s=...', $name, $name));

                return 2;
            }
        }

        if (isset($options['help']) || !isset($options['slug'], $options['name'])) {
            $this->usage();

            return isset($options['help']) ? 0 : 2;
        }

        try {
            $branding = [];
            if (isset($options['branding'])) {
                $decoded = json_decode((string) $options['branding'], true);
                if (!is_array($decoded)) {
                    throw new \InvalidArgumentException('--branding must be a JSON object, for example \'{"primary":"#3182BD"}\'.');
                }
                $branding = $decoded;
            }

            $features = isset($options['features'])
                ? array_values(array_filter(array_map('trim', explode(',', (string) $options['features'])), static fn (string $f): bool => $f !== ''))
                : [];

            $connection = ConnectionString::parse($this->connectionUrl($options));
            $schema = (string) ($options['schema'] ?? 'public');
            $database = new Database($this->app->config());

            if (!isset($options['no-test'])) {
                $this->out(sprintf('Testing the connection to %s ...', $connection->describe()));
                $database->test($connection, $schema);
                $this->out('  connected');
            }

            $registry = new OrganizationRegistry(
                new OrganizationRepository($database->coreMigrator()),
                SecretBox::fromConfig($this->app->config()),
            );

            $organization = $registry->register(
                (string) $options['slug'],
                (string) $options['name'],
                (string) ($options['short-name'] ?? $options['name']),
                $connection,
                $schema,
                $branding,
                $features,
            );
        } catch (\Throwable $exception) {
            $this->err('Could not register the organization: ' . $exception->getMessage());

            return 1;
        }

        $this->out(sprintf('Registered "%s" (slug %s).', $organization->name, $organization->slug));
        $this->out('Next steps:');
        $this->out(sprintf('  1. Set %s in .env to a migrator-role URL (or leave it unset to migrate with the stored connection).', Database::migratorSettingName($organization->slug)));
        $this->out(sprintf('  2. php bin/migrate.php --org=%s', $organization->slug));

        return 0;
    }

    /** @param array<string, string|true> $options */
    private function connectionUrl(array $options): string
    {
        if (isset($options['connection'])) {
            return (string) $options['connection'];
        }

        $fromEnvironment = getenv('GFH_ORG_CONNECTION');
        if (is_string($fromEnvironment) && trim($fromEnvironment) !== '') {
            return $fromEnvironment;
        }

        if (defined('STDIN') && stream_isatty(STDIN)) {
            fwrite(STDOUT, 'Connection string (postgresql://user:password@host:5432/database?sslmode=require): ');
            $line = fgets(STDIN);
            if (is_string($line) && trim($line) !== '') {
                return trim($line);
            }
        }

        throw new \InvalidArgumentException('No connection string given. Use --connection=..., GFH_ORG_CONNECTION, or type it when asked.');
    }

    private function usage(): void
    {
        $this->out('Usage: php bin/make-org.php --slug=<slug> --name="<Full name>" [options]');
        $this->out('  --short-name=<text>   short label (default: the name)');
        $this->out('  --connection=<url>    postgresql://user:password@host:5432/database?sslmode=require');
        $this->out('                        (or set GFH_ORG_CONNECTION, or type it when asked)');
        $this->out('  --schema=<name>       database schema (default: public)');
        $this->out('  --features=<a,b>      feature keys to switch on, comma separated');
        $this->out('  --branding=<json>     branding values as a JSON object');
        $this->out('  --no-test             register without testing the connection first');
    }
}
