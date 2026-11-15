<?php

declare(strict_types=1);

namespace Gfh\Core\Console;

use Gfh\Core\App;
use Gfh\Core\Database\ConnectionString;
use Gfh\Core\Database\Database;
use Gfh\Core\Repository\OrganizationRepository;
use Gfh\Core\Security\SecretBox;
use Gfh\Core\Service\OrganizationRegistry;

/**
 * php bin/make-org.php --slug=acme --name="Acme Trading" [--short-name=Acme]
 *                      [--schema=public] [--features=a,b] [--branding='{"..."}'] [--no-test]
 * php bin/make-org.php --slug=acme --update-connection [--schema=public] [--no-test]
 *
 * Registers an organization in the core registry with an encrypted connection string, or replaces the stored
 * encrypted connection for an existing organization. The connection is read only from GFH_ORG_CONNECTION
 * or a hidden interactive prompt; a command-line flag is deliberately not supported because shell history stores it.
 */
final class MakeOrgCommand extends Command
{
    /**
     * @param (\Closure(): string)|null           $prompt optional hidden-input callback used in tests
     * @param (\Closure(bool, string): void)|null $writer optional output sink ($isError, $message) used in tests
     */
    public function __construct(
        App $app,
        private readonly ?\PDO $coreConnection = null,
        private readonly ?\Closure $prompt = null,
        private readonly ?\Closure $writer = null,
    ) {
        parent::__construct($app);
    }

