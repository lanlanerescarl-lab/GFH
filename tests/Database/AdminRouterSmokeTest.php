<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Http\Request;
use Gfh\Core\Support\Services;
use Gfh\Tests\Support\DatabaseTestCase;

/**
 * Dispatches every Admin GET page through the real App + Router + core/routes.php + middleware stack as a signed-in
 * organization Admin, with the ErrorHandler active, so unbound route variables, bad URL ordering, warnings, and
 * rendering errors fail the build immediately.
 */
final class AdminRouterSmokeTest extends DatabaseTestCase
{
    public function testSignedInAdminCanLoadEveryAdminGetPageWithoutErrors(): void
    {
        $slug = 'smoke-' . bin2hex(random_bytes(3));
        $organization = $this->kit->organization($slug);
        $admin = $this->kit->member($organization, 'admin@' . $slug . '.test', 'admin');
        $crew = $this->kit->member($organization, 'crew@' . $slug . '.test', 'staff');

        $root = dirname(__DIR__, 2);
        $app = App::boot($root);

        try {
            // Seed realistic multi-branch data (rolled back in tearDown) so list/detail pages render real rows.
            $seed = require $root . '/database/seeds/demo/0001_development.php';
            $seed($this->org, $this->core, $app);

            $session = Session::inMemory('staff', $app->config(), ['user_id' => $admin->id]);
            $services = new Services($app, false, $this->core, $this->org, testSessions: ['staff' => $session]);

            $productId = (string) $this->org->query('SELECT id FROM products ORDER BY created_at ASC LIMIT 1')->fetchColumn();
            $supplierId = (string) $this->org->query('SELECT id FROM suppliers ORDER BY created_at ASC LIMIT 1')->fetchColumn();
            $sentPoId = (string) $this->org->query("SELECT id FROM purchase_orders WHERE status = 'sent' ORDER BY created_at ASC LIMIT 1")->fetchColumn();
            $receivedPoId = (string) $this->org->query("SELECT id FROM purchase_orders WHERE status = 'received' ORDER BY created_at ASC LIMIT 1")->fetchColumn();
            $pickupOrderId = (string) $this->org->query("SELECT id FROM orders WHERE fulfillment_method = 'pickup' ORDER BY placed_at ASC LIMIT 1")->fetchColumn();
            $deliveryOrderId = (string) $this->org->query("SELECT id FROM orders WHERE fulfillment_method = 'delivery' ORDER BY placed_at ASC LIMIT 1")->fetchColumn();
            $completedPosSaleId = (string) $this->org->query("SELECT id FROM pos_sales WHERE status = 'completed' ORDER BY sold_at ASC LIMIT 1")->fetchColumn();
            $reviewPosSaleId = (string) $this->org->query("SELECT id FROM pos_sales WHERE status = 'needs_review' ORDER BY sold_at ASC LIMIT 1")->fetchColumn();
            $branchId = (string) $this->org->query('SELECT id FROM branches ORDER BY created_at ASC, id ASC LIMIT 1')->fetchColumn();
            $terminalId = (string) $this->org->query('SELECT id FROM terminals ORDER BY created_at ASC, id ASC LIMIT 1')->fetchColumn();
            $discountRuleId = (string) $this->org->query('SELECT id FROM discount_rules ORDER BY created_at ASC, id ASC LIMIT 1')->fetchColumn();
            $orderStepKey = (string) $this->org->query('SELECT key FROM order_progress_steps ORDER BY sort_order ASC, key ASC LIMIT 1')->fetchColumn();
            $this->assertNotSame('', $discountRuleId, 'The seed has no discount rule, so the edit page would not be covered.');
            $this->assertNotSame('', $orderStepKey, 'The seed has no order step, so the edit page would not be covered.');

            $paths = [
                "/$slug/admin",
                "/$slug/admin/inventory",
                "/$slug/admin/inventory/ledger",
                "/$slug/admin/inventory/reorder",
                "/$slug/admin/products",
                "/$slug/admin/products/new",
                "/$slug/admin/products/$productId",
                "/$slug/admin/brands",
                "/$slug/admin/categories",
                "/$slug/admin/procurement",
                "/$slug/admin/procurement/new",
                "/$slug/admin/procurement/suppliers",
                "/$slug/admin/procurement/suppliers/$supplierId",
                "/$slug/admin/procurement/$sentPoId",
                "/$slug/admin/procurement/$receivedPoId",
                "/$slug/admin/orders",
                "/$slug/admin/orders/$pickupOrderId",
                "/$slug/admin/orders/$deliveryOrderId",
                "/$slug/admin/customers",
                "/$slug/admin/reports",
                "/$slug/admin/team",
                "/$slug/admin/team/new",
                "/$slug/admin/team/{$crew->id}",
                "/$slug/admin/settings",
                "/$slug/admin/settings/branches",
                "/$slug/admin/settings/branches/new",
                "/$slug/admin/settings/branches/$branchId",
                "/$slug/admin/settings/terminals",
                "/$slug/admin/settings/terminals/new",
                "/$slug/admin/settings/terminals/$terminalId",
                "/$slug/admin/settings/discounts",
                "/$slug/admin/settings/discounts/new",
                "/$slug/admin/settings/discounts/$discountRuleId",
                "/$slug/admin/settings/order-steps",
                "/$slug/admin/settings/order-steps/new",
                "/$slug/admin/settings/order-steps/$orderStepKey",
                "/$slug/admin/settings/general",
                "/$slug/admin/pos-sales",
                "/$slug/admin/pos-sales/$completedPosSaleId",
                "/$slug/admin/pos-sales/$reviewPosSaleId",
                "/$slug/admin/ui-kit",
            ];

            $failures = [];
            foreach ($paths as $path) {
                $response = $app->handle(new Request('GET', $path), $services);
                $body = $response->body();

                if ($response->status() !== 200) {
                    $debug = '';
                    if (preg_match('/<div class="error-page__debug"[^>]*>(.*?)<\/div>/s', $body, $matches) === 1) {
                        $debug = ' | ' . trim((string) preg_replace('/\s+/', ' ', strip_tags($matches[1])));
                    }
                    $failures[] = sprintf('GET %s returned HTTP %d%s', $path, $response->status(), $debug);
                    continue;
                }

                $this->assertStringContainsString('<!doctype html>', strtolower($body), "GET $path did not render the HTML shell.");
                $this->assertStringContainsString('Skip to the main content', $body, "GET $path missed the Admin shell layout.");
            }

            $this->assertSame([], $failures, implode("\n", $failures));

            // Batch 7.1: the POS app's pages. The shell as the signed-in Admin (who holds pos view), and the POS sign-in
            // as a signed-out visitor (a signed-in one is sent on to the app, which is its own check in PosAppTest).
            $signedOut = new Services($app, false, $this->core, $this->org, testSessions: ['staff' => Session::inMemory('staff', $app->config(), [])]);
            foreach ([["/$slug/pos", $services, 'id="pos-app"'], ["/$slug/pos/login", $signedOut, 'name="password"']] as [$path, $desk, $marker]) {
                $response = $app->handle(new Request('GET', $path), $desk);
                $this->assertSame(200, $response->status(), "GET $path");
                $this->assertStringContainsString('<!doctype html>', strtolower($response->body()), "GET $path did not render the HTML shell.");
                $this->assertStringContainsString('Skip to the main content', $response->body(), "GET $path missed the POS layout.");
                $this->assertStringContainsString($marker, $response->body(), "GET $path did not render its page.");
            }

            // Batch 5.4a: the Reports page in each view, through the real router, with the current Manila date.
            $manilaToday = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
            foreach ([
                ['view' => 'day', 'date' => $manilaToday->format('Y-m-d')],
                ['view' => 'month', 'month' => $manilaToday->format('Y-m')],
                ['view' => 'year', 'year' => $manilaToday->format('Y')],
            ] as $query) {
                $reportResponse = $app->handle(new Request('GET', "/$slug/admin/reports", query: $query), $services);
                $this->assertSame(200, $reportResponse->status(), 'GET reports ' . $query['view']);
                $this->assertStringContainsString('Net sales', $reportResponse->body());
                $this->assertStringContainsString('whether or not it has been paid or delivered', $reportResponse->body());
            }
            $badReport = $app->handle(new Request('GET', "/$slug/admin/reports", query: ['view' => 'day', 'date' => '2026-02-30']), $services);
            $this->assertSame(422, $badReport->status());

            // Phase 1 fixes, checked on the pages as the real router renders them.
            $page = static fn (string $path): string => $app->handle(new Request('GET', $path), $services)->body();

            // A sent order shows when it was sent; the receive form shows batch fields only where they are stored.
            $sentPage = $page("/$slug/admin/procurement/$sentPoId");
            $this->assertStringNotContainsString('Not sent yet', $sentPage);
            $open = $this->org->prepare(
                'SELECT COUNT(*) FILTER (WHERE v.track_batches) AS tracked, COUNT(*) FILTER (WHERE NOT v.track_batches) AS plain '
                . 'FROM purchase_order_lines l JOIN product_variants v ON v.id = l.variant_id '
                . 'WHERE l.purchase_order_id = ? AND l.qty_received + l.qty_rejected < l.qty_ordered',
            );
            $open->execute([$sentPoId]);
            $counts = $open->fetch();
            foreach (['batch_code', 'expires_on', 'bin'] as $field) {
                $this->assertSame((int) $counts['tracked'], preg_match_all('/name="lines\[\d+\]\[' . $field . '\]"/', $sentPage), "$field is offered only for variants that track batches");
            }
            $this->assertSame((int) $counts['plain'], substr_count($sentPage, 'Batch not tracked for this item'));
            $this->assertStringNotContainsString('Not sent yet', $page("/$slug/admin/procurement/$receivedPoId"));

            // The dashboard and Customers screens say what their figures count.
            $this->assertStringContainsString('all branches combined', $page("/$slug/admin"));
            $this->assertStringContainsString('whether or not it has been paid or delivered', $page("/$slug/admin"));
            $this->assertStringContainsString('whether or not they have been paid', $page("/$slug/admin/customers"));

            // A partial payment reads "Partly paid (...)" on the list and on the order, while the stored status stays.
            $unpaid = $this->org->query(
                "SELECT id, total FROM orders WHERE payment_status = 'unpaid' AND cancelled_at IS NULL AND total > 100 ORDER BY placed_at ASC LIMIT 1",
            )->fetch();
            $this->assertIsArray($unpaid, 'the seed has an unpaid order to part-pay');
            $this->shop->services->orders()->recordPayment((string) $unpaid['id'], ['method' => 'cash', 'amount' => '50.00'], null);
            $expected = 'Partly paid (₱50.00 of ' . \Gfh\Core\Support\Format::peso((string) $unpaid['total']) . ')';
            $this->assertStringContainsString($expected, $page("/$slug/admin/orders/" . $unpaid['id']));
            $this->assertStringContainsString($expected, $page("/$slug/admin/orders"));
            $this->assertStringContainsString('Amount (₱)', $page("/$slug/admin/orders/" . $unpaid['id']));
            $this->assertSame('unpaid', (string) $this->shop->scalar('SELECT payment_status FROM orders WHERE id = ?', [$unpaid['id']]));
        } finally {
            restore_error_handler();
            restore_exception_handler();
        }
    }
}
