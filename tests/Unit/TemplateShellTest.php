<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\View\AppView;
use Gfh\Core\View\AssetUrls;
use Gfh\Core\View\TemplateLocator;
use Gfh\Tests\Support\TemplateTree;
use PHPUnit\Framework\TestCase;

/** Renders the real layout and navigation files of the three apps, and checks the shared styles follow the rules. */
final class TemplateShellTest extends TestCase
{
    private TemplateTree $tree;
    private string $project;

    protected function setUp(): void
    {
        $this->project = dirname(__DIR__, 2);
        $this->tree = new TemplateTree();

        foreach (TemplateLocator::APPS as $app) {
            foreach (['layout', 'partials/nav'] as $file) {
                $this->tree->put('templates/' . $app . '/' . $file . '.php', (string) file_get_contents($this->project . '/templates/' . $app . '/' . $file . '.php'));
            }
            $this->tree->put('templates/' . $app . '/home.php', '<?php $this->layout("layout"); $this->start("title"); echo $this->e("Home & more"); $this->end(); ?><p>Welcome <?= $this->e($who) ?></p>');
        }
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /** @param array<string, mixed> $extra */
    private function render(string $app, array $extra = [], string $slug = 'demo'): string
    {
        $view = new AppView(new TemplateLocator($this->tree->root), $slug, $app, $extra + [
            'organizationName' => 'Demo <Shop>',
            'homeUrl' => '/' . $slug . '/' . $app,
            'assets' => new AssetUrls($this->tree->root . '/public', '', $slug, $app),
        ]);

        return $view->render('home', ['who' => 'Ana']);
    }

    public function testEveryAppRendersAShellWithSkipLinkAssetsAndBranding(): void
    {
        foreach (TemplateLocator::APPS as $app) {
            $html = $this->render($app);

            $this->assertStringContainsString('<a class="skip-link" href="#main">', $html, $app);
            $this->assertStringContainsString('id="main"', $html, $app);
            $this->assertStringContainsString('<title>Home &amp; more · Demo &lt;Shop&gt;</title>', $html, $app);
            $this->assertStringContainsString('href="/assets/css/tokens.css"', $html, $app);
            $this->assertStringContainsString('href="/assets/css/base.css"', $html, $app);
            $this->assertStringContainsString('href="/assets/' . $app . '/css/app.css"', $html, $app);
            $this->assertStringContainsString('href="/demo/branding.css"', $html, $app);
            $this->assertStringContainsString('<script type="module" src="/assets/js/shell.js"></script>', $html, $app);
            $this->assertStringContainsString('<p>Welcome Ana</p>', $html, $app);
            $this->assertStringNotContainsString('<nav', $html, $app . ' has no navigation items');
            $this->assertStringNotContainsString('data-nav-toggle', $html, $app);
            $this->assertStringNotContainsString('<script>', $html, 'no inline scripts');
        }
    }

    public function testBrandingComesAfterTheBaseStylesSoItWins(): void
    {
        $html = $this->render('admin');

        $this->assertLessThan(strpos($html, '/demo/branding.css'), strpos($html, '/assets/css/tokens.css'));
        $this->assertLessThan(strpos($html, '/demo/branding.css'), strpos($html, '/assets/admin/css/app.css'));
    }

    public function testNavigationIsEscapedAndOnlyLocalLinksAreShown(): void
    {
        $html = $this->render('admin', ['navigation' => [
            ['label' => '<b>Orders</b>', 'url' => '/demo/admin/orders', 'current' => true],
            ['label' => 'Products', 'url' => '/demo/admin/products?x=1&y=2'],
            ['label' => 'Script', 'url' => 'javascript:alert(1)'],
            ['label' => 'Outside', 'url' => '//evil.example/x'],
            ['label' => 'Absolute', 'url' => 'https://evil.example/x'],
            ['label' => 'Broken'],
            'not an item',
        ]]);

        $this->assertStringContainsString('<a href="/demo/admin/orders" aria-current="page">&lt;b&gt;Orders&lt;/b&gt;</a>', $html);
        $this->assertStringContainsString('href="/demo/admin/products?x=1&amp;y=2"', $html);
        $this->assertStringContainsString('data-nav-toggle', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('Broken', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function testOnlyBadNavigationItemsMeansNoNavigationAtAll(): void
    {
        $html = $this->render('shop', ['navigation' => [['label' => 'X', 'url' => 'javascript:alert(1)']]]);

        $this->assertStringNotContainsString('<nav', $html);
    }

    public function testAnOrganizationCanReplaceJustTheNavigation(): void
    {
        $this->tree->put('orgs/acme/pos/partials/nav.php', '<nav class="acme-nav">custom</nav>');

        $acme = $this->render('pos', [], 'acme');
        $other = $this->render('pos', ['navigation' => [['label' => 'Sale', 'url' => '/demo/pos/sale']]]);

        $this->assertStringContainsString('<nav class="acme-nav">custom</nav>', $acme);
        $this->assertStringContainsString('Skip to the main content', $acme, 'the shared layout is still used');
        $this->assertStringContainsString('href="/demo/pos/sale"', $other);
    }

    public function testAdminAndPosAreNotIndexedAndTheShopIs(): void
    {
        $this->assertStringContainsString('name="robots" content="noindex"', $this->render('admin'));
        $this->assertStringContainsString('name="robots" content="noindex"', $this->render('pos'));
        $this->assertStringNotContainsString('noindex', $this->render('shop'));
    }

    public function testSharedStylesUseTokensOnlyAndNoExternalHosts(): void
    {
        $files = [
            'public/assets/css/base.css', 'public/assets/css/fonts.css', 'public/assets/js/shell.js',
            'templates/admin/assets/css/app.css', 'templates/shop/assets/css/app.css', 'templates/pos/assets/css/app.css',
        ];

        foreach ($files as $file) {
            $text = (string) file_get_contents($this->project . '/' . $file);

            $this->assertNotSame('', $text, $file);
            $this->assertSame(0, preg_match('#https?://|@import|//cdn\.#i', $text), $file . ' must not name an external host');
            if (str_ends_with($file, '.css')) {
                $this->assertSame(0, preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $text), $file . ' must use variables for colors');
            }
        }

        $tokens = (string) file_get_contents($this->project . '/public/assets/css/tokens.css');
        $this->assertSame(0, preg_match('#https?://|@import#i', $tokens));
        foreach (['--color-bg', '--color-primary', '--font-body', '--font-heading', '--radius-card', '--radius-control', '--shadow-rest', '--space-4'] as $variable) {
            $this->assertStringContainsString($variable . ':', $tokens);
        }
    }

    public function testPublishedAppStylesMatchTheTemplates(): void
    {
        // If this fails, run: php bin/publish-assets.php
        foreach (TemplateLocator::APPS as $app) {
            $this->assertFileEquals(
                $this->project . '/templates/' . $app . '/assets/css/app.css',
                $this->project . '/public/assets/' . $app . '/css/app.css',
                'public/assets/' . $app . ' is out of date. Run php bin/publish-assets.php',
            );
        }
    }
}
