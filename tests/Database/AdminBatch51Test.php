<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Controller\Admin\DashboardController;
use Gfh\Core\Controller\Admin\InventoryController;
use Gfh\Core\Controller\Api\InventoryAdminController;
use Gfh\Core\Database\Database;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Middleware\CsrfMiddleware;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Organization;
use Gfh\Core\Tenancy\Tenant;
use Gfh\Tests\Support\DatabaseTestCase;

final class AdminBatch51Test extends DatabaseTestCase
{
    private string $sessionDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionDir = sys_get_temp_dir() . '/gfh-test-sessions-' . bin2hex(random_bytes(4));
        mkdir($this->sessionDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->sessionDir !== '' && is_dir($this->sessionDir)) {
            foreach (glob($this->sessionDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->sessionDir);
        }
        parent::tearDown();
    }

    private function bootServices(): Services
    {
        $app = App::boot(dirname(__DIR__, 2));
        restore_error_handler();
        restore_exception_handler();

        return new Services($app, false, $this->core);
    }

    private function makeTenant(Organization $organization, ?string $role = null): Tenant
    {
        $membership = $role === null ? null : ['role' => $role, 'status' => 'active'];

        return new Tenant($organization, new Database($this->kit->config), $membership, $this->org);
    }

    private function makeSession(Services $services): Session
    {
        return new Session('staff', $services->app()->config(), $this->sessionDir, false);
    }

    /** @param array<string, mixed> $post */
    private function staffRequest(
        string $method,
        string $path,
        Services $services,
        Tenant $tenant,
        \Gfh\Core\Auth\StaffUser $staff,
        array $query = [],
        array $post = [],
        ?Session $session = null,
    ): Request {
        $session ??= $this->makeSession($services);
        $request = new Request($method, $path, query: $query, post: $post);

        return $request
            ->withAttribute('tenant', $tenant)
            ->withAttribute('staff', $staff)
            ->withAttribute('session', $session);
    }

    public function testDevelopmentSeedIsCompleteAndIdempotent(): void
    {
        $app = App::boot(dirname(__DIR__, 2));
        restore_error_handler();
        restore_exception_handler();

        $seed = require dirname(__DIR__, 2) . '/database/seeds/demo/0001_development.php';
        $this->assertIsCallable($seed);

        $seed($this->org, $this->core, $app);

        $this->assertSame('3', (string) $this->shop->scalar('SELECT COUNT(*) FROM branches'));
        $this->assertSame('2', (string) $this->shop->scalar('SELECT COUNT(*) FROM suppliers'));
        $this->assertSame('8', (string) $this->shop->scalar('SELECT COUNT(*) FROM products'));
        $this->assertSame('3', (string) $this->shop->scalar('SELECT COUNT(*) FROM customers'));
        $this->assertSame('3', (string) $this->shop->scalar('SELECT COUNT(*) FROM orders'));
        $this->assertSame('3', (string) $this->shop->scalar('SELECT COUNT(*) FROM pos_sales'));
        $this->assertSame('1', (string) $this->shop->scalar("SELECT COUNT(*) FROM pos_sales WHERE status = 'needs_review'"));
        $this->assertSame('1', (string) $this->shop->scalar("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'adjustment' AND reason LIKE 'Opening%'"));
        $this->assertSame('1', (string) $this->shop->scalar("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'write_off'"));

        // Running the seed a second time must not duplicate records or fail.
        $seed($this->org, $this->core, $app);
        $this->assertSame('3', (string) $this->shop->scalar('SELECT COUNT(*) FROM branches'));
        $this->assertSame('8', (string) $this->shop->scalar('SELECT COUNT(*) FROM products'));
        $this->assertSame('3', (string) $this->shop->scalar('SELECT COUNT(*) FROM pos_sales'));
    }

