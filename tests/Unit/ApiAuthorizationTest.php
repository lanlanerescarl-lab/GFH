<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * "Every endpoint checks can()": a structural guard. Each public endpoint method of an API controller must take its
 * services from authorize() (staff, with can() checked), customerContext() (a signed-in customer) or publicServices()
 * (anonymous catalog reads and media), and the Admin and POS controllers may use authorize() only.
 */
final class ApiAuthorizationTest extends TestCase
{
    private const ADMIN_ONLY = ['CatalogAdminController', 'OrderAdminController', 'InventoryAdminController', 'ProcurementAdminController', 'PosApiController', 'ReportController'];
    /** The only controller methods that may serve anonymous or customer requests. */
    private const OPEN_METHODS = [
        'CatalogController' => ['products', 'filters', 'product', 'categories', 'brands'],
        'CartController' => ['options', 'price', 'checkout', 'myOrders', 'myOrder'],
        'MediaController' => ['serve'],
    ];

    /** @return array<string, array<string, string>> controller => method => body */
    private function endpoints(): array
    {
        $result = [];
        foreach (glob(dirname(__DIR__, 2) . '/core/Controller/Api/*Controller.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if ($name === 'ApiController') {
                continue;
            }
            preg_match_all('/\n    public function (\w+)\(Request \$request\): Response\n    \{(.*?)\n    \}\n/s', (string) file_get_contents($file), $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $result[$name][$m[1]] = $m[2];
            }
        }

        return $result;
    }

    public function testTheScannerFindsTheEndpoints(): void
    {
        $endpoints = $this->endpoints();

        $this->assertArrayHasKey('CatalogAdminController', $endpoints);
        $this->assertGreaterThan(40, array_sum(array_map('count', $endpoints)));
    }

    public function testEveryAdminAndPosEndpointChecksPermission(): void
    {
        foreach ($this->endpoints() as $controller => $methods) {
            foreach ($methods as $method => $body) {
                if (in_array($controller, self::ADMIN_ONLY, true)) {
                    $this->assertStringContainsString('$this->authorize($request,', $body, "$controller::$method must call authorize()");
                    $this->assertStringNotContainsString('publicServices(', $body, "$controller::$method must not use public services");
                    $this->assertStringNotContainsString('customerContext(', $body, "$controller::$method must not use customer services");
                }
            }
        }
    }

    public function testOnlyTheKnownOpenEndpointsAreOpen(): void
    {
        foreach ($this->endpoints() as $controller => $methods) {
            foreach ($methods as $method => $body) {
                $usesOpen = str_contains($body, 'publicServices(') || str_contains($body, 'customerContext(');
                $mayBeOpen = in_array($method, self::OPEN_METHODS[$controller] ?? [], true);

                if ($usesOpen) {
                    $this->assertTrue($mayBeOpen, "$controller::$method is open to non-staff but is not on the approved list");
                } else {
                    $this->assertStringContainsString('$this->authorize($request,', $body, "$controller::$method must check permission");
                }
            }
        }
    }

    public function testNoControllerBuildsTenantServicesByItself(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/core/Controller/Api/*Controller.php') ?: [] as $file) {
            if (basename($file) === 'ApiController.php') {
                continue;
            }
            $this->assertStringNotContainsString('forTenant(', (string) file_get_contents($file), basename($file) . ' must get services through ApiController');
        }
    }

    public function testAdminAndPosRoutesAllRunBehindTheStaffMiddleware(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/core/routes.php');
        $checked = 0;

        foreach (explode("\n", $routes) as $line) {
            if (str_contains($line, '/api/v1/admin/') || str_contains($line, '/api/v1/pos/')) {
                $this->assertStringContainsString('$staff)', $line, 'Route without the staff middleware: ' . trim($line));
                $checked++;
            }
        }

        $this->assertGreaterThan(40, $checked);
    }
}
