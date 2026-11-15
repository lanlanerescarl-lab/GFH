<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Tests\Support\TemplateTree;
use PHPUnit\Framework\TestCase;

/**
 * "No request goes to an external host." Scans every text file under templates/ and public/assets/ for an absolute
 * http(s) address or a protocol-relative one (//host) in a place that loads something. The site's own pages and
 * assets are always linked with relative paths, so any host at all is a failure.
 *
 * Two things are not requests and are allowed: XML namespace names (xmlns="http://www.w3.org/2000/svg") and
 * block comments in CSS and JavaScript, where vendored libraries keep their licence banner.
 */
final class NoExternalHostsTest extends TestCase
{
    private const TEXT_EXTENSIONS = ['php', 'html', 'css', 'js', 'mjs', 'svg', 'json', 'map'];

    /** @return list<string> one line per finding: "file:line: match" */
    public static function scan(string $root, array $folders): array
    {
        $findings = [];
        foreach ($folders as $folder) {
            $base = $root . '/' . $folder;
            if (!is_dir($base)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                $extension = strtolower($file->getExtension());
                if (!$file->isFile() || !in_array($extension, self::TEXT_EXTENSIONS, true)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                foreach (self::findHosts((string) file_get_contents($file->getPathname()), $extension) as [$line, $match]) {
                    $findings[] = $relative . ':' . $line . ': ' . $match;
                }
            }
        }

        return $findings;
    }

    /** @return list<array{int, string}> */
    private static function findHosts(string $text, string $extension): array
    {
        if (in_array($extension, ['css', 'js', 'mjs'], true)) {
            // Keep line numbers: replace each comment with the same number of newlines.
            $text = (string) preg_replace_callback('#/\*.*?\*/#s', static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $text);
        }

        $patterns = [
            // Absolute addresses anywhere (an XML namespace name is skipped below).
            '#\bhttps?://[^\s"\'()<>]+#i',
            // Protocol-relative addresses where something is loaded or linked.
            '#\b(?:src|href|action|poster|data)\s*=\s*["\']\s*//[^\s"\']+#i',
            '#url\(\s*["\']?\s*//[^\s"\')]+#i',
            '#@import\s+(?:url\()?\s*["\']?\s*(?:https?:)?//[^\s"\')]+#i',
            '#\b(?:import|from)\s*\(?\s*["\'](?:https?:)?//[^"\']+#i',
            '#\bfetch\(\s*["\'](?:https?:)?//[^"\']+#i',
        ];

        $found = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$match, $offset]) {
                if (preg_match('#xmlns(?::[A-Za-z]+)?\s*=\s*["\']$#', substr($text, max(0, $offset - 20), min(20, $offset))) === 1) {
                    continue;
                }
                $found[$offset] = [substr_count($text, "\n", 0, $offset) + 1, $match];
            }
        }
        ksort($found);

        return array_values($found);
    }

    public function testTemplatesAndPublicAssetsNameNoExternalHost(): void
    {
        $findings = self::scan(dirname(__DIR__, 2), ['templates', 'public/assets']);

        $this->assertSame([], $findings, "External hosts found (vendor the file under public/assets/ instead):\n" . implode("\n", $findings));
    }

    public function testTheScannerCatchesEveryKindOfExternalReference(): void
    {
        $tree = new TemplateTree();
        try {
            $tree->put('templates/admin/a.php', '<link rel="stylesheet" href="https://fonts.example.com/css">');
            $tree->put('templates/admin/b.php', '<script src="//cdn.example.com/lib.js"></script>');
            $tree->put('templates/admin/assets/css/c.css', ".x { background: url(//img.example.com/a.png); }\n@import url(\"https://example.com/x.css\");");
            $tree->put('templates/admin/assets/js/d.js', "import x from 'https://esm.example.com/x.js';\nfetch('//api.example.com/data');");
            $tree->put('public/assets/e.svg', '<svg xmlns="http://www.w3.org/2000/svg"><image href="http://img.example.com/a.png"/></svg>');

            $findings = self::scan($tree->root, ['templates', 'public/assets']);
            $text = implode("\n", $findings);

            foreach (['fonts.example.com', 'cdn.example.com', 'img.example.com/a.png', 'example.com/x.css', 'esm.example.com', 'api.example.com', 'http://img.example.com'] as $needle) {
                $this->assertStringContainsString($needle, $text, $needle);
            }
            $this->assertStringNotContainsString('www.w3.org', $text, 'an XML namespace is not a request');
            $this->assertStringContainsString('templates/admin/assets/css/c.css:2:', $text, 'line numbers are reported');
        } finally {
            $tree->remove();
        }
    }

    public function testLicenceBannersAndRelativePathsAreAllowed(): void
    {
        $tree = new TemplateTree();
        try {
            $tree->put('public/assets/vendor/lib.min.js', "/*!\n * Lib v1 https://lib.example.org\n * MIT\n */\nexport const a = '/assets/x.js';");
            $tree->put('templates/admin/view.php', '<a href="/demo/admin/products">Products</a><img src="/media/demo/1" alt="">');
            $tree->put('templates/admin/assets/css/f.css', "/* see https://example.org/spec */\n.x { background: url(\"../fonts/a.woff2\"); }");

            $this->assertSame([], self::scan($tree->root, ['templates', 'public/assets']));
        } finally {
            $tree->remove();
        }
    }
}
