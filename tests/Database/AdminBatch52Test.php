<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Controller\Admin\BrandsController;
use Gfh\Core\Controller\Admin\CategoriesController;
use Gfh\Core\Controller\Admin\ProductsController;
use Gfh\Core\Database\Database;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Middleware\CsrfMiddleware;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Organization;
use Gfh\Core\Tenancy\Tenant;
use Gfh\Tests\Support\DatabaseTestCase;

final class AdminBatch52Test extends DatabaseTestCase
{
    private string $sessionDir = '';
    /** @var list<string> */
    private array $temporaryFiles = [];
    /** @var list<string> */
    private array $uploadDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionDir = sys_get_temp_dir() . '/gfh-test52-sessions-' . bin2hex(random_bytes(4));
        mkdir($this->sessionDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        foreach ($this->uploadDirs as $dir) {
            if (is_dir($dir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($iterator as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
                @rmdir($dir);
            }
        }
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

    private function createTestOrg(Services $services, string $prefix = 'org'): Organization
    {
        $slug = $prefix . '-' . bin2hex(random_bytes(3));
        $org = $this->kit->organization($slug);
        $this->uploadDirs[] = $services->app()->path('storage/uploads/' . $org->slug);

        return $org;
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

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    private function staffRequest(
        string $method,
        string $path,
        Services $services,
        Tenant $tenant,
        StaffUser $staff,
        array $query = [],
        array $post = [],
        array $files = [],
        ?Session $session = null,
    ): Request {
        $session ??= $this->makeSession($services);
        $request = new Request($method, $path, query: $query, post: $post, files: $files);

        return $request
            ->withAttribute('tenant', $tenant)
            ->withAttribute('staff', $staff)
            ->withAttribute('session', $session);
    }

    private function createTempPng(int $width = 40, int $height = 40): string
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('The gd extension is not enabled.');
        }

        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 36, 99, 235));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'gfh-img-');
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    public function testPermissionsAndFiltersOnProductsBrandsAndCategories(): void
    {
        $services = $this->bootServices();
        $org = $this->createTestOrg($services, 'perm');
        $staffUser = $this->kit->member($org, 'manager@example.test', 'manager');
        $cashierUser = $this->kit->member($org, 'cashier@example.test', 'cashier');
        $viewerUser = $this->kit->member($org, 'viewer@example.test', 'staff');

        $managerTenant = $this->makeTenant($org, 'manager');
        $cashierTenant = $this->makeTenant($org, 'cashier');
        $viewerTenant = $this->makeTenant($org, 'staff');

        $catalog = $services->forTenant($managerTenant)->catalog();
        $brandA = $catalog->createBrand('Kape Negros', 'Coop', $staffUser->id);
        $brandB = $catalog->createBrand('Bacolod Roasters', null, $staffUser->id);
        $cat = $catalog->createCategory(null, 'Coffee', $staffUser->id);
        $catalog->createProduct([
            'name' => 'Single Origin Beans',
            'status' => 'active',
            'brand_id' => $brandA['id'],
            'category_id' => $cat['id'],
            'variants' => [['sku' => 'COF-01', 'name' => '250g', 'retail_price' => '340.00']],
        ], $staffUser->id);
        $catalog->createProduct([
            'name' => 'House Espresso Blend',
            'status' => 'draft',
            'brand_id' => $brandB['id'],
            'category_id' => $cat['id'],
            'variants' => [['sku' => 'COF-02', 'name' => '500g', 'retail_price' => '620.00']],
        ], $staffUser->id);

        $products = new ProductsController($services);
        $brands = new BrandsController($services);
        $categories = new CategoriesController($services);

        // Cashier has no products permission at all.
        foreach ([
            fn (): Response => $products->index($this->staffRequest('GET', '/admin/products', $services, $cashierTenant, $cashierUser)),
            fn (): Response => $brands->index($this->staffRequest('GET', '/admin/brands', $services, $cashierTenant, $cashierUser)),
            fn (): Response => $categories->index($this->staffRequest('GET', '/admin/categories', $services, $cashierTenant, $cashierUser)),
        ] as $call) {
            try {
                $call();
                self::fail('Expected 403 Forbidden for cashier.');
            } catch (HttpException $e) {
                self::assertSame(403, $e->status());
            }
        }

        // Staff (view-only) can view listings and cannot create/edit.
        $viewRes = $products->index($this->staffRequest('GET', '/admin/products', $services, $viewerTenant, $viewerUser, [
            'q' => 'Origin',
            'brand_id' => $brandA['id'],
            'category_id' => $cat['id'],
            'status' => 'active',
            'min_price' => '100',
            'max_price' => '400',
            'sort' => '-price',
        ]));
        self::assertSame(200, $viewRes->status());
        self::assertStringContainsString('Single Origin Beans', $viewRes->body());
        self::assertStringNotContainsString('House Espresso Blend', $viewRes->body());
        self::assertStringNotContainsString('New product', $viewRes->body());

        // Invalid filter returns 422 with friendly filter notice instead of throwing uncaught.
        $badFilter = $products->index($this->staffRequest('GET', '/admin/products', $services, $viewerTenant, $viewerUser, ['min_price' => 'nope']));
        self::assertSame(422, $badFilter->status());
        self::assertStringContainsString('Check the filter values', $badFilter->body());

        try {
            $products->store($this->staffRequest('POST', '/admin/products', $services, $viewerTenant, $viewerUser, post: ['name' => 'Denied']));
            self::fail('Expected 403 when staff attempts POST /admin/products.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
    }

    public function testProductEditingVariantsTiersPriceHistoryPhotosAndHooks(): void
    {
        $services = $this->bootServices();
        $org = $this->createTestOrg($services, 'prod');
        $adminUser = $this->kit->member($org, 'owner@example.test', 'admin');
        $tenant = $this->makeTenant($org, 'admin');
        $tenantServices = $services->forTenant($tenant);

        // Register extension hooks: validation.attributes, product.form.tabs, product.form.save.
        $saveHookCalled = false;
        $tenantServices->hooks()->add(Hooks::VALIDATION_ATTRIBUTES, static function (array $errors, array $context): array {
            $attrs = $context['attributes'] ?? [];
            $roast = (string) ($attrs['roast'] ?? '');
            if ($roast !== '' && !in_array($roast, ['light', 'medium', 'dark'], true)) {
                $errors['attributes.roast'] = 'Choose light, medium or dark.';
            }

            return $errors;
        });
        $tenantServices->hooks()->add(Hooks::PRODUCT_FORM_SAVE, static function (array $data) use (&$saveHookCalled): array {
            $saveHookCalled = true;

            return $data;
        });
        $tenantServices->hooks()->add(Hooks::PRODUCT_FORM_TABS, static function (array $tabs, array $context): array {
            $tabs[] = [
                'id' => 'roast-notes',
                'label' => 'Roastery specs',
                'contentHtml' => '<section class="card"><p>Hooked tab for ' . htmlspecialchars((string) $context['product']['name'], ENT_QUOTES) . '</p></section>',
            ];

            return $tabs;
        });

        $brand = $tenantServices->catalog()->createBrand('Negros Estate', null, $adminUser->id);
        $cat = $tenantServices->catalog()->createCategory(null, 'Whole Beans', $adminUser->id);

        $controller = new ProductsController($services, static fn (string $path): bool => is_file($path));

        // 1. Activating on create with no variant fails 422 cleanly.
        $noVar = $controller->store($this->staffRequest('POST', '/admin/products', $services, $tenant, $adminUser, post: [
            'name' => 'Barako Gold',
            'status' => 'active',
            'brand_id' => $brand['id'],
            'category_id' => $cat['id'],
        ]));
        self::assertSame(422, $noVar->status());
        self::assertStringContainsString('Add at least one variant before making the product active.', $noVar->body());

        // 2. Create draft product with initial variant and attributes; verify product.form.save ran.
        $created = $controller->store($this->staffRequest('POST', '/admin/products', $services, $tenant, $adminUser, post: [
            'name' => 'Kanlaon Espresso',
            'status' => 'active',
            'brand_id' => $brand['id'],
            'category_id' => $cat['id'],
            'description' => 'Rich volcanic roast.',
            'attr_new_key' => ['roast', 'origin'],
            'attr_new_val' => ['medium', 'Negros'],
            'sku' => 'KN-250',
            'variant_name' => '250g Pack',
            'retail_price' => '340.00',
            'trade_price' => '285.00',
            'unit' => 'pack',
            'reorder_level' => '5',
        ]));
        self::assertTrue($saveHookCalled);
        self::assertSame(302, $created->status());
        $location = $created->headers()['Location'] ?? '';
        self::assertMatchesRegularExpression('#/admin/products/[0-9a-f\-]{36}$#', $location);
        $productId = substr($location, (int) strrpos($location, '/') + 1);

        // 3. validation.attributes hook error displays inline on the product form.
        $badAttr = $controller->update($this->staffRequest('POST', "/admin/products/$productId", $services, $tenant, $adminUser, post: [
            'name' => 'Kanlaon Espresso',
            'description' => 'Rich volcanic roast.',
            'brand_id' => $brand['id'],
            'category_id' => $cat['id'],
            'attributes' => ['roast' => 'charcoal', 'origin' => 'Negros'],
        ])->withParams(['id' => $productId]));
        self::assertSame(422, $badAttr->status());
        self::assertStringContainsString('Choose light, medium or dark.', $badAttr->body());
        self::assertStringContainsString('aria-invalid="true"', $badAttr->body());

        // 4. Add a second variant, update its retail price (logs price history), save & update a price tier.
        $addVarRes = $controller->addVariant($this->staffRequest('POST', "/admin/products/$productId/variants", $services, $tenant, $adminUser, post: [
            'sku' => 'KN-500',
            'name' => '500g Pack',
            'retail_price' => '640.00',
            'trade_price' => '520.00',
            'unit' => 'pack',
            'allow_decimal_qty' => '0',
            'track_batches' => '1',
            'reorder_level' => '4',
            'status' => 'active',
        ])->withParams(['id' => $productId]));
        self::assertSame(302, $addVarRes->status());

        $loaded = $tenantServices->catalog()->getProduct($productId, ['tiers' => true]);
        self::assertNotNull($loaded);
        $secondVariant = null;
        foreach ($loaded['variants'] as $v) {
            if ($v['sku'] === 'KN-500') {
                $secondVariant = $v;
                break;
            }
        }
        self::assertNotNull($secondVariant);
        $vid = (string) $secondVariant['id'];

        $updVarRes = $controller->updateVariant($this->staffRequest('POST', "/admin/variants/$vid", $services, $tenant, $adminUser, post: [
            'product_id' => $productId,
            'sku' => 'KN-500',
            'name' => '500g Pack Updated',
            'retail_price' => '660.00',
            'trade_price' => '530.00',
            'unit' => 'pack',
            'allow_decimal_qty' => '0',
            'track_batches' => '0',
            'reorder_level' => '6',
            'status' => 'active',
        ])->withParams(['id' => $vid]));
        self::assertSame(302, $updVarRes->status());

        // Tier > retail price refused with 422 inline field error.
        $badTier = $controller->addTier($this->staffRequest('POST', "/admin/variants/$vid/tiers", $services, $tenant, $adminUser, post: [
            'product_id' => $productId,
            'basis' => 'quantity',
            'label' => 'Too expensive',
            'min_qty' => '5',
            'price' => '700.00',
        ])->withParams(['id' => $vid]));
        self::assertSame(422, $badTier->status());
        self::assertStringContainsString('A tier price cannot be higher than the retail price.', $badTier->body());

        // Valid tier saved, updated, and deleted cleanly.
        $addTierRes = $controller->addTier($this->staffRequest('POST', "/admin/variants/$vid/tiers", $services, $tenant, $adminUser, post: [
            'product_id' => $productId,
            'basis' => 'quantity',
            'label' => '5+ packs',
            'min_qty' => '5',
            'price' => '590.00',
        ])->withParams(['id' => $vid]));
        self::assertSame(302, $addTierRes->status());

        $afterTier = $tenantServices->catalog()->getProduct($productId, ['tiers' => true]);
        $tierId = (string) $afterTier['variants'][1]['tiers'][0]['id'];
        $updTierRes = $controller->updateTier($this->staffRequest('POST', "/admin/tiers/$tierId", $services, $tenant, $adminUser, post: [
            'product_id' => $productId,
            'variant_id' => $vid,
            'label' => '5+ bulk',
            'min_qty' => '5',
            'price' => '580.00',
        ])->withParams(['id' => $tierId]));
        self::assertSame(302, $updTierRes->status());

        // 5. Upload 2 photos at once, switch primary, reorder, then remove primary and verify promotion.
        $png1 = $this->createTempPng(50, 50);
        $png2 = $this->createTempPng(60, 60);
        $upRes = $controller->uploadPhotos($this->staffRequest(
            'POST',
            "/admin/products/$productId/photos",
            $services,
            $tenant,
            $adminUser,
            post: ['alt_text' => 'Bag shot', 'primary' => '1'],
            files: ['files' => [
                'tmp_name' => [$png1, $png2],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'name' => ['front.png', 'back.png'],
            ]],
        )->withParams(['id' => $productId]));
        self::assertSame(302, $upRes->status());

        $withPhotos = $tenantServices->catalog()->getProduct($productId);
        self::assertCount(2, $withPhotos['images']);
        $img1 = (string) $withPhotos['images'][0]['id'];
        $img2 = (string) $withPhotos['images'][1]['id'];
        self::assertTrue((bool) $withPhotos['images'][0]['is_primary']);

        $primRes = $controller->setPrimaryPhoto($this->staffRequest('POST', "/admin/images/$img2/primary", $services, $tenant, $adminUser, post: [
            'product_id' => $productId,
        ])->withParams(['id' => $img2]));
        self::assertSame(302, $primRes->status());

        $reordRes = $controller->reorderPhotos($this->staffRequest('POST', "/admin/products/$productId/photos/order", $services, $tenant, $adminUser, post: [
            'move_id' => $img2,
            'direction' => 'up',
        ])->withParams(['id' => $productId]));
        self::assertSame(302, $reordRes->status());

        $reordered = $tenantServices->catalog()->getProduct($productId);
        self::assertSame($img2, (string) $reordered['images'][0]['id']);
        self::assertTrue((bool) $reordered['images'][0]['is_primary']);

        $delRes = $controller->removePhoto($this->staffRequest('POST', "/admin/images/$img2/delete", $services, $tenant, $adminUser, post: [
            'product_id' => $productId,
        ])->withParams(['id' => $img2]));
        self::assertSame(302, $delRes->status());

        $afterDel = $tenantServices->catalog()->getProduct($productId);
        self::assertCount(1, $afterDel['images']);
        self::assertTrue((bool) $afterDel['images'][0]['is_primary']);

        // 6. Edit view shows variant, tiers, price history (₱660.00 + ₱640.00), and extension tab.
        $editView = $controller->edit($this->staffRequest('GET', "/admin/products/$productId", $services, $tenant, $adminUser)->withParams(['id' => $productId]));
        self::assertSame(200, $editView->status());
        $html = $editView->body();
        self::assertStringContainsString('5+ bulk', $html);
        self::assertStringContainsString('₱660.00', $html);
        self::assertStringContainsString('₱640.00', $html);
        self::assertStringContainsString('Roastery specs', $html);
        self::assertStringContainsString('Hooked tab for Kanlaon Espresso', $html);

        // Clean up tier via controller.
        $delTierRes = $controller->deleteTier($this->staffRequest('POST', "/admin/tiers/$tierId/delete", $services, $tenant, $adminUser, post: [
            'product_id' => $productId,
        ])->withParams(['id' => $tierId]));
        self::assertSame(302, $delTierRes->status());
    }

    public function testBrandAndCategoryRulesPermissionsAndLogoUpload(): void
    {
        $services = $this->bootServices();
        $org = $this->createTestOrg($services, 'bc');
        $adminUser = $this->kit->member($org, 'admin@example.test', 'admin');
        $managerUser = $this->kit->member($org, 'mgr@example.test', 'manager');
        $adminTenant = $this->makeTenant($org, 'admin');
        $managerTenant = $this->makeTenant($org, 'manager');

        $brands = new BrandsController($services, static fn (string $path): bool => is_file($path));
        $categories = new CategoriesController($services);

        // Manager (edit permission) can create brand, upload logo, rename, and create/rename category.
        $gfhRejected = $brands->store($this->staffRequest('POST', '/admin/brands', $services, $managerTenant, $managerUser, post: [
            'name' => 'GFH House Brand',
        ]));
        self::assertSame(422, $gfhRejected->status());
        self::assertStringContainsString('GFH', $gfhRejected->body());

        $brandCreated = $brands->store($this->staffRequest('POST', '/admin/brands', $services, $managerTenant, $managerUser, post: [
            'name' => 'Sierra Roasters',
            'manufacturer' => 'Sierra Coop',
        ]));
        self::assertSame(302, $brandCreated->status());

        $catCreated = $categories->store($this->staffRequest('POST', '/admin/categories', $services, $managerTenant, $managerUser, post: [
            'name' => 'Beverages',
        ]));
        self::assertSame(302, $catCreated->status());

        $catalog = $services->forTenant($adminTenant)->catalog();
        $allBrands = $catalog->activeBrands();
        $brandId = (string) $allBrands[0]['id'];
        $tree = $catalog->categoryTree(false);
        $catId = (string) $tree[0]['id'];

        // Manager uploads logo and creates sub-category.
        $logoPng = $this->createTempPng(32, 32);
        $logoRes = $brands->uploadLogo($this->staffRequest(
            'POST',
            "/admin/brands/$brandId/logo",
            $services,
            $managerTenant,
            $managerUser,
            files: ['file' => ['tmp_name' => $logoPng, 'error' => UPLOAD_ERR_OK, 'name' => 'logo.png']],
        )->withParams(['id' => $brandId]));
        self::assertSame(302, $logoRes->status());

        $subCatRes = $categories->store($this->staffRequest('POST', '/admin/categories', $services, $managerTenant, $managerUser, post: [
            'parent_id' => $catId,
            'name' => 'Single Serve',
        ]));
        self::assertSame(302, $subCatRes->status());

        // Manager CANNOT archive brand or category (requires products:delete).
        try {
            $brands->archive($this->staffRequest('POST', "/admin/brands/$brandId/archive", $services, $managerTenant, $managerUser)->withParams(['id' => $brandId]));
            self::fail('Expected 403 when manager archives a brand.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
        try {
            $categories->archive($this->staffRequest('POST', "/admin/categories/$catId/archive", $services, $managerTenant, $managerUser)->withParams(['id' => $catId]));
            self::fail('Expected 403 when manager archives a category.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }

        // Brand / category in use cannot be archived (409).
        $subId = (string) $catalog->categoryTree(false)[0]['children'][0]['id'];
        $catalog->createProduct([
            'name' => 'Drip Bag',
            'status' => 'draft',
            'brand_id' => $brandId,
            'category_id' => $subId,
        ], $adminUser->id);

        $brandInUse = $brands->archive($this->staffRequest('POST', "/admin/brands/$brandId/archive", $services, $adminTenant, $adminUser)->withParams(['id' => $brandId]));
        self::assertSame(409, $brandInUse->status());

        $catInUse = $categories->archive($this->staffRequest('POST', "/admin/categories/$subId/archive", $services, $adminTenant, $adminUser)->withParams(['id' => $subId]));
        self::assertSame(409, $catInUse->status());

        // Unused brand and unused category archive and restore cleanly by admin.
        $unusedBrand = $catalog->createBrand('Unused Roasters', null, $adminUser->id);
        $archBrand = $brands->archive($this->staffRequest('POST', '/admin/brands/' . $unusedBrand['id'] . '/archive', $services, $adminTenant, $adminUser)->withParams(['id' => $unusedBrand['id']]));
        self::assertSame(302, $archBrand->status());
        $brandsIdxArch = $brands->index($this->staffRequest('GET', '/admin/brands', $services, $adminTenant, $adminUser, query: ['q' => 'Unused', 'archived' => '1', 'sort' => '-status']));
        self::assertSame(200, $brandsIdxArch->status());
        self::assertStringContainsString('Unused Roasters', $brandsIdxArch->body());
        $restBrand = $brands->restore($this->staffRequest('POST', '/admin/brands/' . $unusedBrand['id'] . '/restore', $services, $adminTenant, $adminUser)->withParams(['id' => $unusedBrand['id']]));
        self::assertSame(302, $restBrand->status());

        $unusedCat = $catalog->createCategory(null, 'Unused Shelf', $adminUser->id);
        $archCat = $categories->archive($this->staffRequest('POST', '/admin/categories/' . $unusedCat['id'] . '/archive', $services, $adminTenant, $adminUser)->withParams(['id' => $unusedCat['id']]));
        self::assertSame(302, $archCat->status());
        $catsIdxArch = $categories->index($this->staffRequest('GET', '/admin/categories', $services, $adminTenant, $adminUser, query: ['q' => 'Unused', 'archived' => '1', 'sort' => '-name', 'page' => '1', 'per_page' => '10']));
        self::assertSame(200, $catsIdxArch->status());
        self::assertStringContainsString('Unused Shelf', $catsIdxArch->body());
        $restCat = $categories->restore($this->staffRequest('POST', '/admin/categories/' . $unusedCat['id'] . '/restore', $services, $adminTenant, $adminUser)->withParams(['id' => $unusedCat['id']]));
        self::assertSame(302, $restCat->status());
    }

    public function testCsrfMiddlewareBlocksProductStateChangeWithoutToken(): void
    {
        $services = $this->bootServices();
        $org = $this->createTestOrg($services, 'csrf52');
        $adminUser = $this->kit->member($org, 'owner@example.test', 'admin');
        $tenant = $this->makeTenant($org, 'admin');
        $session = $this->makeSession($services);
        $csrfMiddleware = new CsrfMiddleware();
        $products = new ProductsController($services);

        $badReq = $this->staffRequest('POST', '/' . $org->slug . '/admin/products', $services, $tenant, $adminUser, post: ['name' => 'No Token'], session: $session);
        try {
            $csrfMiddleware($badReq, static fn (Request $req): Response => $products->store($req));
            self::fail('Expected 403 from CsrfMiddleware without token.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
    }
}
