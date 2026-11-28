<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

use Gfh\Core\App;
use Gfh\Core\Auth\Csrf;
use Gfh\Core\Auth\PasswordPolicy;
use Gfh\Core\Auth\Session;
use Gfh\Core\Database\Database;
use Gfh\Core\Http\Request;
use Gfh\Core\Repository\CustomerAccountRepository;
use Gfh\Core\Repository\CustomerAddressRepository;
use Gfh\Core\Repository\LoginAttemptRepository;
use Gfh\Core\Repository\MembershipRepository;
use Gfh\Core\Repository\OrganizationRepository;
use Gfh\Core\Repository\PasswordResetRepository;
use Gfh\Core\Repository\PermissionRepository;
use Gfh\Core\Repository\SuperAdminAuditRepository;
use Gfh\Core\Repository\SuperAdminOrganizationRepository;
use Gfh\Core\Repository\SuperAdminUserRepository;
use Gfh\Core\Repository\UserRepository;
use Gfh\Core\Service\AccountService;
use Gfh\Core\Service\AdminNavigationService;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Service\AuthService;
use Gfh\Core\Service\CustomerAccountService;
use Gfh\Core\Service\PasswordResetService;
use Gfh\Core\Service\PermissionService;
use Gfh\Core\Service\ShopAccountMenuService;
use Gfh\Core\Service\ShopNavigationService;
use Gfh\Core\Service\SuperAdminAuditQueryService;
use Gfh\Core\Service\SuperAdminAuthService;
use Gfh\Core\Service\SuperAdminNavigationService;
use Gfh\Core\Service\SuperAdminOrganizationQueryService;
use Gfh\Core\Service\SuperAdminOverviewService;
use Gfh\Core\Service\SuperAdminUserQueryService;
use Gfh\Core\Service\SuperAdminUserService;
use Gfh\Core\Extension\ExtensionLoader;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Tenancy\Tenant;
use Gfh\Core\Tenancy\TenantResolver;
use Gfh\Core\View\AppView;
use Gfh\Core\View\AssetUrls;
use Gfh\Core\View\SuperAdminAssetUrls;
use Gfh\Core\View\SuperAdminView;
use Gfh\Core\View\TemplateLocator;

/**
 * Builds services on first use and hands out the same instance afterwards: a small, explicit service locator.
 * Web requests use the limited core role. bin/ scripts pass $privileged = true to use the migrator role,
 * because they create accounts and write the registry, which web requests may not.
 */
final class Services
{
    /** @var array<string, object> */
    private array $instances = [];
    /** @var array<string, array{loaded: list<string>, failed: array<string, string>}> */
    private array $extensionReports = [];

    /**
     * @param array<string, Session>|null    $testSessions         test-only in-memory sessions keyed by scope ('staff', 'customer')
     * @param (\Closure(): \PDO)|null        $orgConnectionFactory optional test-only factory invoked if an org database is ever requested
     */
    public function __construct(
        private readonly App $app,
        private readonly bool $privileged = false,
        private readonly ?\PDO $coreConnection = null,
        private readonly ?\PDO $orgConnection = null,
        private readonly ?string $sessionPath = null,
        private readonly ?array $testSessions = null,
        private readonly ?\Closure $orgConnectionFactory = null,
    ) {
    }

    public function app(): App
    {
        return $this->app;
    }

    public function database(): Database
    {
        return $this->once('database', fn (): Database => new Database($this->app->config()));
    }

    public function core(): \PDO
    {
        if ($this->coreConnection !== null) {
            return $this->coreConnection;
        }

        return $this->privileged ? $this->database()->coreMigrator() : $this->database()->core();
    }

    public function passwords(): PasswordPolicy
    {
        return $this->once('passwords', static fn (): PasswordPolicy => new PasswordPolicy());
    }

    public function organizations(): OrganizationRepository
    {
        return $this->once('organizations', fn (): OrganizationRepository => new OrganizationRepository($this->core()));
    }

    public function users(): UserRepository
    {
        return $this->once('users', fn (): UserRepository => new UserRepository($this->core()));
    }

    public function customers(): CustomerAccountRepository
    {
        return $this->once('customers', fn (): CustomerAccountRepository => new CustomerAccountRepository($this->core()));
    }

    public function audit(): AuditService
    {
        return $this->once('audit', fn (): AuditService => new AuditService($this->core()));
    }

    public function permissions(): PermissionService
    {
        return $this->once('permissions', fn (): PermissionService => new PermissionService(
            new MembershipRepository($this->core()),
            new PermissionRepository($this->core()),
        ));
    }

    public function auth(): AuthService
    {
        return $this->once('auth', fn (): AuthService => new AuthService(
            $this->users(),
            $this->customers(),
            new LoginAttemptRepository($this->core()),
            $this->audit(),
            $this->passwords(),
            $this->app->config(),
        ));
    }

