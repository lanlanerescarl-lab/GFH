<?php

declare(strict_types=1);

namespace Gfh\Core\Console;

use Gfh\Core\View\AssetPublisher;

/**
 * php bin/publish-assets.php [--dry-run]
 * Copies the apps' CSS, JavaScript, fonts and images from templates/ and orgs/ into public/assets/.
 */
final class PublishAssetsCommand extends Command
{
    public function run(array $argv): int
    {
        try {
            $options = Arguments::parse($argv, ['dry-run', 'help']);
        } catch (\InvalidArgumentException $exception) {
            $this->err($exception->getMessage());
            $this->usage();

            return 2;
        }

        if (isset($options['help'])) {
            $this->usage();

            return 0;
        }

        $dryRun = isset($options['dry-run']);

        try {
            $result = (new AssetPublisher($this->app->path()))->publish($dryRun);
        } catch (\Throwable $exception) {
            $this->err('Publishing failed: ' . $exception->getMessage());

            return 1;
        }

        $this->out(sprintf('%s %d file(s) into public/assets/.', $dryRun ? 'Would publish' : 'Published', $result['copied']));
        foreach ($result['skipped'] as $line) {
            $this->out('  skipped: ' . $line);
        }

        return 0;
    }

    private function usage(): void
    {
        $this->out('Usage: php bin/publish-assets.php [--dry-run]');
        $this->out('  --dry-run   list what would be published without changing anything');
    }
}
