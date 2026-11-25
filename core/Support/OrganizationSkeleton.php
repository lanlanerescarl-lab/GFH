<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

use Gfh\Core\View\TemplateLocator;

/**
 * Copies orgs/_skeleton to orgs/{slug} for a new organization.
 *
 * It never overwrites: the target folder must not exist (not as a folder, a file or a link), and each file is
 * copied only when its destination is absent. A symbolic link anywhere in the skeleton stops the copy, so nothing
 * outside the skeleton can be pulled in. The slug is checked with TemplateLocator::assertSlug before any path is built.
 */
final class OrganizationSkeleton
{
    public function __construct(private readonly string $rootPath)
    {
    }

    public function sourcePath(): string
    {
        return $this->rootPath . '/orgs/_skeleton';
    }

    public function targetPath(string $slug): string
    {
        TemplateLocator::assertSlug($slug);

        return $this->rootPath . '/orgs/' . $slug;
    }

    /** @throws \InvalidArgumentException when the slug is bad, the skeleton is missing or the target already exists */
    public function assertCanCopy(string $slug): void
    {
        $target = $this->targetPath($slug);
        if (!is_dir($this->sourcePath()) || is_link($this->sourcePath())) {
            throw new \InvalidArgumentException('The skeleton folder orgs/_skeleton was not found.');
        }
        if (file_exists($target) || is_link($target)) {
            throw new \InvalidArgumentException(sprintf('The folder orgs/%s already exists. Nothing was copied or overwritten; remove or rename it yourself if you really want a fresh copy.', $slug));
        }
    }

    /**
     * @return list<string> the files copied, relative to the new folder
     * @throws \InvalidArgumentException from assertCanCopy()
     * @throws \RuntimeException when a file cannot be copied; a partly copied folder is left for the person to look at
     */
    public function copy(string $slug): array
    {
        $this->assertCanCopy($slug);
        $source = $this->sourcePath();
        $target = $this->targetPath($slug);

        // Not recursive on purpose: mkdir fails if the folder appeared since the check above, so nothing is ever merged into it.
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('The orgs folder could not be created.');
        }
        if (!mkdir($target, 0775)) {
            throw new \RuntimeException(sprintf('The folder orgs/%s could not be created.', $slug));
        }

        $copied = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            if ($item->isLink()) {
                throw new \RuntimeException(sprintf('The skeleton holds a symbolic link (%s); it was not followed.', str_replace('\\', '/', $relative)));
            }
            $destination = $target . '/' . $relative;
            if ($item->isDir()) {
                if (!mkdir($destination, 0775) && !is_dir($destination)) {
                    throw new \RuntimeException(sprintf('The folder %s could not be created.', str_replace('\\', '/', $relative)));
                }
                continue;
            }
            if (file_exists($destination)) {
                throw new \RuntimeException(sprintf('%s already exists in the new folder; it was not overwritten.', str_replace('\\', '/', $relative)));
            }
            if (!copy($item->getPathname(), $destination)) {
                throw new \RuntimeException(sprintf('%s could not be copied.', str_replace('\\', '/', $relative)));
            }
            $copied[] = str_replace('\\', '/', $relative);
        }
        sort($copied);

        return $copied;
    }
}