    /** A signed-in customer's own profile, addresses and password. Core data, so it is not per organization. */
    public function customerAccounts(): CustomerAccountService
    {
        return $this->once('customer-accounts', fn (): CustomerAccountService => new CustomerAccountService(
            $this->core(),
            $this->customers(),
            new CustomerAddressRepository($this->core()),
            $this->auth(),
            $this->audit(),
            $this->passwords(),
        ));
    }

    public function accounts(): AccountService
    {
        return $this->once('accounts', fn (): AccountService => new AccountService(
            $this->core(),
            $this->users(),
            new MembershipRepository($this->core()),
            $this->permissions(),
            $this->audit(),
            $this->passwords(),
        ));
    }

    public function resets(): PasswordResetService
    {
        return $this->once('resets', fn (): PasswordResetService => new PasswordResetService(
            $this->core(),
            new PasswordResetRepository($this->core()),
            $this->users(),
            $this->customers(),
            $this->accounts(),
            $this->permissions(),
            $this->audit(),
            $this->passwords(),
            $this->app->config(),
        ));
    }

    public function tenants(): TenantResolver
    {
        return $this->once('tenants', function (): TenantResolver {
            $connection = $this->orgConnectionFactory !== null
                ? ($this->orgConnectionFactory)()
                : $this->orgConnection;

            return new TenantResolver(
                $this->organizations(),
                $this->permissions(),
                $this->database(),
                $connection,
            );
        });
    }

    /**
     * A hook registry for code that is not tied to one organization. Each organization has its own registry
     * (see forTenant), so an organization-local extension can never affect another organization.
     */
    public function hooks(): Hooks
    {
        return $this->once('hooks', fn (): Hooks => new Hooks($this->hookLog()));
    }

    /**
     * The business services of the organization a request is about. The first call for an organization also loads its
     * extensions into that organization's own hook registry and settings; a broken extension is logged and skipped.
     */
    public function forTenant(Tenant $tenant): TenantServices
    {
        $slug = $tenant->organization->slug;

        return $this->once('tenant:' . $slug, function () use ($tenant, $slug): TenantServices {
            $hooks = new Hooks($this->hookLog($slug));
            $services = new TenantServices(
                $tenant->database(),
                new AuditService($this->core()),
                $hooks,
                $this->app->path('storage/uploads'),
                $slug,
                permissions: $this->permissions(),
            );

            $logger = $this->app->logger();
            $loader = new ExtensionLoader(
                $this->app->path(),
                static function (string $message, array $context) use ($logger): void {
                    $logger->error($message, $context);
                },
            );
            $this->extensionReports[$slug] = $loader->load(
                $slug,
                $hooks,
                $services->settings(),
                $this->organizations()->enabledFeatureKeys($tenant->organization->id),
            );

            return $services;
        });
    }

    /**
     * Where a failing hook callback is reported: the application log, at error level, with the organization named.
     * The Hooks registry builds the message from the hook name, the extension and the exception's class and cleaned
     * message; the hook's value and context are never logged.
     *
     * @return \Closure(string, array<string, mixed>): void
     */
    private function hookLog(?string $slug = null): \Closure
    {
        $logger = $this->app->logger();

        return static function (string $message, array $context) use ($logger, $slug): void {
            $logger->error($message, $slug === null ? $context : $context + ['organization' => $slug]);
        };
    }

    /**
     * What happened when an organization's extensions were loaded in this request, or null before forTenant ran.
     *
     * @return array{loaded: list<string>, failed: array<string, string>}|null
     */
    public function extensionReport(string $organizationSlug): ?array
    {
        return $this->extensionReports[$organizationSlug] ?? null;
    }

    /**
     * The view for one app (admin, shop or pos) of the organization a request is about. Every include follows
     * orgs/{slug}/{app}/... first, then templates/{app}/.... The values every layout needs are shared with all views.
     *
     * @param array<string, mixed> $extra more shared values, for example the navigation items
     */
    public function appView(Tenant $tenant, string $app, Request $request, array $extra = []): AppView
    {
        $slug = $tenant->organization->slug;
        $basePath = $request->basePath();

        return new AppView(new TemplateLocator($this->app->path()), $slug, $app, $extra + [
            'organizationName' => $tenant->organization->name,
            'organizationShortName' => $tenant->organization->shortName,
            'slug' => $slug,
            'basePath' => $basePath,
            'homeUrl' => $basePath . '/' . $slug . '/' . $app,
            'assets' => new AssetUrls($this->app->path('public'), $basePath, $slug, $app),
        ]);
    }

    /**
     * The Admin sidebar builder for one organization: permission checks plus that organization's own
     * navigation.admin hook callbacks. The UI kit link is offered everywhere except production.
     */
    public function adminNavigation(Tenant $tenant): AdminNavigationService
    {
        return $this->once('admin-navigation:' . $tenant->organization->slug, fn (): AdminNavigationService => new AdminNavigationService(
            $this->permissions(),
            $this->forTenant($tenant)->hooks(),
            !$this->app->config()->isProduction(),
        ));
    }

