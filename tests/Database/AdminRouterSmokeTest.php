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
        } finally {
            restore_error_handler();
            restore_exception_handler();
        }
    }
}
