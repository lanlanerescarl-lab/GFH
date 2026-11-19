<?php

declare(strict_types=1);

use Gfh\Core\App;
use Gfh\Core\Controller\Admin\BranchesController;
use Gfh\Core\Controller\Admin\BrandsController;
use Gfh\Core\Controller\Admin\CategoriesController;
use Gfh\Core\Controller\Admin\CustomersController;
use Gfh\Core\Controller\Admin\DashboardController;
use Gfh\Core\Controller\Admin\InventoryController;
use Gfh\Core\Controller\Admin\OrdersController;
use Gfh\Core\Controller\Admin\PosSalesController;
use Gfh\Core\Controller\Admin\ProductsController;
use Gfh\Core\Controller\Admin\PurchaseOrdersController;
use Gfh\Core\Controller\Admin\ReportsController;
use Gfh\Core\Controller\Admin\SettingsController;
use Gfh\Core\Controller\Admin\SuppliersController;
use Gfh\Core\Controller\Admin\TeamController;
use Gfh\Core\Controller\Admin\TerminalsController;
use Gfh\Core\Controller\Api\CartController;
use Gfh\Core\Controller\Api\CatalogAdminController;
use Gfh\Core\Controller\Api\CatalogController;
use Gfh\Core\Controller\Api\InventoryAdminController;
use Gfh\Core\Controller\Api\MediaController;
use Gfh\Core\Controller\Api\OrderAdminController;
use Gfh\Core\Controller\Api\PosApiController;
use Gfh\Core\Controller\Api\ProcurementAdminController;
use Gfh\Core\Controller\Api\ReportController;
use Gfh\Core\Controller\AuthController;
use Gfh\Core\Controller\BrandingController;
use Gfh\Core\Controller\StaffPagesController;
use Gfh\Core\Controller\SystemController;
use Gfh\Core\Http\Middleware\CsrfMiddleware;
use Gfh\Core\Http\Middleware\RequireCustomerMiddleware;
use Gfh\Core\Http\Middleware\RequireStaffMiddleware;
use Gfh\Core\Http\Middleware\SessionMiddleware;
use Gfh\Core\Http\Middleware\TenantMiddleware;
use Gfh\Core\Http\Request;
use Gfh\Core\Routing\Router;
use Gfh\Core\Support\Services;

// Core routes. Later stages add /media/{org}/{id}, /superadmin and the rest of /{org}/shop, /{org}/pos,
// /{org}/admin and /{org}/api/v1/....
// Reserved first path segments, never usable as organization slugs:
// health, api, media, assets, superadmin.

