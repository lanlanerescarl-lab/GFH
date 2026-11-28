<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\App;
use Gfh\Core\Auth\Modules;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Middleware\CsrfMiddleware;
use Gfh\Core\Http\Middleware\RequireGroupMiddleware;
use Gfh\Core\Http\Middleware\SessionMiddleware;
use Gfh\Core\Http\Middleware\TenantMiddleware;
use Gfh\Core\Repository\MembershipRepository;
use Gfh\Core\Repository\PermissionRepository;
use Gfh\Core\Routing\Route;
use Gfh\Core\Routing\Router;
use Gfh\Core\Service\PermissionService;
use Gfh\Core\Service\SuperAdminNavigationService;
use Gfh\Core\Support\SuperAdminRedirect;
use Gfh\Core\View\AssetPublisher;
use Gfh\Core\View\SuperAdminAssetUrls;
use Gfh\Core\View\SuperAdminView;
use Gfh\Tests\Support\TemplateTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Batch SA.1a unit tests: route table security, safe next redirects, sidebar navigation,
 * SuperAdminView/SuperAdminAssetUrls, AssetPublisher coverage, token-only CSS, and no external hosts.
 */
final class SuperAdminFoundationTest extends TestCase
{
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

    /** @return list<Route> */
    private function superAdminRoutes(): array
    {
        return array_values(array_filter(
            $this->routes(),
            static fn (Route $route): bool => $route->pattern === '/superadmin' || str_starts_with($route->pattern, '/superadmin/'),
        ));
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

    private function permissionServiceWithoutDatabase(): PermissionService
    {
        // Group-level permission checks never touch PDO or any repository.
        $pdo = (new \ReflectionClass(\PDO::class))->newInstanceWithoutConstructor();

        return new PermissionService(new MembershipRepository($pdo), new PermissionRepository($pdo));
    }

    public function testSuperAdminRoutesAreExactlyTheDocumentedSetAndOnlySignInAndSignOutArePost(): void
    {
        $found = array_map(
            static fn (Route $route): string => $route->method . ' ' . $route->pattern,
            $this->superAdminRoutes(),
        );
        sort($found);

        $expected = [
            'GET /superadmin',
            'GET /superadmin/audit',
            'GET /superadmin/organizations',
            'GET /superadmin/reports',
            'GET /superadmin/settings',
            'GET /superadmin/sign-in',
            'GET /superadmin/users',
            'POST /superadmin/sign-in',
            'POST /superadmin/sign-out',
        ];
        $this->assertSame($expected, $found);

        $nonGet = array_values(array_filter(
            $this->superAdminRoutes(),
            static fn (Route $route): bool => $route->method !== 'GET',
        ));
        $nonGetLabels = array_map(static fn (Route $r): string => $r->method . ' ' . $r->pattern, $nonGet);
        sort($nonGetLabels);
        $this->assertSame(['POST /superadmin/sign-in', 'POST /superadmin/sign-out'], $nonGetLabels);
    }

    public function testEverySuperAdminRouteUsesStaffSessionNeverTenantAndProtectsWritesWithCsrf(): void
    {
        foreach ($this->superAdminRoutes() as $route) {
            $label = $route->method . ' ' . $route->pattern;
            $this->assertSame('staff', $this->sessionScope($route), "$label must use the staff session");
            $this->assertFalse($this->carries($route, TenantMiddleware::class), "$label must never load a tenant");

            $isAuthRoute = in_array($route->pattern, ['/superadmin/sign-in', '/superadmin/sign-out'], true);
            if ($isAuthRoute) {
                $this->assertFalse($this->carries($route, RequireGroupMiddleware::class), "$label must not block sign-in/out");
            } else {
                $this->assertTrue($this->carries($route, RequireGroupMiddleware::class), "$label must require a group role");
            }

            if ($route->method !== 'GET') {
                $this->assertTrue($this->carries($route, CsrfMiddleware::class), "$label must carry CsrfMiddleware");
            }
        }
    }

    public function testSuperAdminRoutesAreRegisteredBeforeOrganizationRoutesAndOrgRegexExcludesSuperadmin(): void
    {
        $routes = $this->routes();
        $lastSuperAdmin = -1;
        $firstOrg = PHP_INT_MAX;

        foreach ($routes as $index => $route) {
            if ($route->pattern === '/superadmin' || str_starts_with($route->pattern, '/superadmin/')) {
                $lastSuperAdmin = $index;
            }
            if (str_starts_with($route->pattern, '/{org:')) {
                $firstOrg = min($firstOrg, $index);
                $this->assertSame(0, preg_match($route->regex, '/superadmin/admin'), 'org route must never match /superadmin/admin: ' . $route->pattern);
                $this->assertSame(0, preg_match($route->regex, '/superadmin/shop'), 'org route must never match /superadmin/shop: ' . $route->pattern);
            }
        }

        $this->assertGreaterThan(-1, $lastSuperAdmin);
        $this->assertLessThan($firstOrg, $lastSuperAdmin);
    }

    /** @return array<string, array{mixed, string, ?string}> */
    public static function redirectCases(): array
    {
        return [
            'root' => ['/superadmin', '/superadmin', '/superadmin'],
            'organizations' => ['/superadmin/organizations', '/superadmin', '/superadmin/organizations'],
            'audit with query' => ['/superadmin/audit?page=2&action=user.created', '/superadmin', '/superadmin/audit?page=2&action=user.created'],
            'with install base path' => ['/gfh/superadmin/reports', '/gfh/superadmin', '/gfh/superadmin/reports'],
            'empty' => ['', '/superadmin', null],
            'non-string' => [['/superadmin'], '/superadmin', null],
            'sign-in loop' => ['/superadmin/sign-in', '/superadmin', null],
            'sign-in with query' => ['/superadmin/sign-in?next=%2Fsuperadmin', '/superadmin', null],
            'sign-out loop' => ['/superadmin/sign-out', '/superadmin', null],
            'different app' => ['/demo/admin', '/superadmin', null],
            'prefix lookalike' => ['/superadmin-other/page', '/superadmin', null],
            'external https' => ['https://evil.example/superadmin', '/superadmin', null],
            'protocol relative' => ['//evil.example/superadmin', '/superadmin', null],
            'double slash' => ['/superadmin//evil', '/superadmin', null],
            'dot segment' => ['/superadmin/../demo/admin', '/superadmin', null],
            'encoded dot segment' => ['/superadmin/%2e%2e/demo/admin', '/superadmin', null],
            'encoded backslash' => ['/superadmin/%5cevil', '/superadmin', null],
            'double encoded slash' => ['/superadmin/%252f%252fevil', '/superadmin', null],
            'too long' => ['/superadmin/' . str_repeat('a', 300), '/superadmin', null],
        ];
    }

    #[DataProvider('redirectCases')]
    public function testSafeNextAcceptsOnlyPathsInsideSuperAdmin(mixed $candidate, string $base, ?string $expected): void
    {
        $this->assertSame($expected, SuperAdminRedirect::safeNext($candidate, $base));
    }

    public function testSidebarListsAllSixPagesInOrderForGroupRolesAndMarksCurrentPage(): void
    {
        $nav = new SuperAdminNavigationService($this->permissionServiceWithoutDatabase());
        $expectedLabels = ['Overview', 'Organizations', 'Consolidated reports', 'Users and access', 'Audit log', 'System settings'];
        $expectedUrls = [
            '/superadmin',
            '/superadmin/organizations',
            '/superadmin/reports',
            '/superadmin/users',
            '/superadmin/audit',
            '/superadmin/settings',
        ];

        foreach (Modules::GROUP_ROLES as $role) {
            $user = new StaffUser('00000000-0000-4000-8000-000000000001', 'Group User', $role . '@example.test', $role, 'active');
            $items = $nav->items($user, '', '/superadmin/organizations');

            $this->assertSame($expectedLabels, array_column($items, 'label'), $role);
            $this->assertSame($expectedUrls, array_column($items, 'url'), $role);
            $this->assertSame([false, true, false, false, false, false], array_column($items, 'current'), $role);

            $overviewItems = $nav->items($user, '', '/superadmin');
            $this->assertSame([true, false, false, false, false, false], array_column($overviewItems, 'current'), $role);
        }
    }

    public function testSidebarIsEmptyAndForPageThrows403ForNonGroupInactiveOrUnknownModule(): void
    {
        $nav = new SuperAdminNavigationService($this->permissionServiceWithoutDatabase());

        $orgStaff = new StaffUser('00000000-0000-4000-8000-000000000002', 'Org Admin', 'org@example.test', null, 'active');
        $inactive = new StaffUser('00000000-0000-4000-8000-000000000003', 'Inactive', 'off@example.test', 'super_admin', 'inactive');
        $unknownRole = new StaffUser('00000000-0000-4000-8000-000000000004', 'Odd', 'odd@example.test', 'other_role', 'active');

        $this->assertSame([], $nav->items($orgStaff, '', '/superadmin'));
        $this->assertSame([], $nav->items($inactive, '', '/superadmin'));
        $this->assertSame([], $nav->items($unknownRole, '', '/superadmin'));

        foreach ([$orgStaff, $inactive, $unknownRole] as $blocked) {
            try {
                $nav->forPage($blocked, 'overview', '', '/superadmin');
                $this->fail('Expected 403 for ' . $blocked->email);
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->status());
            }
        }

        $superAdmin = new StaffUser('00000000-0000-4000-8000-000000000001', 'Super', 'sa@example.test', 'super_admin', 'active');
        try {
            $nav->forPage($superAdmin, 'nonexistent_module', '', '/superadmin');
            $this->fail('Expected 403 for unknown module');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->status());
        }
    }

    public function testSuperAdminViewAndAssetUrlsEscapeAndRejectTraversal(): void
    {
        $tree = new TemplateTree();
        try {
            $tree->put('templates/superadmin/layout.php', '[<?= $this->section("title", "Default") ?>|<?= $this->section("content") ?>]');
            $tree->put('templates/superadmin/sample.php', '<?php $this->layout("layout"); $this->start("title"); echo $this->e("A & B"); $this->end(); ?>Hi <?= $this->e($name) ?>');
            $tree->put('secret.php', 'top secret');

            $view = new SuperAdminView($tree->root . '/templates/superadmin');
            $this->assertSame('[A &amp; B|Hi &lt;script&gt;]', $view->render('sample', ['name' => '<script>']));

            try {
                $view->render('../secret');
                $this->fail('Path traversal must be refused.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }

            $assets = new SuperAdminAssetUrls('/gfh');
            $this->assertSame('/gfh/assets/superadmin/css/app.css', $assets->url('css/app.css'));
            $this->assertSame('/gfh/assets/admin/css/tokens.css', $assets->admin('css/tokens.css'));
            $this->assertSame('/gfh/assets/css/tokens.css', $assets->shared('css/tokens.css'));

            $this->expectException(\InvalidArgumentException::class);
            $assets->url('../secret.css');
        } finally {
            $tree->remove();
        }
    }

    public function testAssetPublisherPublishesSuperAdminAssetsAndCleansStaleFiles(): void
    {
        $tree = new TemplateTree();
        try {
            $tree->put('templates/superadmin/assets/css/app.css', 'sa css');
            $tree->put('templates/superadmin/assets/js/shell.js', 'sa js');
            $tree->put('public/assets/superadmin/css/old.css', 'stale');

            $result = (new AssetPublisher($tree->root))->publish();

            $this->assertSame(2, $result['copied']);
            $this->assertSame('sa css', file_get_contents($tree->root . '/public/assets/superadmin/css/app.css'));
            $this->assertSame('sa js', file_get_contents($tree->root . '/public/assets/superadmin/js/shell.js'));
            $this->assertFileDoesNotExist($tree->root . '/public/assets/superadmin/css/old.css');
        } finally {
            $tree->remove();
        }
    }

    public function testPublishedSuperAdminAssetsMatchSourcesUseTokensOnlyAndNameNoExternalHost(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['css/app.css', 'js/shell.js'] as $file) {
            $source = $root . '/templates/superadmin/assets/' . $file;
            $published = $root . '/public/assets/superadmin/' . $file;
            $this->assertFileExists($source);
            $this->assertFileExists($published, 'Run php bin/publish-assets.php');
            $this->assertFileEquals($source, $published, 'public/assets/superadmin/' . $file . ' is out of date.');
        }

        $css = (string) file_get_contents($root . '/templates/superadmin/assets/css/app.css');
        $this->assertSame(0, preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $css), 'Super Admin CSS must use CSS variables only.');

        $js = (string) file_get_contents($root . '/templates/superadmin/assets/js/shell.js');
        $this->assertStringNotContainsString('innerHTML', $js, 'Super Admin JS must not use innerHTML.');

        $findings = NoExternalHostsTest::scan($root, ['templates/superadmin', 'public/assets/superadmin']);
        $this->assertSame([], $findings, "External hosts found:\n" . implode("\n", $findings));
    }
}
