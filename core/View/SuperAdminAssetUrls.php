<?php

declare(strict_types=1);

namespace Gfh\Core\View;

/**
 * Builds public asset URLs for the Super Admin console (group-level, no organization branding or overrides).
 */
final class SuperAdminAssetUrls
{
    public function __construct(private readonly string $basePath)
    {
    }

    /** A Super Admin asset published from templates/superadmin/assets/. */
    public function url(string $path): string
    {
        $this->assertPath($path);

        return $this->basePath . '/assets/superadmin/' . $path;
    }

    /** A shared Admin asset reused by Super Admin (for example tokens.css and app.css). */
    public function admin(string $path): string
    {
        $this->assertPath($path);

        return $this->basePath . '/assets/admin/' . $path;
    }

    /** A shared foundation asset from public/assets/. */
    public function shared(string $path): string
    {
        $this->assertPath($path);

        return $this->basePath . '/assets/' . $path;
    }

    private function assertPath(string $path): void
    {
        if (preg_match('#^' . AssetUrls::SEGMENT . '(/' . AssetUrls::SEGMENT . ')*$#D', $path) !== 1) {
            throw new \InvalidArgumentException('That is not a valid asset path.');
        }
    }
}
