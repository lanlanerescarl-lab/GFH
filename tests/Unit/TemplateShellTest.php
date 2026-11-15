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
            $this->assertStringContainsString('href="/assets/' . $app . '/css/tokens.css"', $html, $app . ' loads its own token set');
            $this->assertLessThan(strpos($html, '/assets/' . $app . '/css/tokens.css'), strpos($html, '/assets/css/tokens.css'), $app . ': shared tokens first');
            $this->assertLessThan(strpos($html, '/demo/branding.css'), strpos($html, '/assets/' . $app . '/css/tokens.css'), $app . ': branding wins over the token set');
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

    public function testAdminShellHasASidebarAndASignOutFormWithTheCsrfToken(): void
    {
        $html = $this->render('admin', [
            'navigation' => [['label' => 'Inventory', 'url' => '/demo/admin/inventory', 'group' => 'Stock', 'current' => true]],
            'staffName' => 'Ana <Reyes>',
            'staffRole' => 'manager',
            'logoutUrl' => '/demo/admin/logout',
            'csrf' => 'tok"en',
        ]);

        $this->assertStringContainsString('<aside class="admin-sidebar" aria-label="Sidebar">', $html);
        $this->assertStringContainsString('<nav class="shell-nav admin-nav" id="shell-nav" aria-label="Main">', $html);
        $this->assertStringContainsString('>Stock</p>', $html);
        $this->assertStringContainsString('Ana &lt;Reyes&gt;', $html);
        $this->assertStringContainsString('<form class="admin-user__logout" method="post" action="/demo/admin/logout">', $html);
        $this->assertStringContainsString('name="_csrf" value="tok&quot;en"', $html);
        $this->assertLessThan(strpos($html, 'id="main"'), strpos($html, 'class="skip-link"'), 'the skip link comes first');
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

    public function testEachAppHasATokenSetOfVariablesThatBrandingCanOverride(): void
    {
        foreach (TemplateLocator::APPS as $app) {
            $file = 'templates/' . $app . '/assets/css/tokens.css';
            $text = (string) file_get_contents($this->project . '/' . $file);

            // Only custom properties inside :root, so the set themes the app without styling anything itself.
            $withoutComments = (string) preg_replace('#/\*.*?\*/#s', '', $text);
            $this->assertMatchesRegularExpression('/^\s*:root\s*\{[^{}]*\}\s*$/s', $withoutComments, $file . ' must hold only a :root block');
            foreach (preg_split('/;\s*/', trim((string) preg_replace('/^\s*:root\s*\{|\}\s*$/s', '', $withoutComments))) ?: [] as $declaration) {
                if (trim($declaration) !== '') {
                    $this->assertStringStartsWith('--', trim($declaration), $file . ' declares only variables');
                }
            }
            // The branding keys of BrandingCss must be names this set uses, or branding would not reach the app.
            foreach (['--color-primary', '--color-primary-strong', '--color-bg', '--color-ink'] as $variable) {
                $this->assertStringContainsString($variable . ':', $text, $file);
            }
            $this->assertSame(0, preg_match('#url\(|@import|https?://#i', $text), $file);
        }
    }

    public function testSelfHostedFontsAreDeclaredAndVendored(): void
    {
        $fontsCss = (string) file_get_contents($this->project . '/public/assets/css/fonts.css');
        $this->assertStringContainsString('font-family: "Inter";', $fontsCss);
        $this->assertStringContainsString('font-family: "Source Serif 4";', $fontsCss);

        preg_match_all('#url\("\.\./fonts/([A-Za-z0-9._-]+\.woff2)"\)#', $fontsCss, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $file) {
            $path = $this->project . '/public/assets/fonts/' . $file;
            $this->assertFileExists($path);
            $bytes = (string) file_get_contents($path);
            $this->assertStringStartsWith('wOF2', $bytes, $file . ' must be a valid WOFF2 binary');
        }
    }

    public function testPublishedAppStylesMatchTheTemplates(): void
    {
        // If this fails, run: php bin/publish-assets.php
        foreach (TemplateLocator::APPS as $app) {
            foreach (['css/app.css', 'css/tokens.css'] as $file) {
                $this->assertFileEquals(
                    $this->project . '/templates/' . $app . '/assets/' . $file,
                    $this->project . '/public/assets/' . $app . '/' . $file,
                    'public/assets/' . $app . ' is out of date. Run php bin/publish-assets.php',
                );
            }
        }
    }
}
