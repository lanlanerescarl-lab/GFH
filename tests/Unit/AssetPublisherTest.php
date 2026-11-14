<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\View\AssetPublisher;
use Gfh\Core\View\AssetUrls;
use Gfh\Tests\Support\TemplateTree;
use PHPUnit\Framework\TestCase;

final class AssetPublisherTest extends TestCase
{
    private TemplateTree $tree;

    protected function setUp(): void
    {
        $this->tree = new TemplateTree();
        $this->tree->put('templates/admin/assets/css/app.css', 'admin css');
        $this->tree->put('templates/admin/assets/.gitkeep', '');
        $this->tree->put('templates/shop/assets/js/cart.js', 'shop js');
        $this->tree->put('templates/pos/assets/css/Big.CSS', 'upper-case extension is fine');
        $this->tree->put('templates/pos/assets/evil.php', '<?php echo 1;');
        $this->tree->put('templates/pos/assets/notes.txt', 'text');
        $this->tree->put('templates/pos/assets/my file.css', 'space in name');
        $this->tree->put('orgs/acme/admin/assets/css/brand.css', 'acme css');
        $this->tree->put('orgs/acme/admin/layout.php', 'not an asset, never published');
        $this->tree->put('orgs/_skeleton/admin/assets/css/skeleton.css', 'skeleton');
        $this->tree->put('orgs/Bad_Org/admin/assets/css/x.css', 'bad folder name');
        $this->tree->put('public/assets/css/shared.css', 'shared styles stay');
        $this->tree->put('public/assets/admin/css/stale.css', 'left over from an earlier run');
        $this->tree->put('public/assets/orgs/gone/admin/css/old.css', 'organization that no longer exists');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private function exists(string $relative): bool
    {
        return is_file($this->tree->root . '/' . $relative);
    }

    public function testFilesAreCopiedToTheRightPlaces(): void
    {
        $result = (new AssetPublisher($this->tree->root))->publish();

        $this->assertSame(4, $result['copied']);
        $this->assertTrue($this->exists('public/assets/admin/css/app.css'));
        $this->assertTrue($this->exists('public/assets/shop/js/cart.js'));
        $this->assertTrue($this->exists('public/assets/pos/css/Big.CSS'));
        $this->assertTrue($this->exists('public/assets/orgs/acme/admin/css/brand.css'));
        $this->assertSame('admin css', file_get_contents($this->tree->root . '/public/assets/admin/css/app.css'));
    }

    public function testNothingExecutableHiddenOrOddlyNamedIsPublished(): void
    {
        $result = (new AssetPublisher($this->tree->root))->publish();

        $this->assertFalse($this->exists('public/assets/pos/evil.php'));
        $this->assertFalse($this->exists('public/assets/pos/notes.txt'));
        $this->assertFalse($this->exists('public/assets/pos/my file.css'));
        $this->assertFalse($this->exists('public/assets/admin/.gitkeep'));
        $this->assertFalse($this->exists('public/assets/orgs/acme/admin/layout.php'));
        $this->assertFalse(is_dir($this->tree->root . '/public/assets/orgs/Bad_Org'));
        $this->assertFalse(is_dir($this->tree->root . '/public/assets/orgs/_skeleton'));

        $report = implode("\n", $result['skipped']);
        $this->assertStringContainsString('evil.php (file type not allowed)', $report);
        $this->assertStringContainsString('notes.txt (file type not allowed)', $report);
        $this->assertStringContainsString('my file.css (unsafe file name)', $report);
        $this->assertStringContainsString('orgs/Bad_Org (not a valid organization folder name)', $report);
        $this->assertStringNotContainsString('.gitkeep', $report);
        $this->assertStringNotContainsString('_skeleton', $report);
    }

    public function testGeneratedFoldersAreEmptiedFirstButSharedAssetsAreNotTouched(): void
    {
        (new AssetPublisher($this->tree->root))->publish();

        $this->assertFalse($this->exists('public/assets/admin/css/stale.css'));
        $this->assertFalse($this->exists('public/assets/orgs/gone/admin/css/old.css'));
        $this->assertTrue($this->exists('public/assets/css/shared.css'));
    }

    public function testADryRunChangesNothing(): void
    {
        $result = (new AssetPublisher($this->tree->root))->publish(true);

        $this->assertSame(4, $result['copied']);
        $this->assertFalse($this->exists('public/assets/shop/js/cart.js'));
        $this->assertTrue($this->exists('public/assets/admin/css/stale.css'), 'nothing is deleted in a dry run');
    }

    public function testRunningTwiceGivesTheSameResult(): void
    {
        $publisher = new AssetPublisher($this->tree->root);
        $first = $publisher->publish();
        $second = $publisher->publish();

        $this->assertSame($first, $second);
    }

    public function testAssetUrlsPreferTheOrganizationsPublishedFile(): void
    {
        (new AssetPublisher($this->tree->root))->publish();
        $public = $this->tree->root . '/public';

        $acme = new AssetUrls($public, '/gfh', 'acme', 'admin');
        $this->assertSame('/gfh/assets/orgs/acme/admin/css/brand.css', $acme->url('css/brand.css'));
        $this->assertSame('/gfh/assets/admin/css/app.css', $acme->url('css/app.css'), 'no organization file: the shared one');
        $this->assertSame('/gfh/assets/css/tokens.css', $acme->shared('css/tokens.css'));
        $this->assertSame('/gfh/acme/branding.css', $acme->branding());

        $zeta = new AssetUrls($public, '', 'zeta-shop', 'admin');
        $this->assertSame('/assets/admin/css/app.css', $zeta->url('css/app.css'));
    }

    public function testAssetUrlsRefuseBadPathsSlugsAndApps(): void
    {
        $urls = new AssetUrls($this->tree->root . '/public', '', 'acme', 'shop');

        foreach (['../x.css', 'css/../../x.css', '/etc/passwd', '.hidden', 'a//b.css', '', 'css/a b.css', "css/a.css\n"] as $path) {
            try {
                $urls->url($path);
                $this->fail('Expected a refusal for ' . json_encode($path));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new AssetUrls($this->tree->root . '/public', '', '../acme', 'shop');
    }

    public function testAssetUrlsRefuseAnUnknownApp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AssetUrls($this->tree->root . '/public', '', 'acme', 'api');
    }
}
