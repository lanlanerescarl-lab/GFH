<?php

declare(strict_types=1);

namespace Gfh\Core\View;

/**
 * Builds the POS service worker for one organization: the cache names, the list of files to keep, and the worker
 * source (templates/pos/service-worker.js with its __CONFIG__ placeholder filled in).
 *
 * The list is made of the real asset addresses this organization's POS loads (the same AssetUrls the layout uses, so an
 * organization's own published file wins over the shared one): the stylesheets, every published POS script, the shared
 * shell script, the fonts that fonts.css names, the branding stylesheet, and the shell page itself. Every entry is a
 * path on this same origin; nothing points to another host and nothing is under /api/.
 *
 * The cache name carries a hash of those files' contents, so a new deployment (any changed file) gives a new name, the
 * browser installs the new worker, and the old caches are deleted when it takes over.
 *
 * Nothing about a person goes in: no CSRF token, no name, no session value. The worker needs none.
 */
final class PosServiceWorker
{
    /** The most product pictures kept; the oldest are dropped first. */
    public const MAX_THUMBNAILS = 400;

    private ?array $urls = null;

    public function __construct(
        private readonly string $rootPath,
        private readonly string $basePath,
        private readonly string $organizationSlug,
    ) {
        TemplateLocator::assertSlug($organizationSlug);
    }

    /** The scope the worker controls and the Service-Worker-Allowed header names: the POS page itself, no trailing slash. */
    public function scope(): string
    {
        return $this->posPath();
    }

    /**
     * Every address the worker keeps at install, in a stable order: the shell page first.
     *
     * @return list<string>
     */
    public function precacheUrls(): array
    {
        if ($this->urls !== null) {
            return $this->urls;
        }

        $assets = new AssetUrls($this->publicPath(), $this->basePath, $this->organizationSlug, 'pos');
        $urls = [$this->posPath()];

        foreach (['css/tokens.css', 'css/fonts.css', 'css/base.css'] as $shared) {
            $urls[] = $assets->shared($shared);
        }
        $urls[] = $assets->url('css/tokens.css');
        $urls[] = $assets->url('css/app.css');
        $urls[] = $this->brandingPath();
        $urls[] = $assets->shared('js/shell.js');
        foreach ($this->scriptNames() as $name) {
            $urls[] = $assets->url('js/' . $name);
        }
        foreach ($this->fontFiles() as $font) {
            $urls[] = $this->basePath . '/assets/fonts/' . $font;
        }

        return $this->urls = array_values(array_unique($urls));
    }

    /** The name of this version's cache: it changes whenever any kept file, the list or the worker itself changes. */
    public function cacheName(): string
    {
        $parts = [];
        foreach ($this->precacheUrls() as $url) {
            $file = $this->fileFor($url);
            $parts[] = $url . ':' . ($file !== null ? (string) sha1_file($file) : '-');
        }
        $parts[] = (string) sha1_file($this->templatePath());

        return $this->cachePrefix() . 'v-' . substr(sha1(implode("\n", $parts)), 0, 12);
    }

    /** The complete worker source (plain ASCII JavaScript). */
    public function source(): string
    {
        $config = [
            'cacheName' => $this->cacheName(),
            'cachePrefix' => $this->cachePrefix(),
            'mediaCacheName' => $this->cachePrefix() . 'media',
            'maxThumbnails' => self::MAX_THUMBNAILS,
            'shellUrl' => $this->posPath(),
            'loginPath' => $this->posPath() . '/login',
            'workerPath' => $this->posPath() . '/sw.js',
            'apiPrefix' => $this->basePath . '/' . $this->organizationSlug . '/api/',
            'mediaPrefix' => $this->basePath . '/media/' . $this->organizationSlug . '/',
            'brandingUrl' => $this->brandingPath(),
            'assetsPrefix' => $this->basePath . '/assets/',
            'precache' => $this->precacheUrls(),
        ];

        // JSON_HEX_* keep "</script>"-like text and quotes harmless; the default escaping keeps the output ASCII.
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $template = file_get_contents($this->templatePath());
        if ($template === false || !str_contains($template, '__CONFIG__')) {
            throw new \RuntimeException('The POS service worker template is missing or has no __CONFIG__ placeholder.');
        }

        return str_replace('__CONFIG__', $json, $template);
    }

    private function posPath(): string
    {
        return $this->basePath . '/' . $this->organizationSlug . '/pos';
    }

    private function brandingPath(): string
    {
        return $this->basePath . '/' . $this->organizationSlug . '/branding.css';
    }

    private function cachePrefix(): string
    {
        return 'gfh-pos-' . $this->organizationSlug . '-';
    }

    private function publicPath(): string
    {
        return $this->rootPath . '/public';
    }

    private function templatePath(): string
    {
        return $this->rootPath . '/templates/pos/service-worker.js';
    }

    /**
     * The published POS scripts, relative to js/: the shared ones and the organization's own (which win by name).
     *
     * @return list<string>
     */
    private function scriptNames(): array
    {
        $names = [];
        foreach ([
            $this->publicPath() . '/assets/pos/js',
            $this->publicPath() . '/assets/orgs/' . $this->organizationSlug . '/pos/js',
        ] as $folder) {
            if (!is_dir($folder)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['js', 'mjs'], true)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($folder) + 1));
                if (preg_match('#^' . AssetUrls::SEGMENT . '(/' . AssetUrls::SEGMENT . ')*$#D', $relative) === 1) {
                    $names[$relative] = $relative;
                }
            }
        }
        ksort($names);

        return array_values($names);
    }

    /**
     * The font files fonts.css refers to (url("../fonts/name.woff2")), by file name.
     *
     * @return list<string>
     */
    private function fontFiles(): array
    {
        $css = @file_get_contents($this->publicPath() . '/assets/css/fonts.css');
        if ($css === false) {
            return [];
        }
        preg_match_all('#url\(\s*["\']?\.\./fonts/(' . AssetUrls::SEGMENT . '\.woff2?)["\']?\s*\)#', $css, $found);

        return array_values(array_unique($found[1]));
    }

    /** The file on disk behind a kept address, or null for a page that has none (the shell, the branding stylesheet). */
    private function fileFor(string $url): ?string
    {
        $prefix = $this->basePath . '/';
        if (!str_starts_with($url, $prefix . 'assets/')) {
            return null;
        }
        $file = $this->publicPath() . '/' . substr($url, strlen($prefix));

        return is_file($file) ? $file : null;
    }
}
