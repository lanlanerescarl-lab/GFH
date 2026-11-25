<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Support\OrganizationSkeleton;
use Gfh\Core\View\AppView;
use Gfh\Core\View\BrandingCss;
use Gfh\Core\View\TemplateLocator;
use Gfh\Tests\Support\TemplateTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Batch 8.2b: what orgs/_skeleton holds, and the class that copies it. */
final class OrgSkeletonTest extends TestCase
{
    private const FOLDERS = ['', 'admin', 'shop', 'pos', 'extensions', 'database', 'database/seeds'];

    private TemplateTree $tree;
    private string $skeleton;

    protected function setUp(): void
    {
        $this->tree = new TemplateTree();
        $this->skeleton = dirname(__DIR__, 2) . '/orgs/_skeleton';
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /** @return list<string> every file of the real skeleton, relative to it, sorted */
    private function skeletonFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->skeleton, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($this->skeleton) + 1));
            }
        }
        sort($files);

        return $files;
    }

    private function installSkeleton(): void
    {
        foreach ($this->skeletonFiles() as $file) {
            $this->tree->put('orgs/_skeleton/' . $file, (string) file_get_contents($this->skeleton . '/' . $file));
        }
    }

    // ------------------------------------------------------------------ the content

    public function testEveryFolderHasAReadmeThatSaysWhatBelongsThere(): void
    {
        foreach (self::FOLDERS as $folder) {
            $readme = $this->skeleton . ($folder === '' ? '' : '/' . $folder) . '/README.md';
            $this->assertFileExists($readme, 'README.md in "' . $folder . '"');
            $this->assertGreaterThan(80, strlen(trim((string) file_get_contents($readme))), 'README.md in "' . $folder . '" says something');
        }
    }

    public function testItHoldsNoOrganizationSpecificName(): void
    {
        $names = '/\b(demo|ace|bistro|acme|appliances?|furniture|decor|f&b)\b/i';
        foreach ($this->skeletonFiles() as $file) {
            $text = (string) file_get_contents($this->skeleton . '/' . $file);
            $this->assertSame(0, preg_match($names, $text, $found), $file . ' names an organization: ' . ($found[0] ?? ''));
            $this->assertSame(0, preg_match($names, $file), 'the file name ' . $file . ' names an organization');
        }
    }

    public function testItHoldsNoLiveOverride(): void
    {
        foreach ($this->skeletonFiles() as $file) {
            $this->assertDoesNotMatchRegularExpression('/\.(php|phtml|phar|inc)$/i', $file, $file . ' could be loaded as a template');
        }
        // In the template folders and in extensions/ and database/seeds/, nothing but the README (and the .gitkeep that keeps the folder).
        foreach (['admin', 'shop', 'pos', 'extensions', 'database/seeds'] as $folder) {
            $inside = array_values(array_filter($this->skeletonFiles(), static fn (string $f): bool => str_starts_with($f, $folder . '/')));
            $this->assertSame([], array_values(array_diff($inside, [$folder . '/README.md', $folder . '/.gitkeep'])), $folder . ' holds something that is not a README');
        }

        // The real lookup: with a copy of the skeleton installed as an organization, every template still resolves to templates/.
        $this->installSkeleton();
        (new OrganizationSkeleton($this->tree->root))->copy('copy-org');
        foreach (['admin' => 'orders', 'shop' => 'home', 'pos' => 'sell'] as $app => $name) {
            $this->tree->put('templates/' . $app . '/' . $name . '.php', 'core');
            $this->assertSame($this->tree->root . '/templates/' . $app . '/' . $name . '.php', (new TemplateLocator($this->tree->root))->resolve('copy-org', $app, $name));
        }
    }

    public function testTheBrandingExampleIsAValidBrandingObject(): void
    {
        $decoded = json_decode((string) file_get_contents($this->skeleton . '/branding.example.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $built = BrandingCss::build($decoded);
        $this->assertSame([], $built['ignored'], 'every key of the example is one BrandingCss knows and accepts');
        $this->assertStringContainsString('--color-primary', $built['css']);
    }

    // ------------------------------------------------------------------ the copy

    public function testCopyMakesTheFolderWithTheSameFilesAndSameContent(): void
    {
        $this->installSkeleton();
        $copied = (new OrganizationSkeleton($this->tree->root))->copy('my-shop');

        $this->assertSame($this->skeletonFiles(), $copied);
        foreach ($copied as $file) {
            $this->assertFileEquals($this->skeleton . '/' . $file, $this->tree->root . '/orgs/my-shop/' . $file);
        }
        $this->assertDirectoryExists($this->tree->root . '/orgs/my-shop/database/seeds');
    }

    public function testCopyNeverOverwritesAFolderOrAFile(): void
    {
        $this->installSkeleton();
        $this->tree->put('orgs/taken/shop/home.php', 'MINE');
        $this->tree->put('orgs/afile', 'MINE');
        $skeleton = new OrganizationSkeleton($this->tree->root);

        foreach (['taken', 'afile'] as $slug) {
            try {
                $skeleton->copy($slug);
                $this->fail('A taken name must be refused: ' . $slug);
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('already exists', $exception->getMessage());
            }
        }
        $this->assertSame('MINE', file_get_contents($this->tree->root . '/orgs/taken/shop/home.php'));
        $this->assertFileDoesNotExist($this->tree->root . '/orgs/taken/README.md', 'nothing was merged into the existing folder');
        $this->assertSame('MINE', file_get_contents($this->tree->root . '/orgs/afile'));

        $skeleton->copy('fresh-one');
        $this->tree->put('orgs/fresh-one/README.md', 'EDITED');
        try {
            $skeleton->copy('fresh-one');
            $this->fail('A second copy must be refused');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame('EDITED', file_get_contents($this->tree->root . '/orgs/fresh-one/README.md'));
    }

    /** @return array<string, array{string}> */
    public static function badSlugs(): array
    {
        return [
            'capital letters' => ['Bad-Shop'],
            'too short' => ['x'],
            'climbs out' => ['../evil'],
            'underscore' => ['bad_shop'],
            'line break' => ["ok-shop\n"],
            'the skeleton itself' => ['_skeleton'],
            'empty' => [''],
        ];
    }

    #[DataProvider('badSlugs')]
    public function testCopyRefusesABadSlugAndCreatesNothing(string $slug): void
    {
        $this->installSkeleton();
        try {
            (new OrganizationSkeleton($this->tree->root))->copy($slug);
            $this->fail('The slug must be refused');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('That is not a valid organization slug.', $exception->getMessage());
        }
        $this->assertSame(['_skeleton'], array_values(array_diff(scandir($this->tree->root . '/orgs') ?: [], ['.', '..'])));
    }

    public function testCopyNeedsTheSkeletonToExist(): void
    {
        $this->tree->put('orgs/other/README.md', 'x');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('orgs/_skeleton was not found');
        (new OrganizationSkeleton($this->tree->root))->copy('my-shop');
    }

    public function testCopyDoesNotFollowASymbolicLinkInTheSkeleton(): void
    {
        $this->installSkeleton();
        $outside = $this->tree->root . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/secret.txt', 'SECRET');
        $link = $this->tree->root . '/orgs/_skeleton/shop/linked';
        if (!@symlink($outside, $link)) {
            $this->markTestSkipped('Symbolic links are not available here.');
        }

        try {
            (new OrganizationSkeleton($this->tree->root))->copy('my-shop');
            $this->fail('A link in the skeleton must stop the copy');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('symbolic link', $exception->getMessage());
        } finally {
            unlink($link); // the tree cleaner does not remove a linked folder
        }
        $this->assertFileDoesNotExist($this->tree->root . '/orgs/my-shop/shop/linked/secret.txt');
    }

    // ------------------------------------------------------------------ the Shop home partial

    public function testTheDefaultHomeIntroPartialPrintsNothing(): void
    {
        $this->tree->put('templates/shop/partials/home-intro.php', (string) file_get_contents(dirname(__DIR__, 2) . '/templates/shop/partials/home-intro.php'));
        $view = new AppView(new TemplateLocator($this->tree->root), 'my-shop', 'shop');

        $this->assertSame('', trim($view->render('partials/home-intro', ['organizationName' => 'X', 'productsUrl' => '/x'])));
    }
}
