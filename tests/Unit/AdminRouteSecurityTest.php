<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\App;
use Gfh\Core\Http\Middleware\CsrfMiddleware;
use Gfh\Core\Http\Middleware\RequireCustomerMiddleware;
use Gfh\Core\Http\Middleware\RequireStaffMiddleware;
use Gfh\Core\Http\Middleware\SessionMiddleware;
use Gfh\Core\Http\Middleware\TenantMiddleware;
use Gfh\Core\Routing\Route;
use Gfh\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Reads the routes that core/routes.php really registers (no source-text matching) and checks that every Admin
 * route that changes state carries the CSRF middleware, and every Admin route needs a signed-in staff member. The
 * same is checked for the customer account routes (/api/v1/account/...): customer session, signed-in customer, and
 * CSRF on every write.
 * Nothing here touches a database: building the routes opens no connection.
 */
final class AdminRouteSecurityTest extends TestCase
{
    /** Admin pages a signed-out visitor must reach, because they sign the person in or out. */
    private const OPEN_ADMIN_PATHS = ['/admin/login', '/admin/logout'];

    /** @return list<Route> */
    private function routes(): array
    {
        $root = dirname(__DIR__, 2);
        $app = App::boot($root);
        restore_error_handler();
        restore_exception_handler();

        $router = new Router();
        (require $root . '/core/routes.php')($router, $app);

        /** @var list<Route> $routes */
        $routes = (new \ReflectionProperty(Router::class, 'routes'))->getValue($router);

        return $routes;
    }

    private function carries(Route $route, string $class): bool
    {
        foreach ($route->middleware as $middleware) {
            if ((new \ReflectionFunction($middleware))->getClosureThis() instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function isAdmin(Route $route): bool
    {
        return str_contains($route->pattern, '/admin');
    }

    public function testEveryStateChangingAdminRouteRequiresTheCsrfToken(): void
    {
        $checked = 0;
        $missing = [];
        foreach ($this->routes() as $route) {
            if (!$this->isAdmin($route) || in_array($route->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                continue;
            }
            $checked++;
            if (!$this->carries($route, CsrfMiddleware::class)) {
                $missing[] = $route->method . ' ' . $route->pattern;
            }
        }

        $this->assertGreaterThan(30, $checked, 'The route scan must actually find the Admin routes.');
        $this->assertSame([], $missing, 'These state-changing Admin routes have no CsrfMiddleware.');
    }

    public function testEveryAdminRouteExceptSignInAndOutRequiresASignedInStaffMember(): void
    {
        $missing = [];
        foreach ($this->routes() as $route) {
            if (!$this->isAdmin($route)) {
                continue;
            }
            $path = (string) preg_replace('#^/\{org:[^}]*\}#', '', $route->pattern);
            if (in_array($path, self::OPEN_ADMIN_PATHS, true) || str_starts_with($path, '/api/v1/pos')) {
                continue;
            }
            if (!$this->carries($route, RequireStaffMiddleware::class)) {
                $missing[] = $route->method . ' ' . $route->pattern;
            }
        }

        $this->assertSame([], $missing, 'These Admin routes can be reached without a signed-in staff member.');
    }

    /** @return list<Route> */
    private function accountRoutes(): array
    {
        return array_values(array_filter(
            $this->routes(),
            static fn (Route $route): bool => str_contains($route->pattern, '/api/v1/account/'),
        ));
    }

    /** The scope ('staff' or 'customer') of the SessionMiddleware on a route, or null when it has none. */
    private function sessionScope(Route $route): ?string
    {
        foreach ($route->middleware as $middleware) {
            $owner = (new \ReflectionFunction($middleware))->getClosureThis();
            if ($owner instanceof SessionMiddleware) {
                return (string) (new \ReflectionProperty(SessionMiddleware::class, 'scope'))->getValue($owner);
            }
        }

        return null;
    }

    public function testTheAccountRoutesAreExactlyTheEightThatAreDocumented(): void
    {
        $found = array_map(
            static fn (Route $route): string => $route->method . ' ' . (string) preg_replace('#^/\{org:[^}]*\}/api/v1/account#', '', $route->pattern),
            $this->accountRoutes(),
        );
        sort($found);

        $expected = [
            'DELETE /addresses/{id}', 'GET /addresses', 'GET /profile', 'POST /addresses', 'POST /addresses/{id}/default',
            'POST /password', 'PUT /addresses/{id}', 'PUT /profile',
        ];
        $this->assertSame($expected, $found);
    }

    public function testEveryAccountRouteNeedsTheTenantTheCustomerSessionAndASignedInCustomer(): void
    {
        foreach ($this->accountRoutes() as $route) {
            $label = $route->method . ' ' . $route->pattern;
            $this->assertTrue($this->carries($route, TenantMiddleware::class), "$label needs the tenant");
            $this->assertSame('customer', $this->sessionScope($route), "$label must use the customer session, not the staff one");
            $this->assertTrue($this->carries($route, RequireCustomerMiddleware::class), "$label needs a signed-in customer");
            $this->assertFalse($this->carries($route, RequireStaffMiddleware::class), "$label must not accept a staff member");
        }
    }

    public function testEveryStateChangingAccountRouteRequiresTheCsrfToken(): void
    {
        $writes = 0;
        foreach ($this->accountRoutes() as $route) {
            if (in_array($route->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                continue;
            }
            $writes++;
            $this->assertTrue($this->carries($route, CsrfMiddleware::class), $route->method . ' ' . $route->pattern . ' has no CsrfMiddleware');
        }

        $this->assertSame(6, $writes);
    }
}
