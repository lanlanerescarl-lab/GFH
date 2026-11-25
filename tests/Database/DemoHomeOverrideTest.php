<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Http\Request;
use Gfh\Core\Support\Services;
use Gfh\Core\View\AppView;
use Gfh\Core\View\TemplateLocator;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TemplateTree;

/** Batch 8.2b: orgs/demo/shop/partials/home-intro.php changes the demo organization's Shop home page, and only that. */
final class DemoHomeOverrideTest extends DatabaseTestCase
{
    private const INTRO = 'Welcome to the demonstration shop';

    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    private function home(string $slug): string
    {
        $response = $this->app->handle(new Request('GET', '/' . $slug . '/shop'), new Services($this->app, false, $this->core, $this->org));
        $this->assertSame(200, $response->status());

        return $response->body();
    }

    public function testTheDemoOrganizationsHomePageShowsTheIntroductionAndAnotherOrganizationsDoesNot(): void
    {
        $this->kit->organization('demo');
        $this->kit->organization('other-shop');

        $demo = $this->home('demo');
        $this->assertStringContainsString(self::INTRO, $demo);
        $this->assertStringContainsString('Test demo', $demo, 'the introduction names the organization, escaped');
        $this->assertStringContainsString('href="/demo/shop/products"', $demo);
        // The rest of the page is still core's: hero, then the new section, then the product areas.
        $this->assertLessThan(strpos($demo, self::INTRO), strpos($demo, 'shop-hero__title'));
        $this->assertLessThan(strpos($demo, 'shop-newest-title'), strpos($demo, self::INTRO));

        $other = $this->home('other-shop');
        $this->assertStringNotContainsString(self::INTRO, $other);
        $this->assertStringNotContainsString('demo-intro-title', $other);
        $this->assertStringContainsString('shop-hero__title', $other, 'its home page is the default one');
    }

    public function testRemovingTheOverrideRestoresTheDefault(): void
    {
        $root = dirname(__DIR__, 2);
        $tree = new TemplateTree();
        try {
            $tree->put('templates/shop/partials/home-intro.php', (string) file_get_contents($root . '/templates/shop/partials/home-intro.php'));
            $tree->put('orgs/demo/shop/partials/home-intro.php', (string) file_get_contents($root . '/orgs/demo/shop/partials/home-intro.php'));
            $locator = new TemplateLocator($tree->root);
            $data = ['organizationName' => 'Demo <Shop>', 'productsUrl' => '/demo/shop/products'];

            $this->assertSame($tree->root . '/orgs/demo/shop/partials/home-intro.php', $locator->resolve('demo', 'shop', 'partials/home-intro'));
            $withOverride = (new AppView($locator, 'demo', 'shop'))->render('partials/home-intro', $data);
            $this->assertStringContainsString(self::INTRO, $withOverride);
            $this->assertStringContainsString('Demo &lt;Shop&gt;', $withOverride, 'the organization name is escaped');
            $this->assertStringNotContainsString('<Shop>', $withOverride);

            unlink($tree->root . '/orgs/demo/shop/partials/home-intro.php');

            $this->assertSame($tree->root . '/templates/shop/partials/home-intro.php', $locator->resolve('demo', 'shop', 'partials/home-intro'));
            $this->assertSame('', trim((new AppView($locator, 'demo', 'shop'))->render('partials/home-intro', $data)));
        } finally {
            $tree->remove();
        }
    }
}