return static function (Router $router, App $app, ?Services $services = null): void {
    $system = new SystemController($app);

    $router->get('/', static fn (Request $request) => $system->welcome($request));
    $router->get('/health', static fn (Request $request) => $system->health($request));

    // Everything below belongs to one organization. The slug is only a lookup key: TenantMiddleware
    // loads the organization (and its connection) from the core registry.
    $services ??= new Services($app);
    $auth = new AuthController($services);
    $pages = new StaffPagesController($services);
    $adminDashboard = new DashboardController($services);
    $adminInventory = new InventoryController($services);
    $adminProducts = new ProductsController($services);
    $adminBrands = new BrandsController($services);
    $adminCategories = new CategoriesController($services);
    $adminSuppliers = new SuppliersController($services);
    $adminPurchaseOrders = new PurchaseOrdersController($services);
    $adminOrders = new OrdersController($services);
    $adminCustomers = new CustomersController($services);
    $adminPosSales = new PosSalesController($services);
    $adminReports = new ReportsController($services);
    $adminTeam = new TeamController($services);
    $adminSettings = new SettingsController($services);
    $adminBranches = new BranchesController($services);
    $adminTerminals = new TerminalsController($services);
    $branding = new BrandingController();

    $tenant = new TenantMiddleware($services);
    $staffSession = new SessionMiddleware($services, 'staff');
    $customerSession = new SessionMiddleware($services, 'customer');
    $csrf = new CsrfMiddleware();
    $requireStaff = new RequireStaffMiddleware($services);
    $requireCustomer = new RequireCustomerMiddleware();

    // Reading the catalog needs no sign-in; a session is opened only if the visitor already has one (for tier prices).
    $customerOptional = new SessionMiddleware($services, 'customer', true);

    $catalog = new CatalogController($services);
    $catalogAdmin = new CatalogAdminController($services);
    $media = new MediaController($services);
    $cart = new CartController($services);
    $ordersAdmin = new OrderAdminController($services);
    $inventoryAdmin = new InventoryAdminController($services);
    $procurementAdmin = new ProcurementAdminController($services);
    $posApi = new PosApiController($services);
    $reports = new ReportController($services);

    // Product photos: public, long-cached, streamed from storage/uploads through this route only.
    $router->get('/media/{org:[a-z][a-z0-9-]*}/{id}', static fn (Request $q) => $media->serve($q), [$tenant]);

    $router->group('/{org:[a-z][a-z0-9-]*}', static function (Router $r) use (
        $auth, $pages, $adminDashboard, $adminInventory, $adminProducts, $adminBrands, $adminCategories,
        $adminSuppliers, $adminPurchaseOrders, $adminOrders, $adminCustomers, $adminPosSales, $adminReports, $adminTeam,
        $adminSettings, $adminBranches, $adminTerminals, $branding,
        $tenant, $staffSession, $customerSession, $csrf, $requireStaff, $requireCustomer,
        $customerOptional, $catalog, $catalogAdmin, $media, $cart, $ordersAdmin, $inventoryAdmin, $procurementAdmin, $posApi, $reports,
    ): void {
        // Every Admin and POS API call: organization, staff session, CSRF for changes, signed-in member. Each
        // controller method then checks can() for its own module and action.
        $staff = [$tenant, $staffSession, $csrf, $requireStaff];
        // --- JSON: staff
        $r->get('/api/v1/auth/staff/csrf', static fn (Request $q) => $auth->csrf($q), [$tenant, $staffSession]);
        $r->post('/api/v1/auth/staff/login', static fn (Request $q) => $auth->staffLogin($q), [$tenant, $staffSession, $csrf]);
        $r->post('/api/v1/auth/staff/logout', static fn (Request $q) => $auth->staffLogout($q), [$tenant, $staffSession, $csrf]);
        $r->get('/api/v1/auth/staff/me', static fn (Request $q) => $auth->staffMe($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/api/v1/admin/reset-links', static fn (Request $q) => $auth->issueResetLink($q), $staff);

        // --- JSON: customers
        $r->get('/api/v1/auth/customer/csrf', static fn (Request $q) => $auth->csrf($q), [$tenant, $customerSession]);
        $r->post('/api/v1/auth/customer/register', static fn (Request $q) => $auth->customerRegister($q), [$tenant, $customerSession, $csrf]);
        $r->post('/api/v1/auth/customer/login', static fn (Request $q) => $auth->customerLogin($q), [$tenant, $customerSession, $csrf]);
        $r->post('/api/v1/auth/customer/logout', static fn (Request $q) => $auth->customerLogout($q), [$tenant, $customerSession, $csrf]);
        $r->get('/api/v1/auth/customer/me', static fn (Request $q) => $auth->customerMe($q), [$tenant, $customerSession, $requireCustomer]);

        // --- JSON: password reset from a one-time link (the link is the proof, so no session or CSRF token)
        $r->post('/api/v1/auth/reset-password', static fn (Request $q) => $auth->resetPassword($q), [$tenant]);

        // --- JSON: catalog (public)
        $r->get('/api/v1/catalog/products', static fn (Request $q) => $catalog->products($q), [$tenant, $customerOptional]);
        $r->get('/api/v1/catalog/filters', static fn (Request $q) => $catalog->filters($q), [$tenant]);
        $r->get('/api/v1/catalog/products/{slug}', static fn (Request $q) => $catalog->product($q), [$tenant, $customerOptional]);
        $r->get('/api/v1/catalog/categories', static fn (Request $q) => $catalog->categories($q), [$tenant]);
        $r->get('/api/v1/catalog/brands', static fn (Request $q) => $catalog->brands($q), [$tenant]);

        // --- JSON: cart, checkout and the customer's own orders
        $r->get('/api/v1/checkout/options', static fn (Request $q) => $cart->options($q), [$tenant]);
        $r->post('/api/v1/cart/price', static fn (Request $q) => $cart->price($q), [$tenant, $customerSession, $csrf]);
        $r->post('/api/v1/checkout', static fn (Request $q) => $cart->checkout($q), [$tenant, $customerSession, $csrf, $requireCustomer]);
        $r->get('/api/v1/orders', static fn (Request $q) => $cart->myOrders($q), [$tenant, $customerSession, $requireCustomer]);
        $r->get('/api/v1/orders/{id}', static fn (Request $q) => $cart->myOrder($q), [$tenant, $customerSession, $requireCustomer]);

        // --- JSON: Admin catalog
        $r->get('/api/v1/admin/products', static fn (Request $q) => $catalogAdmin->products($q), $staff);
        $r->post('/api/v1/admin/products', static fn (Request $q) => $catalogAdmin->createProduct($q), $staff);
        $r->get('/api/v1/admin/products/{id}', static fn (Request $q) => $catalogAdmin->product($q), $staff);
        $r->put('/api/v1/admin/products/{id}', static fn (Request $q) => $catalogAdmin->updateProduct($q), $staff);
        $r->post('/api/v1/admin/products/{id}/status', static fn (Request $q) => $catalogAdmin->setProductStatus($q), $staff);
        $r->post('/api/v1/admin/products/{id}/variants', static fn (Request $q) => $catalogAdmin->createVariant($q), $staff);
        $r->put('/api/v1/admin/variants/{id}', static fn (Request $q) => $catalogAdmin->updateVariant($q), $staff);
        $r->post('/api/v1/admin/variants/{id}/tiers', static fn (Request $q) => $catalogAdmin->createTier($q), $staff);
        $r->put('/api/v1/admin/tiers/{id}', static fn (Request $q) => $catalogAdmin->updateTier($q), $staff);
        $r->delete('/api/v1/admin/tiers/{id}', static fn (Request $q) => $catalogAdmin->deleteTier($q), $staff);
        $r->post('/api/v1/admin/products/{id}/images', static fn (Request $q) => $catalogAdmin->attachImage($q), $staff);
        $r->put('/api/v1/admin/products/{id}/images/order', static fn (Request $q) => $catalogAdmin->reorderImages($q), $staff);
        $r->post('/api/v1/admin/images/{id}/primary', static fn (Request $q) => $catalogAdmin->setPrimaryImage($q), $staff);
        $r->delete('/api/v1/admin/images/{id}', static fn (Request $q) => $catalogAdmin->removeImage($q), $staff);
        $r->post('/api/v1/admin/media', static fn (Request $q) => $media->upload($q), $staff);
        $r->get('/api/v1/admin/brands', static fn (Request $q) => $catalogAdmin->brands($q), $staff);
        $r->post('/api/v1/admin/brands', static fn (Request $q) => $catalogAdmin->createBrand($q), $staff);
        $r->put('/api/v1/admin/brands/{id}', static fn (Request $q) => $catalogAdmin->renameBrand($q), $staff);
        $r->put('/api/v1/admin/brands/{id}/logo', static fn (Request $q) => $catalogAdmin->setBrandLogo($q), $staff);
        $r->delete('/api/v1/admin/brands/{id}', static fn (Request $q) => $catalogAdmin->archiveBrand($q), $staff);
        $r->post('/api/v1/admin/brands/{id}/restore', static fn (Request $q) => $catalogAdmin->restoreBrand($q), $staff);
        $r->get('/api/v1/admin/categories', static fn (Request $q) => $catalogAdmin->categories($q), $staff);
        $r->post('/api/v1/admin/categories', static fn (Request $q) => $catalogAdmin->createCategory($q), $staff);
        $r->put('/api/v1/admin/categories/{id}', static fn (Request $q) => $catalogAdmin->renameCategory($q), $staff);
        $r->delete('/api/v1/admin/categories/{id}', static fn (Request $q) => $catalogAdmin->archiveCategory($q), $staff);
        $r->post('/api/v1/admin/categories/{id}/restore', static fn (Request $q) => $catalogAdmin->restoreCategory($q), $staff);

        // --- JSON: Admin orders, inventory, procurement, reports
        $r->get('/api/v1/admin/orders', static fn (Request $q) => $ordersAdmin->index($q), $staff);
        $r->get('/api/v1/admin/orders/{id}', static fn (Request $q) => $ordersAdmin->show($q), $staff);
        $r->post('/api/v1/admin/orders/{id}/progress', static fn (Request $q) => $ordersAdmin->advance($q), $staff);
        $r->post('/api/v1/admin/orders/{id}/payments', static fn (Request $q) => $ordersAdmin->recordPayment($q), $staff);
        $r->post('/api/v1/admin/orders/{id}/cancel', static fn (Request $q) => $ordersAdmin->cancel($q), $staff);

        $r->get('/api/v1/admin/inventory/levels', static fn (Request $q) => $inventoryAdmin->levels($q), $staff);
        $r->get('/api/v1/admin/inventory/ledger', static fn (Request $q) => $inventoryAdmin->ledger($q), $staff);
        $r->get('/api/v1/admin/inventory/reorder-suggestions', static fn (Request $q) => $inventoryAdmin->reorderSuggestions($q), $staff);
        $r->post('/api/v1/admin/inventory/adjustments', static fn (Request $q) => $inventoryAdmin->adjust($q), $staff);
        $r->post('/api/v1/admin/inventory/write-offs', static fn (Request $q) => $inventoryAdmin->writeOff($q), $staff);

        $r->get('/api/v1/admin/suppliers', static fn (Request $q) => $procurementAdmin->suppliers($q), $staff);
        $r->post('/api/v1/admin/suppliers', static fn (Request $q) => $procurementAdmin->createSupplier($q), $staff);
        $r->get('/api/v1/admin/suppliers/{id}', static fn (Request $q) => $procurementAdmin->supplier($q), $staff);
        $r->put('/api/v1/admin/suppliers/{id}', static fn (Request $q) => $procurementAdmin->updateSupplier($q), $staff);
        $r->post('/api/v1/admin/suppliers/{id}/status', static fn (Request $q) => $procurementAdmin->setSupplierStatus($q), $staff);
        $r->get('/api/v1/admin/purchase-orders', static fn (Request $q) => $procurementAdmin->purchaseOrders($q), $staff);
        $r->post('/api/v1/admin/purchase-orders', static fn (Request $q) => $procurementAdmin->createPurchaseOrder($q), $staff);
        $r->get('/api/v1/admin/purchase-orders/{id}', static fn (Request $q) => $procurementAdmin->purchaseOrder($q), $staff);
        $r->post('/api/v1/admin/purchase-orders/{id}/send', static fn (Request $q) => $procurementAdmin->sendPurchaseOrder($q), $staff);
        $r->post('/api/v1/admin/purchase-orders/{id}/cancel', static fn (Request $q) => $procurementAdmin->cancelPurchaseOrder($q), $staff);
        $r->post('/api/v1/admin/purchase-orders/{id}/receive', static fn (Request $q) => $procurementAdmin->receive($q), $staff);

        $r->get('/api/v1/admin/reports/dashboard', static fn (Request $q) => $reports->dashboard($q), $staff);

        // --- JSON: POS
        $r->get('/api/v1/pos/terminals/{id}/snapshot', static fn (Request $q) => $posApi->snapshot($q), $staff);
        $r->get('/api/v1/pos/terminals/{id}/shift', static fn (Request $q) => $posApi->currentShift($q), $staff);
        $r->post('/api/v1/pos/shifts', static fn (Request $q) => $posApi->openShift($q), $staff);
        $r->post('/api/v1/pos/shifts/{id}/close', static fn (Request $q) => $posApi->closeShift($q), $staff);
        $r->post('/api/v1/pos/sales', static fn (Request $q) => $posApi->syncSale($q), $staff);
        $r->get('/api/v1/pos/sales/{id}', static fn (Request $q) => $posApi->sale($q), $staff);
        $r->get('/api/v1/admin/pos-sales', static fn (Request $q) => $posApi->sales($q), $staff);
        $r->post('/api/v1/admin/pos-sales/{id}/resolve', static fn (Request $q) => $posApi->resolveReview($q), $staff);

        // --- Organization branding (a stylesheet of CSS variables)
        $r->get('/branding.css', static fn (Request $q) => $branding->css($q), [$tenant]);

        // --- Pages
        $r->get('/admin', static fn (Request $q) => $adminDashboard->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/inventory', static fn (Request $q) => $adminInventory->levels($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/inventory/ledger', static fn (Request $q) => $adminInventory->ledger($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/inventory/reorder', static fn (Request $q) => $adminInventory->reorder($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/inventory/adjustments', static fn (Request $q) => $adminInventory->adjust($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/inventory/write-offs', static fn (Request $q) => $adminInventory->writeOff($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/products', static fn (Request $q) => $adminProducts->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/products/new', static fn (Request $q) => $adminProducts->createForm($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/products', static fn (Request $q) => $adminProducts->store($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/products/{id}', static fn (Request $q) => $adminProducts->edit($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/products/{id}', static fn (Request $q) => $adminProducts->update($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/products/{id}/status', static fn (Request $q) => $adminProducts->setStatus($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/products/{id}/variants', static fn (Request $q) => $adminProducts->addVariant($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/variants/{id}', static fn (Request $q) => $adminProducts->updateVariant($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/variants/{id}/tiers', static fn (Request $q) => $adminProducts->addTier($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/tiers/{id}', static fn (Request $q) => $adminProducts->updateTier($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/tiers/{id}/delete', static fn (Request $q) => $adminProducts->deleteTier($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/products/{id}/photos', static fn (Request $q) => $adminProducts->uploadPhotos($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/products/{id}/photos/order', static fn (Request $q) => $adminProducts->reorderPhotos($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/images/{id}/primary', static fn (Request $q) => $adminProducts->setPrimaryPhoto($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/images/{id}/delete', static fn (Request $q) => $adminProducts->removePhoto($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/brands', static fn (Request $q) => $adminBrands->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/brands', static fn (Request $q) => $adminBrands->store($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/brands/{id}/rename', static fn (Request $q) => $adminBrands->rename($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/brands/{id}/logo', static fn (Request $q) => $adminBrands->uploadLogo($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/brands/{id}/archive', static fn (Request $q) => $adminBrands->archive($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/brands/{id}/restore', static fn (Request $q) => $adminBrands->restore($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/categories', static fn (Request $q) => $adminCategories->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/categories', static fn (Request $q) => $adminCategories->store($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/categories/{id}/rename', static fn (Request $q) => $adminCategories->rename($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/categories/{id}/archive', static fn (Request $q) => $adminCategories->archive($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/categories/{id}/restore', static fn (Request $q) => $adminCategories->restore($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/procurement', static fn (Request $q) => $adminPurchaseOrders->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/procurement/new', static fn (Request $q) => $adminPurchaseOrders->createForm($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/procurement', static fn (Request $q) => $adminPurchaseOrders->store($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/procurement/suppliers', static fn (Request $q) => $adminSuppliers->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/procurement/suppliers', static fn (Request $q) => $adminSuppliers->store($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/procurement/suppliers/{id}', static fn (Request $q) => $adminSuppliers->show($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/procurement/suppliers/{id}', static fn (Request $q) => $adminSuppliers->update($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/procurement/suppliers/{id}/status', static fn (Request $q) => $adminSuppliers->setStatus($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/procurement/{id}', static fn (Request $q) => $adminPurchaseOrders->show($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/procurement/{id}/send', static fn (Request $q) => $adminPurchaseOrders->send($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/procurement/{id}/cancel', static fn (Request $q) => $adminPurchaseOrders->cancel($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/procurement/{id}/receive', static fn (Request $q) => $adminPurchaseOrders->receive($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/orders', static fn (Request $q) => $adminOrders->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/orders/{id}', static fn (Request $q) => $adminOrders->show($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/orders/{id}/progress', static fn (Request $q) => $adminOrders->advance($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/orders/{id}/payments', static fn (Request $q) => $adminOrders->recordPayment($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/orders/{id}/cancel', static fn (Request $q) => $adminOrders->cancel($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/customers', static fn (Request $q) => $adminCustomers->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/customers/{id}/reset-link', static fn (Request $q) => $adminCustomers->issueResetLink($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/pos-sales', static fn (Request $q) => $adminPosSales->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/pos-sales/{id}', static fn (Request $q) => $adminPosSales->show($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/pos-sales/{id}/resolve', static fn (Request $q) => $adminPosSales->resolve($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/reports', static fn (Request $q) => $adminReports->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/team', static fn (Request $q) => $adminTeam->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/team/new', static fn (Request $q) => $adminTeam->createForm($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/team', static fn (Request $q) => $adminTeam->store($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/team/{id}', static fn (Request $q) => $adminTeam->show($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/team/{id}', static fn (Request $q) => $adminTeam->update($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/team/{id}/deactivate', static fn (Request $q) => $adminTeam->deactivate($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/team/{id}/reactivate', static fn (Request $q) => $adminTeam->reactivate($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/team/{id}/reset-link', static fn (Request $q) => $adminTeam->issueResetLink($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/settings', static fn (Request $q) => $adminSettings->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/settings/branches', static fn (Request $q) => $adminBranches->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/settings/branches/new', static fn (Request $q) => $adminBranches->createForm($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/settings/branches', static fn (Request $q) => $adminBranches->store($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/settings/branches/{id}', static fn (Request $q) => $adminBranches->show($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/settings/branches/{id}', static fn (Request $q) => $adminBranches->update($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/settings/branches/{id}/deactivate', static fn (Request $q) => $adminBranches->deactivate($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/settings/branches/{id}/reactivate', static fn (Request $q) => $adminBranches->reactivate($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/settings/terminals', static fn (Request $q) => $adminTerminals->index($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/settings/terminals/new', static fn (Request $q) => $adminTerminals->createForm($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/settings/terminals', static fn (Request $q) => $adminTerminals->store($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/settings/terminals/{id}', static fn (Request $q) => $adminTerminals->show($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/settings/terminals/{id}', static fn (Request $q) => $adminTerminals->update($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/settings/terminals/{id}/deactivate', static fn (Request $q) => $adminTerminals->deactivate($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->post('/admin/settings/terminals/{id}/reactivate', static fn (Request $q) => $adminTerminals->reactivate($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/admin/login', static fn (Request $q) => $pages->loginForm($q), [$tenant, $staffSession]);
        $r->post('/admin/login', static fn (Request $q) => $pages->loginSubmit($q), [$tenant, $staffSession, $csrf]);
        $r->post('/admin/logout', static fn (Request $q) => $pages->logout($q), [$tenant, $staffSession, $csrf]);
        $r->get('/admin/account', static fn (Request $q) => $pages->account($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/ui-kit', static fn (Request $q) => $pages->uiKit($q), [$tenant, $staffSession, $requireStaff]);
        $r->get('/admin/reset-links', static fn (Request $q) => $pages->resetLinksForm($q), [$tenant, $staffSession, $requireStaff]);
        $r->post('/admin/reset-links', static fn (Request $q) => $pages->resetLinksSubmit($q), [$tenant, $staffSession, $csrf, $requireStaff]);
        $r->get('/reset-password', static fn (Request $q) => $pages->resetPasswordForm($q), [$tenant]);
        $r->post('/reset-password', static fn (Request $q) => $pages->resetPasswordSubmit($q), [$tenant]);
    });
};
