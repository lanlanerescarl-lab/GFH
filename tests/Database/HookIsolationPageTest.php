<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Database\Database;
use Gfh\Core\Extension\ExtensionLoader;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Organization;
use Gfh\Core\Tenancy\Tenant;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;

/**
 * Batch 8.3 Part A at page level: a callback that throws (or returns the wrong type) when a real Admin or Shop
 * page runs its hook does not make the page a 500. The fixture extension tests/fixtures/extension-root/extensions/
 * throwing-hooks is loaded into the organization's own hook registry; the application log file is read to see
 * what was written.
 */
final class HookIsolationPageTest extends DatabaseTestCase
{
    private App $app;
    private Organization $organization;
    private StaffUser $admin;
    private string $slug;
    private string $productSlug;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 2));
        $this->logFile = dirname(__DIR__, 2) . '/storage/logs/app.log';
        $this->slug = 'iso-' . bin2hex(random_bytes(3));
        $this->organization = $this->kit->organization($this->slug);
        $this->admin = $this->kit->staff('alma@iso.test', TestKit::PASSWORD, null, 'active', 'Alma Admin');
        $this->kit->join($this->organization, $this->admin, 'admin');
        $product = $this->shop->services->catalog()->createProduct([
            'name' => 'Hook Test Lamp',
            'status' => 'active',
            'variants' => [['sku' => 'ISO-' . bin2hex(random_bytes(2)), 'name' => 'Default', 'retail_price' => '500.00']],
        ], null);
        $this->productSlug = $product['slug'];
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function services(bool $withFixture, ?StaffUser $actor = null): Services
    {
        $sessions = [];
        if ($actor !== null) {
            $sessions['staff'] = Session::inMemory('staff', $this->app->config(), ['user_id' => $actor->id]);
        }
        $services = new Services($this->app, false, $this->core, $this->org, testSessions: $sessions);

        if ($withFixture) {
            // The same loader and the same registry the real request would use, pointed at the test-only folder.
            $tenant = new Tenant($this->organization, new Database($this->kit->config), null, $this->org);
            $tenantServices = $services->forTenant($tenant);
            $report = (new ExtensionLoader(dirname(__DIR__) . '/fixtures/extension-root'))
                ->load($this->slug, $tenantServices->hooks(), $tenantServices->settings(), ['throwing_hooks']);
            $this->assertSame(['throwing-hooks'], $report['loaded']);
        }

        return $services;
    }

    private function shopGet(string $path, Services $services): Response
    {
        return $this->app->handle(new Request('GET', '/' . $this->slug . '/shop' . $path), $services);
    }

    private function adminGet(string $path, Services $services): Response
    {
        return $this->app->handle(new Request('GET', '/' . $this->slug . '/admin' . $path), $services);
    }

    private function logSize(): int
    {
        clearstatcache(true, $this->logFile);

        return is_file($this->logFile) ? (int) filesize($this->logFile) : 0;
    }

    /** @return list<string> the log lines written since $offset that are about hook callbacks */
    private function hookLines(int $offset): array
    {
        $text = is_file($this->logFile) ? (string) file_get_contents($this->logFile, false, null, $offset) : '';

        return array_values(array_filter(explode("\n", $text), static fn (string $line): bool => str_contains($line, 'Hook callback skipped')));
    }

    private function assertNoLeak(string $body): void
    {
        foreach (['fixture callback exploded', 'RuntimeException', 'Stack trace', '#0 ', 'register.php'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "the page never shows $needle");
        }
    }

    // ------------------------------------------------------------------ the pages

    public function testTheShopHomeStillAnswers200WhenANavigationCallbackThrows(): void
    {
        $before = $this->logSize();

        $response = $this->shopGet('', $this->services(true));

        $this->assertSame(200, $response->status());
        $body = $response->body();
        $this->assertStringContainsString('>Before<', $body, 'the callback before the broken one still ran');
        $this->assertStringContainsString('>After<', $body, 'the callback after the broken one still ran');
        $this->assertNoLeak($body);

        $lines = $this->hookLines($before);
        $this->assertCount(1, $lines, 'logged once for the request');
        $this->assertStringContainsString('navigation.shop', $lines[0]);
        $this->assertStringContainsString('throwing-hooks', $lines[0]);
        $this->assertStringContainsString('RuntimeException', $lines[0]);
        $this->assertStringContainsString('fixture callback exploded', $lines[0]);
        $this->assertStringContainsString('"organization":"' . $this->slug . '"', $lines[0]);
        $this->assertDoesNotMatchRegularExpression('/Stack trace|#\d+ \S+\.php/', $lines[0], 'no stack trace in the log either');
    }

    public function testTheAdminDashboardStillAnswers200WhenANavigationCallbackThrows(): void
    {
        $before = $this->logSize();

        $response = $this->adminGet('', $this->services(true, $this->admin));

        $this->assertSame(200, $response->status());
        $body = $response->body();
        $this->assertStringContainsString('Before', $body);
        $this->assertStringContainsString('After', $body);
        $this->assertNoLeak($body);

        $lines = $this->hookLines($before);
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('navigation.admin', $lines[0]);
        $this->assertStringContainsString('throwing-hooks', $lines[0]);
    }

    public function testAProductPageStillAnswers200WhenASectionsCallbackReturnsTheWrongType(): void
    {
        $before = $this->logSize();

        $response = $this->shopGet('/products/' . $this->productSlug, $this->services(true));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Hook Test Lamp', $response->body());
        $this->assertStringNotContainsString('not a list', $response->body());

        // Two different callbacks failed on this page (the header's navigation and the sections), one line each.
        $lines = $this->hookLines($before);
        $this->assertCount(2, $lines);
        $sections = array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'product.detail.sections')));
        $this->assertCount(1, $sections);
        $this->assertStringContainsString('returned string where array was expected', $sections[0]);
    }

    public function testEachRequestReportsItsOwnFailureOnce(): void
    {
        $before = $this->logSize();

        foreach ([1, 2] as $request) {
            $this->assertSame(200, $this->shopGet('', $this->services(true))->status());
        }

        $this->assertCount(2, $this->hookLines($before), 'one line for each of the two requests, not more');
    }

    public function testWithNoExtensionThePagesAreUnchangedAndNothingIsLogged(): void
    {
        $before = $this->logSize();

        $shop = $this->shopGet('', $this->services(false));
        $admin = $this->adminGet('', $this->services(false, $this->admin));

        $this->assertSame(200, $shop->status());
        $this->assertSame(200, $admin->status());
        $this->assertStringNotContainsString('>Before<', $shop->body());
        $this->assertStringNotContainsString('fixture-after', $admin->body());
        $this->assertSame([], $this->hookLines($before));
    }
}