    public function testDashboardEnforcesPermissionsAndRendersGrossProfitFirstWithWidgetsHook(): void
    {
        $app = App::boot(dirname(__DIR__, 2));
        restore_error_handler();
        restore_exception_handler();

        $seed = require dirname(__DIR__, 2) . '/database/seeds/demo/0001_development.php';
        $seed($this->org, $this->core, $app);

        $organization = $this->kit->organization('dash-org');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $staff = $this->kit->member($organization, 'staff@example.test', 'staff');
        $cashier = $this->kit->member($organization, 'cashier@example.test', 'cashier');

        $services = $this->bootServices();
        $controller = new DashboardController($services);

        // Signed-out caller gets 401.
        $signedOutRequest = (new Request('GET', '/dash-org/admin'))->withAttribute('tenant', $this->makeTenant($organization));
        $this->assertHttpStatus(401, fn () => $controller->index($signedOutRequest));

        // Cashier has no dashboard:view permission -> 403.
        $cashierReq = $this->staffRequest('GET', '/dash-org/admin', $services, $this->makeTenant($organization, 'cashier'), $cashier);
        $this->assertHttpStatus(403, fn () => $controller->index($cashierReq));

        // Register a dashboard widget with both valid HTML escaping check and an unsafe URL.
        $tenant = $this->makeTenant($organization, 'admin');
        $hooks = $services->forTenant($tenant)->hooks();
        $hooks->add(Hooks::DASHBOARD_WIDGETS, static function (array $widgets): array {
            $widgets[] = [
                'id' => 'roast-queue',
                'title' => 'Roast queue <script>alert(1)</script>',
                'value' => '4 batches',
                'description' => 'Pending daily roast schedule.',
                'action_label' => 'View inventory',
                'action_url' => '/dash-org/admin/inventory',
                'unsafe_skip' => null,
            ];
            $widgets[] = [
                'id' => 'bad-link',
                'title' => 'External test',
                'action_label' => 'Blocked',
                'action_url' => 'https://evil.example/steal',
            ];

            return $widgets;
        });

        $response = $controller->index($this->staffRequest('GET', '/dash-org/admin', $services, $tenant, $admin, query: ['period' => 'today']));
        $this->assertSame(200, $response->status());
        $html = $response->body();

        $kpiStart = strpos($html, 'id="dashboard-kpis-heading"');
        $this->assertIsInt($kpiStart);
        $kpiHtml = substr($html, $kpiStart);

        $gpPos = strpos($kpiHtml, 'Gross profit');
        $nsPos = strpos($kpiHtml, 'Net sales (VAT-excl.)');
        $ordPos = strpos($kpiHtml, 'Orders');
        $lowPos = strpos($kpiHtml, 'Low-stock count');
        $costPos = strpos($kpiHtml, 'Cost completeness');

        $this->assertIsInt($gpPos);
        $this->assertIsInt($nsPos);
        $this->assertIsInt($ordPos);
        $this->assertIsInt($lowPos);
        $this->assertIsInt($costPos);
        $this->assertLessThan($nsPos, $gpPos, 'Gross profit must appear before Net sales.');
        $this->assertLessThan($ordPos, $nsPos);
        $this->assertLessThan($lowPos, $ordPos);
        $this->assertLessThan($costPos, $lowPos);

        // Peso & percentage formatting + Chart.js data payload + escaped widget hook + rejected unsafe URL.
        $this->assertStringContainsString('₱', $html);
        $this->assertStringContainsString('%', $html);
        $this->assertStringContainsString('data-chart="line"', $html);
        $this->assertStringContainsString('data-chart="bar"', $html);
        $this->assertStringContainsString('Roast queue &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('href="/dash-org/admin/inventory"', $html);
        $this->assertStringNotContainsString('https://evil.example/steal', $html);

        // Staff role also has dashboard:view -> 200.
        $staffResponse = $controller->index($this->staffRequest('GET', '/dash-org/admin', $services, $this->makeTenant($organization, 'staff'), $staff));
        $this->assertSame(200, $staffResponse->status());
    }

    public function testReorderSuggestionsCalculateAvailableMinusOnOrderAndSupportListQuery(): void
    {
        $branch = $this->shop->branch('B1');
        $supplier = $this->shop->services->procurement()->createSupplier(['name' => 'Bean Supplier'], null)['id'];
        $otherSupplier = $this->shop->services->procurement()->createSupplier(['name' => 'Box Supplier'], null)['id'];

        $vOut = $this->shop->variant(['sku' => 'REO-OUT', 'reorder' => '10']);
        $vLowWithPo = $this->shop->variant(['sku' => 'REO-LOW', 'reorder' => '20']);
        $vCovered = $this->shop->variant(['sku' => 'REO-COV', 'reorder' => '10']);
        $vHealthy = $this->shop->variant(['sku' => 'REO-OK', 'reorder' => '5']);

        $this->org->prepare('UPDATE products SET supplier_id = :s WHERE id IN (SELECT product_id FROM product_variants WHERE id IN (:v1, :v2))')
            ->execute(['s' => $supplier, 'v1' => $vOut, 'v2' => $vLowWithPo]);
        $this->org->prepare('UPDATE products SET supplier_id = :s WHERE id IN (SELECT product_id FROM product_variants WHERE id IN (:v1, :v2))')
            ->execute(['s' => $otherSupplier, 'v1' => $vCovered, 'v2' => $vHealthy]);

        // vOut: 0 on hand, 0 on order -> suggested 10.
        // vLowWithPo: 5 on hand, 8 on sent PO -> suggested max(0, 20 - 5 - 8) = 7.
        $this->shop->stock($branch, $vLowWithPo, '5', '50.00');
        $po = $this->shop->services->procurement()->createPurchaseOrder([
            'supplier_id' => $supplier,
            'branch_id' => $branch,
            'lines' => [['variant_id' => $vLowWithPo, 'quantity' => '8', 'unit_cost' => '50.00']],
        ], null);
        $this->shop->services->procurement()->sendPurchaseOrder($po['id'], null);

        // vCovered: 4 on hand, 10 on sent PO -> suggested 0 (covered by PO, still low stock until received).
        $this->shop->stock($branch, $vCovered, '4', '30.00');
        $po2 = $this->shop->services->procurement()->createPurchaseOrder([
            'supplier_id' => $otherSupplier,
            'branch_id' => $branch,
            'lines' => [['variant_id' => $vCovered, 'quantity' => '10', 'unit_cost' => '30.00']],
        ], null);
        $this->shop->services->procurement()->sendPurchaseOrder($po2['id'], null);

        // vHealthy: 15 on hand vs reorder_level 5 -> excluded.
        $this->shop->stock($branch, $vHealthy, '15', '20.00');

        $inventory = $this->shop->services->inventory();
        $all = $inventory->reorderSuggestions(ListQuery::fromRequest(
            new Request('GET', '/reorders', query: ['per_page' => '10', 'sort' => '-suggested_qty']),
            ['suggested_qty' => 'suggested_qty', 'sku' => 'sku'],
            '-suggested_qty',
            ['supplier_id', 'status'],
            ['supplier_id'],
        ));

        $this->assertSame(3, $all['pagination']['total']);
        $bySku = array_column($all['items'], null, 'sku');
        $this->assertSame('10.000', $bySku['REO-OUT']['suggested_qty']);
        $this->assertSame('7.000', $bySku['REO-LOW']['suggested_qty']);
        $this->assertSame('8.000', $bySku['REO-LOW']['on_order']);
        $this->assertSame('0.000', $bySku['REO-COV']['suggested_qty']);

        // Search & filter by status=out_of_stock.
        $outOnly = $inventory->reorderSuggestions(ListQuery::fromRequest(
            new Request('GET', '/reorders', query: ['status' => 'out_of_stock', 'q' => 'REO-OUT']),
            ['suggested_qty' => 'suggested_qty', 'sku' => 'sku'],
            '-suggested_qty',
            ['supplier_id', 'status'],
            ['supplier_id'],
        ));
        $this->assertSame(1, $outOnly['pagination']['total']);
        $this->assertSame('REO-OUT', $outOnly['items'][0]['sku']);

        // API controller enforces inventory:view.
        $organization = $this->kit->organization('reo-api');
        $cashier = $this->kit->member($organization, 'cashier@example.test', 'cashier');
        $services = $this->bootServices();
        $api = new InventoryAdminController($services);
        $req = $this->staffRequest('GET', '/api/v1/admin/inventory/reorder-suggestions', $services, $this->makeTenant($organization, 'cashier'), $cashier);
        $this->assertHttpStatus(403, fn () => $api->reorderSuggestions($req));
    }

    public function testInventoryControllerEnforcesPermissionsValidatesFormsAndSupportsListQueries(): void
    {
        $organization = $this->kit->organization('inv-org');
        $staff = $this->kit->member($organization, 'staff@example.test', 'staff');
        $manager = $this->kit->member($organization, 'manager@example.test', 'manager');
        $cashier = $this->kit->member($organization, 'cashier@example.test', 'cashier');

        $branchA = $this->shop->branch('BR-A');
        $branchB = $this->shop->branch('BR-B');
        $variant = $this->shop->variant(['sku' => 'INV-SKU-1', 'name' => 'Alpha Coffee', 'reorder' => '5']);
        $this->shop->stock($branchA, $variant, '10', '80.00');
        $this->shop->stock($branchB, $variant, '2', '80.00');

        // Restrict manager to read-only inventory:view (can_edit = false) in this organization to verify view vs edit.
        $this->core->prepare(
            "INSERT INTO organization_role_permissions (organization_id, role, module, can_view, can_edit, can_delete)
             VALUES (:org, 'manager', 'inventory', true, false, false)",
        )->execute(['org' => $organization->id]);

        $services = $this->bootServices();
        $controller = new InventoryController($services);

        // Cashier has no inventory:view -> 403 on all views.
        $cashierTenant = $this->makeTenant($organization, 'cashier');
        $this->assertHttpStatus(403, fn () => $controller->levels($this->staffRequest('GET', '/inv-org/admin/inventory', $services, $cashierTenant, $cashier)));
        $this->assertHttpStatus(403, fn () => $controller->ledger($this->staffRequest('GET', '/inv-org/admin/inventory/ledger', $services, $cashierTenant, $cashier)));
        $this->assertHttpStatus(403, fn () => $controller->reorder($this->staffRequest('GET', '/inv-org/admin/inventory/reorder', $services, $cashierTenant, $cashier)));

        // Manager has inventory:view but NOT inventory:edit -> 200 read-only on GET, 403 on POST.
        $managerTenant = $this->makeTenant($organization, 'manager');
        $mgrLevels = $controller->levels($this->staffRequest('GET', '/inv-org/admin/inventory', $services, $managerTenant, $manager));
        $this->assertSame(200, $mgrLevels->status());
        $this->assertStringNotContainsString('Adjust stock', $mgrLevels->body());

        $this->assertHttpStatus(403, fn () => $controller->adjust(
            $this->staffRequest('POST', '/inv-org/admin/inventory/adjustments', $services, $managerTenant, $manager, post: [
                'branch_id' => $branchA, 'variant_id' => $variant, 'delta' => '2', 'reason' => 'Attempt by manager',
            ]),
        ));
        $this->assertHttpStatus(403, fn () => $controller->writeOff(
            $this->staffRequest('POST', '/inv-org/admin/inventory/write-offs', $services, $managerTenant, $manager, post: [
                'branch_id' => $branchA, 'variant_id' => $variant, 'quantity' => '1', 'reason' => 'Attempt by manager',
            ]),
        ));

        // Staff has inventory:edit -> levels search/filter/sort/pagination works.
        $staffTenant = $this->makeTenant($organization, 'staff');
        $lowOnly = $controller->levels($this->staffRequest(
            'GET',
            '/inv-org/admin/inventory',
            $services,
            $staffTenant,
            $staff,
            query: ['q' => 'INV-SKU-1', 'low_stock' => '1', 'sort' => '-available', 'page' => '1', 'per_page' => '10'],
        ));
        $this->assertSame(200, $lowOnly->status());
        $this->assertSame(1, preg_match('#<tbody>(.*?)</tbody>#s', $lowOnly->body(), $tableBody));
        $this->assertStringContainsString('BR-B', $tableBody[1]);
        $this->assertStringNotContainsString('BR-A', $tableBody[1]);
        $this->assertStringContainsString('Adjust stock', $lowOnly->body());

        // Validation failure: adjustment without reason -> 422 with inline field error & preserved input.
        $badAdjust = $controller->adjust($this->staffRequest(
            'POST',
            '/inv-org/admin/inventory/adjustments',
            $services,
            $staffTenant,
            $staff,
            post: ['branch_id' => $branchA, 'variant_id' => $variant, 'delta' => '5', 'reason' => ''],
        ));
        $this->assertSame(422, $badAdjust->status());
        $this->assertStringContainsString('aria-invalid="true"', $badAdjust->body());
        $this->assertStringContainsString('class="field-error"', $badAdjust->body());
        $this->assertStringContainsString('value="5"', $badAdjust->body());

        // Validation failure: write-off exceeding on_hand -> 422 with field error.
        $badWriteOff = $controller->writeOff($this->staffRequest(
            'POST',
            '/inv-org/admin/inventory/write-offs',
            $services,
            $staffTenant,
            $staff,
            post: ['branch_id' => $branchB, 'variant_id' => $variant, 'quantity' => '99', 'reason' => 'Too much damaged'],
        ));
        $this->assertSame(422, $badWriteOff->status());
        $this->assertStringContainsString('aria-invalid="true"', $badWriteOff->body());
        $this->assertStringContainsString('class="field-error"', $badWriteOff->body());

        // Valid adjustment & valid write-off succeed, redirect cleanly, flash session, and update ledger & on-hand.
        $session = $this->makeSession($services);
        $okAdjust = $controller->adjust($this->staffRequest(
            'POST',
            '/inv-org/admin/inventory/adjustments',
            $services,
            $staffTenant,
            $staff,
            post: ['branch_id' => $branchA, 'variant_id' => $variant, 'delta' => '5', 'reason' => 'Cycle count surplus'],
            session: $session,
        ));
        $this->assertSame(302, $okAdjust->status());
        $this->assertSame('/inv-org/admin/inventory', $okAdjust->header('Location'));
        $this->assertIsArray($session->get('_admin_flash'));

        $okWriteOff = $controller->writeOff($this->staffRequest(
            'POST',
            '/inv-org/admin/inventory/write-offs',
            $services,
            $staffTenant,
            $staff,
            post: ['branch_id' => $branchA, 'variant_id' => $variant, 'quantity' => '3', 'reason' => 'Damaged during handling'],
            session: $session,
        ));
        $this->assertSame(302, $okWriteOff->status());

        $this->assertSame('12.000', $this->shop->services->inventory()->onHand($branchA, $variant));

        // Ledger supports filter by type + search and displays formatted Asia/Manila timestamps and reasons.
        $ledger = $controller->ledger($this->staffRequest(
            'GET',
            '/inv-org/admin/inventory/ledger',
            $services,
            $staffTenant,
            $staff,
            query: ['type' => 'write_off', 'q' => 'Damaged during handling', 'sort' => '-time', 'per_page' => '20'],
        ));
        $this->assertSame(200, $ledger->status());
        $this->assertStringContainsString('Damaged during handling', $ledger->body());
        $this->assertStringNotContainsString('Cycle count surplus', $ledger->body());
    }

    public function testAdminBatch51RoutesAreRegisteredBehindStaffAndCsrfMiddleware(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/core/routes.php');

        foreach ([
            "\$r->get('/admin', static fn (Request \$q) => \$adminDashboard->index(\$q), [\$tenant, \$staffSession, \$requireStaff])",
            "\$r->get('/admin/inventory', static fn (Request \$q) => \$adminInventory->levels(\$q), [\$tenant, \$staffSession, \$requireStaff])",
            "\$r->post('/admin/inventory/adjustments', static fn (Request \$q) => \$adminInventory->adjust(\$q), [\$tenant, \$staffSession, \$csrf, \$requireStaff])",
            "\$r->post('/admin/inventory/write-offs', static fn (Request \$q) => \$adminInventory->writeOff(\$q), [\$tenant, \$staffSession, \$csrf, \$requireStaff])",
            "\$r->get('/admin/inventory/ledger', static fn (Request \$q) => \$adminInventory->ledger(\$q), [\$tenant, \$staffSession, \$requireStaff])",
            "\$r->get('/admin/inventory/reorder', static fn (Request \$q) => \$adminInventory->reorder(\$q), [\$tenant, \$staffSession, \$requireStaff])",
            "\$r->get('/api/v1/admin/inventory/reorder-suggestions', static fn (Request \$q) => \$inventoryAdmin->reorderSuggestions(\$q), \$staff)",
        ] as $expected) {
            $this->assertStringContainsString($expected, $routes);
        }
    }

    public function testCsrfMiddlewareBlocksStateChangeWithoutValidToken(): void
    {
        $services = $this->bootServices();
        $organization = $this->kit->organization('csrf-org');
        $staff = $this->kit->member($organization, 'staff@example.test', 'staff');
        $tenant = $this->makeTenant($organization, 'staff');
        $session = $this->makeSession($services);
        $csrfMiddleware = new CsrfMiddleware();

        $missingToken = $this->staffRequest(
            'POST',
            '/csrf-org/admin/inventory/adjustments',
            $services,
            $tenant,
            $staff,
            post: ['delta' => '1', 'reason' => 'No token'],
            session: $session,
        )->withAttribute('csrf', $services->csrf($session));

        $this->assertHttpStatus(403, fn () => $csrfMiddleware($missingToken, static fn (): Response => Response::html('ok')));

        $token = $services->csrf($session)->token();
        $withToken = $this->staffRequest(
            'POST',
            '/csrf-org/admin/inventory/adjustments',
            $services,
            $tenant,
            $staff,
            post: ['_csrf' => $token, 'delta' => '1', 'reason' => 'Valid token'],
            session: $session,
        )->withAttribute('csrf', $services->csrf($session));

        $allowed = $csrfMiddleware($withToken, static fn (): Response => Response::html('ok'));
        $this->assertSame(200, $allowed->status());
    }
}
