<?php

declare(strict_types=1);

namespace Gfh\Core;

/**
 * Minimal PSR-4 autoloader so the project runs without Composer.
 * Extensions register their own namespace here when they are loaded.
 */
final class Autoloader
{
    /** @var array<string, string> namespace prefix (with trailing backslash) => directory (with trailing slash) */
    private array $prefixes = [];

    public function addNamespace(string $prefix, string $directory): void
    {
        $this->prefixes[trim($prefix, '\\') . '\\'] = rtrim($directory, '/\\') . '/';
    }

    public function register(): void
    {
        spl_autoload_register($this->load(...));
    }

    private function load(string $class): void
    {
        foreach ($this->prefixes as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = $directory . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
}
