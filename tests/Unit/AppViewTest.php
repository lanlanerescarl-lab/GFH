<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\View\AppView;
use Gfh\Core\View\AssetUrls;
use Gfh\Core\View\TemplateLocator;
use Gfh\Tests\Support\TemplateTree;
use PHPUnit\Framework\TestCase;

final class AppViewTest extends TestCase
{
    private TemplateTree $tree;

    protected function setUp(): void
    {
        $this->tree = new TemplateTree();
        $this->tree->put('templates/admin/layout.php', '[<?= $this->section("title", "Untitled") ?>|<?= $this->partial("partials/nav") ?>|<?= $this->section("content") ?>]');
        $this->tree->put('templates/admin/partials/nav.php', 'nav:<?= $this->e($org) ?>');
        $this->tree->put('templates/admin/orders.php', '<?php $this->layout("layout"); $this->start("title"); echo "Orders"; $this->end(); ?>Hello <?= $this->e($name) ?>');
        $this->tree->put('templates/admin/plain.php', 'plain <?= $this->e($name) ?>');
        $this->tree->put('templates/admin/broken.php', '<?php $this->start("title"); echo "half"; throw new \RuntimeException("boom");');
        $this->tree->put('templates/admin/bad-partial.php', '<?= $this->partial("partials/wants-layout") ?>');
        $this->tree->put('templates/admin/partials/wants-layout.php', '<?php $this->layout("layout"); ?>x');
        $this->tree->put('orgs/acme/admin/partials/nav.php', 'acme-nav');
        $this->tree->put('orgs/acme/admin/plain.php', 'acme plain <?= $this->e($name) ?>');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /** @param array<string, mixed> $shared */
    private function view(string $slug = 'beta', array $shared = ['org' => 'Shared Org']): AppView
    {
        return new AppView(new TemplateLocator($this->tree->root), $slug, 'admin', $shared);
    }

    public function testALayoutWrapsTheViewAndSectionsReachIt(): void
    {
        $this->assertSame('[Orders|nav:Shared Org|Hello Ana]', $this->view()->render('orders', ['name' => 'Ana']));
    }

    public function testOutputIsEscaped(): void
    {
        $html = $this->view()->render('plain', ['name' => '<b onclick="x">O\'Neil</b>']);

        $this->assertSame('plain &lt;b onclick=&quot;x&quot;&gt;O&#039;Neil&lt;/b&gt;', $html);
    }

    public function testAnOrganizationFileOverridesTheSharedOne(): void
    {
        $this->assertSame('acme plain Ana', $this->view('acme')->render('plain', ['name' => 'Ana']));
        $this->assertSame('plain Ana', $this->view('beta')->render('plain', ['name' => 'Ana']));
    }

    public function testAnOrganizationCanOverrideJustOnePartialInsideASharedLayout(): void
    {
        $this->assertSame('[Orders|acme-nav|Hello Ana]', $this->view('acme')->render('orders', ['name' => 'Ana']));
    }

    public function testSharedDataIsAvailableAndDataPassedToACallWins(): void
    {
        $this->tree->put('templates/admin/who.php', '<?= $this->e($org) ?>');

        $this->assertSame('Shared Org', $this->view()->render('who'));
        $this->assertSame('Call Org', $this->view()->render('who', ['org' => 'Call Org']));
    }

    public function testAViewThatFailsLeavesNoOutputBufferOpenAndNothingLeaks(): void
    {
        $level = ob_get_level();
        $view = $this->view();

        try {
            $view->render('broken');
            $this->fail('The view should have thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame($level, ob_get_level());
        $this->assertSame('plain Ana', $view->render('plain', ['name' => 'Ana']), 'the view object is still usable');
    }

    public function testAPartialCannotChooseALayout(): void
    {
        $level = ob_get_level();

        try {
            $this->view()->render('bad-partial');
            $this->fail('A partial that calls layout() should be refused.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('partial cannot choose a layout', $exception->getMessage());
        }

        $this->assertSame($level, ob_get_level());
    }

    public function testPartialAndSectionsCannotBeUsedOutsideAView(): void
    {
        $view = $this->view();

        $this->assertSame('nav:Shared Org', $view->partial('partials/nav'));

        $this->expectException(\LogicException::class);
        $view->section('title');
    }

    public function testLayoutOutsideAViewIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->view()->layout('layout');
    }

    public function testEndWithoutStartAndBadSectionNamesAreRefused(): void
    {
        $this->tree->put('templates/admin/bad-end.php', '<?php $this->end();');
        $this->tree->put('templates/admin/bad-name.php', '<?php $this->start("content");');

        foreach (['bad-end', 'bad-name'] as $name) {
            try {
                $this->view()->render($name);
                $this->fail($name . ' should have been refused.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNamesThatClimbOutOfTheFolderAreRefused(): void
    {
        $this->tree->put('secret.php', 'top secret');

        $this->expectException(\InvalidArgumentException::class);
        $this->view()->render('../secret');
    }

    public function testABadSlugIsRefusedWhenTheViewIsBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->view('../acme');
    }

    public function testTheRealAdminShopAndPosLayoutShellsRenderThroughAppView(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $locator = new TemplateLocator($this->tree->root);

        foreach (TemplateLocator::APPS as $app) {
            $this->tree->put('templates/' . $app . '/layout.php', (string) file_get_contents($projectRoot . '/templates/' . $app . '/layout.php'));
            $this->tree->put('templates/' . $app . '/partials/nav.php', (string) file_get_contents($projectRoot . '/templates/' . $app . '/partials/nav.php'));
            // The Shop layout is built from more partials (header, search box, footer, placeholder); copy them all.
            foreach ($app === 'shop' ? (glob($projectRoot . '/templates/shop/partials/*.php') ?: []) : [] as $partial) {
                $this->tree->put('templates/shop/partials/' . basename($partial), (string) file_get_contents($partial));
            }
            $this->tree->put('templates/' . $app . '/sample.php', '<?php $this->layout("layout"); $this->start("title"); echo "Sample"; $this->end(); ?><p>Shell body</p>');

            $view = new AppView($locator, 'demo', $app, [
                'organizationName' => 'Demo <Store>',
                'homeUrl' => '/demo/' . $app,
                'assets' => new AssetUrls($projectRoot . '/public', '', 'demo', $app),
            ]);

            $html = $view->render('sample', [
                'navigation' => [
                    ['label' => 'Orders', 'url' => '/demo/' . $app . '/orders', 'current' => true],
                    ['label' => 'Outside', 'url' => 'https://evil.example/x'],
                    ['label' => 'Protocol relative', 'url' => '//evil.example/x'],
                    ['label' => 'Script', 'url' => 'javascript:alert(1)'],
                ],
            ]);

            $this->assertStringContainsString('<body class="app-' . $app . '">', $html);
            $this->assertStringContainsString('Sample · Demo &lt;Store&gt;</title>', $html);
            $this->assertStringContainsString('href="/demo/branding.css"', $html);
            $this->assertStringContainsString('href="/assets/' . $app . '/css/app.css"', $html);
            $this->assertStringContainsString('src="/assets/js/shell.js"', $html);
            $this->assertStringContainsString('<p>Shell body</p>', $html);
            $this->assertStringContainsString('href="/demo/' . $app . '/orders" aria-current="page">Orders</a>', $html);
            $this->assertStringContainsString('data-nav-toggle', $html);
            $this->assertStringNotContainsString('evil.example', $html);
            $this->assertStringNotContainsString('javascript:', $html);

            $withoutNav = $view->render('sample');
            $this->assertStringNotContainsString('data-nav-toggle', $withoutNav);
        }
    }
}
