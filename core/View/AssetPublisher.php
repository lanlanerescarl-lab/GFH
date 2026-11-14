<?php

declare(strict_types=1);

namespace Gfh\Core\View;

/**
 * Publishes the assets of the three apps into public/assets/, because templates are not web-visible.
 *
 *   templates/{app}/assets/**            ->  public/assets/{app}/**
 *   orgs/{slug}/{app}/assets/**          ->  public/assets/orgs/{slug}/{app}/**
 *
 * The target folders are generated output: each run empties them first, so a file deleted from a template
 * disappears from public/ too. Shared files in public/assets/css, js and fonts are never touched.
 * Only static file types are copied, never PHP, and names are checked, so nothing executable or hidden
 * can end up in the web root. Files are copied (not linked) so it works the same on Windows.
 */
final class AssetPublisher
{
    public const EXTENSIONS = ['css', 'js', 'mjs', 'woff2', 'woff', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico'];

    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * @return array{copied: int, skipped: list<string>} the number of files published and what was left out and why
     */
    public function publish(bool $dryRun = false): array
    {
        $assets = $this->rootPath . '/public/assets';
        $copied = 0;
        $skipped = [];

        if (!$dryRun) {
            foreach ([...TemplateLocator::APPS, 'orgs'] as $folder) {
                $this->removeTree($assets . '/' . $folder);
            }
        }

        foreach (TemplateLocator::APPS as $app) {
            $copied += $this->copyTree(
                $this->rootPath . '/templates/' . $app . '/assets',
                $assets . '/' . $app,
                'templates/' . $app . '/assets',
                $skipped,
                $dryRun,
            );
        }

        $orgs = $this->rootPath . '/orgs';
        $names = is_dir($orgs) ? (scandir($orgs) ?: []) : [];
        sort($names);
        foreach ($names as $slug) {
            if ($slug[0] === '.' || $slug === '_skeleton') {
                continue;
            }
            if (!TemplateLocator::isValidSlug($slug) || !is_dir($orgs . '/' . $slug) || is_link($orgs . '/' . $slug)) {
                $skipped[] = 'orgs/' . $slug . ' (not a valid organization folder name)';
                continue;
            }
            foreach (TemplateLocator::APPS as $app) {
                $copied += $this->copyTree(
                    $orgs . '/' . $slug . '/' . $app . '/assets',
                    $assets . '/orgs/' . $slug . '/' . $app,
                    'orgs/' . $slug . '/' . $app . '/assets',
                    $skipped,
                    $dryRun,
                );
            }
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /** @param list<string> $skipped */
    private function copyTree(string $source, string $target, string $label, array &$skipped, bool $dryRun): int
    {
        if (!is_dir($source) || is_link($source)) {
            return 0;
        }

        $count = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));

            // Hidden placeholder files such as .gitkeep are ignored without a message.
            if (str_starts_with($file->getFilename(), '.')) {
                continue;
            }
            if ($file->isLink() || !$file->isFile()) {
                $skipped[] = $label . '/' . $relative . ' (not a regular file)';
                continue;
            }
            foreach (explode('/', $relative) as $segment) {
                if (preg_match('#^' . AssetUrls::SEGMENT . '$#D', $segment) !== 1) {
                    $skipped[] = $label . '/' . $relative . ' (unsafe file name)';
                    continue 2;
                }
            }
            if (!in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                $skipped[] = $label . '/' . $relative . ' (file type not allowed)';
                continue;
            }

            if (!$dryRun) {
                $destination = $target . '/' . $relative;
                if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0777, true) && !is_dir(dirname($destination))) {
                    throw new \RuntimeException('Could not create a folder under public/assets.');
                }
                if (!copy($file->getPathname(), $destination)) {
                    throw new \RuntimeException(sprintf('Could not copy %s/%s.', $label, $relative));
                }
            }
            $count++;
        }

        return $count;
    }

    /** Empties and removes one generated folder; refuses anything outside public/assets. */
    private function removeTree(string $directory): void
    {
        $safeRoot = str_replace('\\', '/', $this->rootPath . '/public/assets/');
        if (!str_starts_with(str_replace('\\', '/', $directory) . '/', $safeRoot)) {
            throw new \LogicException('Refusing to delete outside public/assets.');
        }
        if (is_link($directory)) {
            unlink($directory);

            return;
        }
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
