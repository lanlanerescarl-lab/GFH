<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Payments\ManualPaymentProvider;
use Gfh\Core\Payments\PaymentProvider;
use Gfh\Core\Repository\BranchRepository;
use Gfh\Core\Repository\CatalogRepository;
use Gfh\Core\Repository\InventoryRepository;
use Gfh\Core\Repository\MediaRepository;
use Gfh\Core\Repository\OrderRepository;
use Gfh\Core\Repository\OrganizationCustomerRepository;
use Gfh\Core\Repository\PosRepository;
use Gfh\Core\Repository\ProcurementRepository;
use Gfh\Core\Repository\ReportRepository;
use Gfh\Core\Repository\SettingsRepository;
use Gfh\Core\Repository\TerminalRepository;
use Gfh\Core\Repository\DiscountRuleRepository;
use Gfh\Core\Repository\OrderStepRepository;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Service\BranchService;
use Gfh\Core\Service\CatalogService;
use Gfh\Core\Service\InventoryService;
use Gfh\Core\Service\MediaService;
use Gfh\Core\Service\OrderService;
use Gfh\Core\Service\PermissionService;
use Gfh\Core\Service\PosService;
use Gfh\Core\Service\PricingService;
use Gfh\Core\Service\ProcurementService;
use Gfh\Core\Service\CustomerAccessService;
use Gfh\Core\Service\ShopBrowseService;
use Gfh\Core\Service\ShopOrderService;
use Gfh\Core\Service\ReportService;
use Gfh\Core\Service\SettingsService;
use Gfh\Core\Service\TerminalService;
use Gfh\Core\Service\DiscountRuleService;
use Gfh\Core\Service\OrderStepService;
use Gfh\Core\Service\OrganizationSettingsService;

/**
 * The business services of ONE organization, all built on that organization's database connection.
 * The Shop, POS and Admin all get their services here, which is why there is one implementation of
 * pricing, VAT, stock, costing and orders. Services are built on first use.
 */
final class TenantServices
{
    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(
        private readonly \PDO $organization,
        private readonly AuditService $audit,
        private readonly Hooks $hooks,
        private readonly string $uploadRoot,
        private readonly string $organizationSlug,
        private readonly ?PaymentProvider $paymentProvider = null,
        private readonly ?PermissionService $permissions = null,
    ) {
    }

    public function hooks(): Hooks
    {
        return $this->hooks;
    }

    public function connection(): \PDO
    {
        return $this->organization;
    }

    public function settings(): SettingsService
    {
        return $this->once('settings', fn (): SettingsService => new SettingsService(new SettingsRepository($this->organization), $this->audit, $this->organization));
    }

    public function catalog(): CatalogService
    {
        return $this->once('catalog', fn (): CatalogService => new CatalogService($this->organization, new CatalogRepository($this->organization), $this->audit, $this->hooks));
    }

    /** What the public Shop pages show; it only combines CatalogService calls and runs the Shop's hook. */
    public function shop(): ShopBrowseService
    {
        return $this->once('shop', fn (): ShopBrowseService => new ShopBrowseService($this->catalog(), $this->hooks));
    }

    /** What the Shop's order pages show a signed-in customer: their own orders only, with the fields a customer may see. */
    public function shopOrders(): ShopOrderService
    {
        return $this->once('shop-orders', fn (): ShopOrderService => new ShopOrderService(
            $this->orders(),
            new CustomerAccessService(new OrganizationCustomerRepository($this->organization)),
            new BranchRepository($this->organization),
        ));
    }

    public function media(): MediaService
    {
        return $this->once('media', fn (): MediaService => new MediaService(new MediaRepository($this->organization), $this->uploadRoot, $this->organizationSlug));
    }

    public function pricing(): PricingService
    {
        return $this->once('pricing', fn (): PricingService => new PricingService(new CatalogRepository($this->organization), $this->settings(), $this->hooks));
    }

    public function inventory(): InventoryService
    {
        return $this->once('inventory', fn (): InventoryService => new InventoryService($this->organization, new InventoryRepository($this->organization), $this->audit, $this->hooks));
    }

    public function payments(): PaymentProvider
    {
        return $this->paymentProvider ?? new ManualPaymentProvider();
    }

    public function orders(): OrderService
    {
        return $this->once('orders', fn (): OrderService => new OrderService(
            $this->organization, new OrderRepository($this->organization), $this->pricing(), $this->inventory(), $this->settings(), $this->audit, $this->hooks, $this->payments(),
        ));
    }

    public function procurement(): ProcurementService
    {
        return $this->once('procurement', fn (): ProcurementService => new ProcurementService(
            $this->organization, new ProcurementRepository($this->organization), $this->inventory(), $this->audit,
        ));
    }

    public function pos(): PosService
    {
        return $this->once('pos', fn (): PosService => new PosService(
            $this->organization, new PosRepository($this->organization), $this->pricing(), $this->inventory(), $this->audit, $this->payments(),
        ));
    }

    public function reports(): ReportService
    {
        return $this->once('reports', fn (): ReportService => new ReportService(new ReportRepository($this->organization), $this->hooks));
    }

    /**
     * Branches, terminals, discount rules, order steps and organization settings are the only services that check the
     * actor's permission themselves, so they need the PermissionService. Services::forTenant() supplies it; code that builds TenantServices without it (the seed, the
     * business-service tests) simply cannot reach these two.
     */
    public function branches(): BranchService
    {
        return $this->once('branches', fn (): BranchService => new BranchService(
            $this->organization, new BranchRepository($this->organization), $this->audit, $this->requirePermissions(),
        ));
    }

    public function terminals(): TerminalService
    {
        return $this->once('terminals', fn (): TerminalService => new TerminalService(
            $this->organization, new TerminalRepository($this->organization), new BranchRepository($this->organization), $this->audit, $this->requirePermissions(),
        ));
    }

    public function discountRules(): DiscountRuleService
    {
        return $this->once('discountRules', fn (): DiscountRuleService => new DiscountRuleService(
            $this->organization, new DiscountRuleRepository($this->organization), $this->audit, $this->requirePermissions(),
        ));
    }

    public function orderSteps(): OrderStepService
    {
        return $this->once('orderSteps', fn (): OrderStepService => new OrderStepService(
            $this->organization, new OrderStepRepository($this->organization), $this->audit, $this->requirePermissions(),
        ));
    }

    public function organizationSettings(): OrganizationSettingsService
    {
        return $this->once('organizationSettings', fn (): OrganizationSettingsService => new OrganizationSettingsService(
            $this->organization, $this->settings(), $this->requirePermissions(),
        ));
    }

    private function requirePermissions(): PermissionService
    {
        return $this->permissions ?? throw new \LogicException('The branch, terminal, discount rule, order step and organization settings services need the PermissionService; build TenantServices through Services::forTenant().');
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