    /** The Shop header navigation for one organization: the Shop link plus that organization's own navigation.shop hook callbacks. */
    public function shopNavigation(Tenant $tenant): ShopNavigationService
    {
        return $this->once('shop-navigation:' . $tenant->organization->slug, fn (): ShopNavigationService => new ShopNavigationService(
            $this->forTenant($tenant)->hooks(),
        ));
    }

    /** The customer account menu for one organization: the built-in items plus that organization's own account.menu hook callbacks. */
    public function shopAccountMenu(Tenant $tenant): ShopAccountMenuService
    {
        return $this->once('shop-account-menu:' . $tenant->organization->slug, fn (): ShopAccountMenuService => new ShopAccountMenuService(
            $this->forTenant($tenant)->hooks(),
        ));
    }

    /** Group-level sign-in guard for /superadmin. Never opens an organization database. */
    public function superAdminAuth(): SuperAdminAuthService
    {
        return $this->once('superadmin-auth', fn (): SuperAdminAuthService => new SuperAdminAuthService(
            $this->auth(),
            $this->permissions(),
        ));
    }

    /** Super Admin sidebar builder driven by Modules::groupAllows and PermissionService::can. */
    public function superAdminNavigation(): SuperAdminNavigationService
    {
        return $this->once('superadmin-navigation', fn (): SuperAdminNavigationService => new SuperAdminNavigationService(
            $this->permissions(),
        ));
    }

    /** Read-only Super Admin organization registry queries (core DB only; never reads connection_encrypted). */
    public function superAdminOrganizationQueries(): SuperAdminOrganizationQueryService
    {
        return $this->once('superadmin-organizations', fn (): SuperAdminOrganizationQueryService => new SuperAdminOrganizationQueryService(
            new SuperAdminOrganizationRepository($this->core()),
            $this->permissions(),
        ));
    }

    /** Read-only Super Admin Overview queries (core DB only). */
    public function superAdminOverview(): SuperAdminOverviewService
    {
        return $this->once('superadmin-overview', fn (): SuperAdminOverviewService => new SuperAdminOverviewService(
            new SuperAdminOrganizationRepository($this->core()),
            new SuperAdminAuditRepository($this->core()),
            $this->permissions(),
        ));
    }

    /** Read-only Super Admin audit log queries (core DB only). */
    public function superAdminAuditQueries(): SuperAdminAuditQueryService
    {
        return $this->once('superadmin-audit', fn (): SuperAdminAuditQueryService => new SuperAdminAuditQueryService(
            new SuperAdminAuditRepository($this->core()),
            $this->permissions(),
        ));
    }

    /** Read-only Super Admin Users and Access queries (core DB only; never selects password_hash or connection_encrypted). */
    public function superAdminUserQueries(): SuperAdminUserQueryService
    {
        return $this->once('superadmin-user-queries', fn (): SuperAdminUserQueryService => new SuperAdminUserQueryService(
            new SuperAdminUserRepository($this->core()),
            $this->permissions(),
        ));
    }

    /** Super Admin Users and Access write coordinator (delegates account and reset rules to AccountService/PasswordResetService). */
    public function superAdminUsers(): SuperAdminUserService
    {
        return $this->once('superadmin-users', fn (): SuperAdminUserService => new SuperAdminUserService(
            new SuperAdminUserRepository($this->core()),
            $this->accounts(),
            $this->resets(),
            $this->permissions(),
        ));
    }

    /**
     * The view for the Super Admin console. Resolves only inside templates/superadmin/.
     *
     * @param array<string, mixed> $extra
     */
    public function superAdminView(Request $request, array $extra = []): SuperAdminView
    {
        $basePath = $request->basePath();

        return new SuperAdminView($this->app->path('templates/superadmin'), $extra + [
            'basePath' => $basePath,
            'homeUrl' => $basePath . '/superadmin',
            'assets' => new SuperAdminAssetUrls($basePath),
        ]);
    }

    /** The session for one kind of person. Secure cookies on HTTPS and always in production. */
    public function session(string $scope, Request $request): Session
    {
        if ($this->testSessions !== null && isset($this->testSessions[$scope])) {
            return $this->testSessions[$scope];
        }

        return new Session(
            $scope,
            $this->app->config(),
            $this->sessionPath ?? $this->app->path('storage/sessions'),
            $request->isSecure() || $this->app->config()->isProduction(),
        );
    }

    public function csrf(Session $session): Csrf
    {
        return new Csrf($session);
    }

    /**
     * @template T of object
     * @param callable(): T $make
     * @return T
     */
    private function once(string $key, callable $make): object
    {
        return $this->instances[$key] ??= $make();
    }
}