    public function run(array $argv): int
    {
        try {
            $options = Arguments::parse($argv, ['slug', 'name', 'short-name', 'schema', 'features', 'branding', 'update-connection', 'no-test', 'help']);
        } catch (\InvalidArgumentException $exception) {
            $this->err($exception->getMessage());
            $this->usage();

            return 2;
        }

        foreach (['slug', 'name', 'short-name', 'schema', 'features', 'branding'] as $name) {
            if (isset($options[$name]) && !is_string($options[$name])) {
                $this->err(sprintf('Option --%s needs a value: --%s=...', $name, $name));

                return 2;
            }
        }

        foreach (['update-connection', 'no-test'] as $flag) {
            if (isset($options[$flag]) && $options[$flag] !== true) {
                $this->err(sprintf('Option --%s does not take a value.', $flag));

                return 2;
            }
        }

        if (isset($options['help'])) {
            $this->usage();

            return 0;
        }

        if (isset($options['update-connection'])) {
            return $this->runUpdateConnection($options);
        }

        if (!isset($options['slug'], $options['name'])) {
            $this->usage();

            return 2;
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

            $connection = ConnectionString::parse($this->connectionUrl());
            $schema = (string) ($options['schema'] ?? 'public');
            $database = new Database($this->app->config());

            if (!isset($options['no-test'])) {
                $this->out(sprintf('Testing the connection to %s ...', $connection->describe()));
                $database->test($connection, $schema);
                $this->out('  connected');
            }

            $registry = $this->registry($database);

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
    private function runUpdateConnection(array $options): int
    {
        if (!isset($options['slug'])) {
            $this->usage();

            return 2;
        }

        foreach (['name', 'short-name', 'features', 'branding'] as $disallowed) {
            if (isset($options[$disallowed])) {
                $this->err(sprintf('Option --%s cannot be used with --update-connection; only the stored connection (and optional --schema) is updated.', $disallowed));

                return 2;
            }
        }

        try {
            $slug = trim((string) $options['slug']);
            $explicitSchema = isset($options['schema']) ? (string) $options['schema'] : null;
            $database = new Database($this->app->config());
            $repository = $this->repository($database);
            $existing = $repository->findBySlug($slug);
            if ($existing === null) {
                throw new \InvalidArgumentException(sprintf('No organization with the slug "%s" is registered.', $slug));
            }

            $connection = ConnectionString::parse($this->connectionUrl());

            if (!isset($options['no-test'])) {
                $this->out(sprintf('Testing the connection to %s ...', $connection->describe()));
                $database->test($connection, $explicitSchema ?? $existing->dbSchema);
                $this->out('  connected');
            }

            $registry = $this->registry($database, $repository);
            $organization = $registry->updateConnection($slug, $connection, $explicitSchema);
        } catch (\Throwable $exception) {
            $this->err('Could not update the organization connection: ' . $exception->getMessage());

            return 1;
        }

        $this->out(sprintf('Updated stored connection for "%s" (slug %s).', $organization->name, $organization->slug));

        return 0;
    }

    private function registry(Database $database, ?OrganizationRepository $repository = null): OrganizationRegistry
    {
        return new OrganizationRegistry(
            $repository ?? $this->repository($database),
            SecretBox::fromConfig($this->app->config()),
        );
    }

    private function repository(Database $database): OrganizationRepository
    {
        return new OrganizationRepository($this->coreConnection ?? $database->coreMigrator());
    }

    private function connectionUrl(): string
    {
        $fromEnvironment = getenv('GFH_ORG_CONNECTION');
        if (is_string($fromEnvironment) && trim($fromEnvironment) !== '') {
            return trim($fromEnvironment);
        }

        if ($this->prompt !== null) {
            $line = ($this->prompt)();
            if (is_string($line) && trim($line) !== '') {
                return trim($line);
            }
        }

        if (defined('STDIN') && stream_isatty(STDIN)) {
            $line = $this->readHiddenConnectionUrl();
            if (trim($line) !== '') {
                return trim($line);
            }
        }

        throw new \InvalidArgumentException('No connection string given. Set GFH_ORG_CONNECTION or run interactively to enter it at the hidden prompt.');
    }

    private function readHiddenConnectionUrl(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $script = '$s = Read-Host -AsSecureString -Prompt "Connection string (input hidden)"; '
                . '$b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); '
                . 'try { [Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringAuto($b)) } '
                . 'finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }';

            $process = proc_open(
                ['powershell', '-NoProfile', '-Command', $script],
                [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR],
                $pipes,
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('Could not start a hidden terminal prompt; set GFH_ORG_CONNECTION instead.');
            }

            $line = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $exit = proc_close($process);
            if ($exit !== 0 || !is_string($line)) {
                throw new \RuntimeException('Could not read from the hidden terminal prompt; set GFH_ORG_CONNECTION instead.');
            }

            return $line;
        }

        fwrite(STDOUT, 'Connection string (input hidden): ');
        $disabled = [];
        $code = 0;
        exec('stty -echo 2>/dev/null', $disabled, $code);
        if ($code !== 0) {
            fwrite(STDOUT, PHP_EOL);
            throw new \RuntimeException('Could not hide terminal input; set GFH_ORG_CONNECTION instead.');
        }

        try {
            $line = fgets(STDIN);
        } finally {
            exec('stty echo 2>/dev/null');
            fwrite(STDOUT, PHP_EOL);
        }

        return is_string($line) ? $line : '';
    }

    protected function out(string $message): void
    {
        if ($this->writer !== null) {
            ($this->writer)(false, $message);

            return;
        }

        parent::out($message);
    }

    protected function err(string $message): void
    {
        if ($this->writer !== null) {
            ($this->writer)(true, $message);

            return;
        }

        parent::err($message);
    }

    private function usage(): void
    {
        $this->out('Usage:');
        $this->out('  php bin/make-org.php --slug=<slug> --name="<Full name>" [options]');
        $this->out('  php bin/make-org.php --slug=<slug> --update-connection [--schema=<name>] [--no-test]');
        $this->out('Options:');
        $this->out('  --short-name=<text>   short label (default: the name)');
        $this->out('  --schema=<name>       database schema (default: public)');
        $this->out('  --features=<a,b>      feature keys to switch on, comma separated');
        $this->out('  --branding=<json>     branding values as a JSON object');
        $this->out('  --update-connection   replace the stored connection for an existing organization');
        $this->out('  --no-test             save without testing the connection first');
        $this->out('Connection input (never passed on the command line):');
        $this->out('  Set GFH_ORG_CONNECTION in the environment, or enter it at the hidden prompt.');
    }
}
