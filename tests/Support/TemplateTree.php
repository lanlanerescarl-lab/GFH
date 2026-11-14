<?php

declare(strict_types=1);

namespace Gfh\Tests\Support;

/** A throwaway project folder in the system temp directory, for tests of the template and extension loaders. */
final class TemplateTree
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/gfh-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    /** Writes a file (creating folders) relative to the root. */
    public function put(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    public function remove(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
