<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Controller\Admin\CustomersController;
use Gfh\Core\Controller\Admin\OrdersController;
use Gfh\Core\Controller\Admin\PosSalesController;
use Gfh\Core\Controller\Admin\PurchaseOrdersController;
use Gfh\Core\Controller\Admin\SuppliersController;
use Gfh\Core\Database\Database;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Repository\OrganizationCustomerRepository;
use Gfh\Core\Support\Services;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Organization;
use Gfh\Core\Tenancy\Tenant;
use Gfh\Tests\Support\DatabaseTestCase;

final class AdminBatch53Test extends DatabaseTestCase
{
    private string $sessionDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionDir = sys_get_temp_dir() . '/gfh-test53-sessions-' . bin2hex(random_bytes(4));
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

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $params
     */
    private function staffRequest(
        string $method,
        string $path,
        Services $services,
        Tenant $tenant,
        StaffUser $staff,
        array $query = [],
        array $post = [],
        array $params = [],
    ): Request {
        $session = new Session('staff', $services->app()->config(), $this->sessionDir, false);
        $request = new Request($method, $path, query: $query, post: $post);
        if ($params !== []) {
            $request = $request->withParams($params);
        }

        return $request
            ->withAttribute('tenant', $tenant)
            ->withAttribute('staff', $staff)
            ->withAttribute('session', $session);
    }

    public function testSuppliersPermissionsFiltersValidationAndDeactivation(): void
    {
        $services = $this->bootServices();
        $org = $this->kit->organization('sup-' . bin2hex(random_bytes(3)));
        $manager = $this->kit->member($org, 'manager@example.test', 'manager');
        $viewer = $this->kit->member($org, 'staff@example.test', 'staff');
        $cashier = $this->kit->member($org, 'cashier@example.test', 'cashier');

        $managerTenant = $this->makeTenant($org, 'manager');
        $viewerTenant = $this->makeTenant($org, 'staff');
        $cashierTenant = $this->makeTenant($org, 'cashier');

        $controller = new SuppliersController($services);
        $procurement = $services->forTenant($managerTenant)->procurement();

        // Cashier lacks procurement:view and procurement:edit
        $this->assertHttpStatus(403, fn () => $controller->index(
            $this->staffRequest('GET', '/' . $org->slug . '/admin/procurement/suppliers', $services, $cashierTenant, $cashier),
        ));
        $this->assertHttpStatus(403, fn () => $controller->store(
            $this->staffRequest('POST', '/' . $org->slug . '/admin/procurement/suppliers', $services, $cashierTenant, $cashier, [], ['name' => 'Blocked']),
        ));

        // Staff has procurement:view only
        $supA = $procurement->createSupplier(['name' => 'Alpha Timber', 'contact_email' => 'alpha@example.test', 'lead_time_days' => '7'], $manager->id);
        $supB = $procurement->createSupplier(['name' => 'Bacolod Varnish', 'lead_time_days' => '3'], $manager->id);
        $procurement->setSupplierStatus($supB['id'], 'inactive', $manager->id);

        $viewList = $controller->index($this->staffRequest('GET', '/' . $org->slug . '/admin/procurement/suppliers', $services, $viewerTenant, $viewer));
        $this->assertSame(200, $viewList->status());
        $this->assertStringContainsString('Alpha Timber', $viewList->body());
        $this->assertStringNotContainsString('Add supplier', $viewList->body());

        $this->assertHttpStatus(403, fn () => $controller->store(
            $this->staffRequest('POST', '/' . $org->slug . '/admin/procurement/suppliers', $services, $viewerTenant, $viewer, [], ['name' => 'Blocked']),
        ));
        $this->assertHttpStatus(403, fn () => $controller->setStatus(
            $this->staffRequest('POST', '/' . $org->slug . '/admin/procurement/suppliers/' . $supA['id'] . '/status', $services, $viewerTenant, $viewer, [], ['status' => 'inactive'], ['id' => $supA['id']]),
        ));

        // Validation error renders inline field errors with 422
        $bad = $controller->store($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement/suppliers',
            $services,
            $managerTenant,
            $manager,
            [],
            ['name' => 'Bad Supplier', 'contact_email' => 'not-an-email', 'lead_time_days' => '99999'],
        ));
        $this->assertSame(422, $bad->status());
        $this->assertStringContainsString('Enter a valid email address, or leave it empty.', $bad->body());
        $this->assertStringContainsString('Enter a whole number of days from 0 to 365.', $bad->body());

        // Create, update & filter
        $created = $controller->store($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement/suppliers',
            $services,
            $managerTenant,
            $manager,
            [],
            ['name' => 'Cebu Cane Co', 'contact_email' => 'cebu@example.test', 'payment_terms' => 'Net 15', 'lead_time_days' => '5'],
        ));
        $this->assertSame(302, $created->status());

        $activeOnly = $controller->index($this->staffRequest(
            'GET',
            '/' . $org->slug . '/admin/procurement/suppliers',
            $services,
            $managerTenant,
            $manager,
            ['status' => 'active', 'sort' => '-lead_time', 'q' => 'Cane'],
        ));
        $this->assertSame(200, $activeOnly->status());
        $this->assertStringContainsString('Cebu Cane Co', $activeOnly->body());
        $this->assertStringNotContainsString('Bacolod Varnish', $activeOnly->body());

        $badFilter = $controller->index($this->staffRequest(
            'GET',
            '/' . $org->slug . '/admin/procurement/suppliers',
            $services,
            $managerTenant,
            $manager,
            ['status' => 'bogus'],
        ));
        $this->assertSame(422, $badFilter->status());

        $updated = $controller->update($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement/suppliers/' . $supA['id'],
            $services,
            $managerTenant,
            $manager,
            [],
            ['name' => 'Alpha Timber Corp', 'payment_terms' => 'Net 45', 'lead_time_days' => '10'],
            ['id' => $supA['id']],
        ));
        $this->assertSame(302, $updated->status());

        $deactivated = $controller->setStatus($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement/suppliers/' . $supA['id'] . '/status',
            $services,
            $managerTenant,
            $manager,
            [],
            ['status' => 'inactive'],
            ['id' => $supA['id']],
        ));
        $this->assertSame(302, $deactivated->status());
        $this->assertSame('inactive', $procurement->getSupplier($supA['id'])['status']);
    }

    public function testPurchaseOrdersCreateSendCancelAndReceiveWithRejectedAndBatchCapture(): void
    {
        $services = $this->bootServices();
        $org = $this->kit->organization('po-' . bin2hex(random_bytes(3)));
        $manager = $this->kit->member($org, 'manager@example.test', 'manager');
        $viewer = $this->kit->member($org, 'staff@example.test', 'staff');
        $cashier = $this->kit->member($org, 'cashier@example.test', 'cashier');

        $managerTenant = $this->makeTenant($org, 'manager');
        $viewerTenant = $this->makeTenant($org, 'staff');
        $cashierTenant = $this->makeTenant($org, 'cashier');

        $branchId = $this->shop->branch('MAIN');
        $plainVariant = $this->shop->variant(['sku' => 'WOOD-01', 'name' => 'Timber Board', 'price' => '224.00']);
        $batchVariant = $this->shop->variant(['sku' => 'GLUE-01', 'name' => 'Wood Glue', 'price' => '180.00', 'track_batches' => true]);
        // Seed existing stock at PHP 50.00 so weighted average recalculates on receive: (2 * 50 + 4 * 80) / 6 = 70.00
        $this->shop->stock($branchId, $plainVariant, '2', '50.00');

        $supplier = $services->forTenant($managerTenant)->procurement()->createSupplier(['name' => 'Iloilo Hardware'], $manager->id);
        $controller = new PurchaseOrdersController($services);

        // Role gates
        $this->assertHttpStatus(403, fn () => $controller->index(
            $this->staffRequest('GET', '/' . $org->slug . '/admin/procurement', $services, $cashierTenant, $cashier),
        ));
        $this->assertHttpStatus(403, fn () => $controller->createForm(
            $this->staffRequest('GET', '/' . $org->slug . '/admin/procurement/new', $services, $viewerTenant, $viewer),
        ));
        $this->assertHttpStatus(403, fn () => $controller->store(
            $this->staffRequest('POST', '/' . $org->slug . '/admin/procurement', $services, $viewerTenant, $viewer),
        ));

        // Validation on PO creation renders inline errors
        $badCreate = $controller->store($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement',
            $services,
            $managerTenant,
            $manager,
            [],
            [
                'supplier_id' => $supplier['id'],
                'branch_id' => $branchId,
                'lines' => [
                    ['variant_id' => $plainVariant, 'quantity' => '-3', 'unit_cost' => '80.00'],
                ],
            ],
        ));
        $this->assertSame(422, $badCreate->status());
        $this->assertStringContainsString('The quantity must be greater than zero.', $badCreate->body());

        // Create two-line PO and cancel one extra draft PO
        $created = $controller->store($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement',
            $services,
            $managerTenant,
            $manager,
            [],
            [
                'supplier_id' => $supplier['id'],
                'branch_id' => $branchId,
                'expected_on' => '2026-12-10',
                'notes' => 'Deliver to rear bay',
                'lines' => [
                    ['variant_id' => $plainVariant, 'quantity' => '5', 'unit_cost' => '80.00'],
                    ['variant_id' => $batchVariant, 'quantity' => '3', 'unit_cost' => '90.00'],
                ],
            ],
        ));
        $this->assertSame(302, $created->status());

        $list = $services->forTenant($managerTenant)->procurement()->listPurchaseOrders(
            \Gfh\Core\Support\ListQuery::fromRequest(new Request('GET', '/'), ['created' => 'p.created_at'], '-created'),
        );
        $poId = (string) $list['items'][0]['id'];
        $po = $services->forTenant($managerTenant)->procurement()->getPurchaseOrder($poId);
        $plainLineId = '';
        $batchLineId = '';
        foreach ($po['lines'] as $line) {
            if ($line['variant_id'] === $plainVariant) {
                $plainLineId = (string) $line['id'];
            } else {
                $batchLineId = (string) $line['id'];
            }
        }

        // Send PO
        $sendResp = $controller->send($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement/' . $poId . '/send',
            $services,
            $managerTenant,
            $manager,
            [],
            [],
            ['id' => $poId],
        ));
        $this->assertSame(302, $sendResp->status());

        // Batch-tracked line without batch_code fails with inline error and writes zero stock
        $missingBatch = $controller->receive($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement/' . $poId . '/receive',
            $services,
            $managerTenant,
            $manager,
            [],
            [
                'lines' => [
                    ['line_id' => $plainLineId, 'qty_received' => '4', 'qty_rejected' => '1', 'batch_code' => '', 'expires_on' => '', 'bin' => ''],
                    ['line_id' => $batchLineId, 'qty_received' => '2', 'qty_rejected' => '0', 'batch_code' => '', 'expires_on' => '2027-06-01', 'bin' => 'B-02'],
                ],
            ],
            ['id' => $poId],
        ));
        $this->assertSame(422, $missingBatch->status());
        $this->assertStringContainsString('Enter the batch code (up to 60 characters). This product tracks batches.', $missingBatch->body());
        $this->assertSame('2.000', $services->forTenant($managerTenant)->inventory()->onHand($branchId, $plainVariant));

        // Partial receive with rejected quantity & batch details updates stock + weighted average
        $partial = $controller->receive($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement/' . $poId . '/receive',
            $services,
            $managerTenant,
            $manager,
            [],
            [
                'lines' => [
                    ['line_id' => $plainLineId, 'qty_received' => '4', 'qty_rejected' => '1', 'batch_code' => '', 'expires_on' => '', 'bin' => ''],
                    ['line_id' => $batchLineId, 'qty_received' => '2', 'qty_rejected' => '0', 'batch_code' => 'LOT-GLUE-1', 'expires_on' => '2027-06-01', 'bin' => 'B-02'],
                ],
            ],
            ['id' => $poId],
        ));
        $this->assertSame(302, $partial->status());

        $poAfter = $services->forTenant($managerTenant)->procurement()->getPurchaseOrder($poId);
        $this->assertSame('partially_received', $poAfter['status']);
        $this->assertSame('6.000', $services->forTenant($managerTenant)->inventory()->onHand($branchId, $plainVariant));
        $this->assertSame('70.0000', (string) $this->shop->scalar('SELECT avg_cost FROM product_variants WHERE id = ?', [$plainVariant]));
        $this->assertSame('2.000', $services->forTenant($managerTenant)->inventory()->onHand($branchId, $batchVariant));
        $this->assertSame('LOT-GLUE-1', (string) $this->shop->scalar('SELECT batch_code FROM stock_batches WHERE variant_id = ? LIMIT 1', [$batchVariant]));
        $this->assertSame('2027-06-01', (string) $this->shop->scalar('SELECT expires_on FROM stock_batches WHERE variant_id = ? LIMIT 1', [$batchVariant]));
        $this->assertSame('B-02', (string) $this->shop->scalar('SELECT bin FROM stock_batches WHERE variant_id = ? LIMIT 1', [$batchVariant]));

        // Over-receiving a partially received line (1 remaining on batchLine) is refused with 422 and zero stock change
        $overReceive = $controller->receive($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement/' . $poId . '/receive',
            $services,
            $managerTenant,
            $manager,
            [],
            [
                'lines' => [
                    ['line_id' => $batchLineId, 'qty_received' => '2', 'qty_rejected' => '0', 'batch_code' => 'LOT-GLUE-X', 'expires_on' => '2027-08-01', 'bin' => 'B-03'],
                ],
            ],
            ['id' => $poId],
        ));
        $this->assertSame(422, $overReceive->status());
        $this->assertStringContainsString('Only 1 is still to be received on this line.', $overReceive->body());
        $this->assertSame('2.000', $services->forTenant($managerTenant)->inventory()->onHand($branchId, $batchVariant));

        // Second receive on the already fully settled plainLine (4 accepted + 1 rejected = 5 ordered) is refused
        $secondOnFullLine = $controller->receive($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement/' . $poId . '/receive',
            $services,
            $managerTenant,
            $manager,
            [],
            [
                'lines' => [
                    ['line_id' => $plainLineId, 'qty_received' => '1', 'qty_rejected' => '0', 'batch_code' => '', 'expires_on' => '', 'bin' => ''],
                ],
            ],
            ['id' => $poId],
        ));
        $this->assertSame(422, $secondOnFullLine->status());
        $this->assertStringContainsString('Only 0 is still to be received on this line.', $secondOnFullLine->body());
        $this->assertSame('6.000', $services->forTenant($managerTenant)->inventory()->onHand($branchId, $plainVariant));

        // Receive remaining 1 unit of batch line -> status becomes received
        $complete = $controller->receive($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement/' . $poId . '/receive',
            $services,
            $managerTenant,
            $manager,
            [],
            [
                'lines' => [
                    ['line_id' => $batchLineId, 'qty_received' => '1', 'qty_rejected' => '0', 'batch_code' => 'LOT-GLUE-2', 'expires_on' => '2027-08-01', 'bin' => 'B-03'],
                ],
            ],
            ['id' => $poId],
        ));
        $this->assertSame(302, $complete->status());
        $this->assertSame('received', $services->forTenant($managerTenant)->procurement()->getPurchaseOrder($poId)['status']);

        // Receiving again once the PO is fully received is also refused with 422
        $secondAfterReceived = $controller->receive($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement/' . $poId . '/receive',
            $services,
            $managerTenant,
            $manager,
            [],
            [
                'lines' => [
                    ['line_id' => $batchLineId, 'qty_received' => '1', 'qty_rejected' => '0', 'batch_code' => 'LOT-GLUE-3', 'expires_on' => '2027-09-01', 'bin' => 'B-03'],
                ],
            ],
            ['id' => $poId],
        ));
        $this->assertSame(422, $secondAfterReceived->status());
        $this->assertStringContainsString('Goods can only be received against a sent purchase order.', $secondAfterReceived->body());
        $this->assertSame('3.000', $services->forTenant($managerTenant)->inventory()->onHand($branchId, $batchVariant));

        // List supports search, filters & rejects invalid UUID filter cleanly
        $listPage = $controller->index($this->staffRequest(
            'GET',
            '/' . $org->slug . '/admin/procurement',
            $services,
            $viewerTenant,
            $viewer,
            ['status' => 'received', 'q' => $poAfter['po_number'], 'sort' => 'number'],
        ));
        $this->assertSame(200, $listPage->status());
        $this->assertStringContainsString((string) $poAfter['po_number'], $listPage->body());

        $badFilter = $controller->index($this->staffRequest(
            'GET',
            '/' . $org->slug . '/admin/procurement',
            $services,
            $viewerTenant,
            $viewer,
            ['supplier_id' => 'not-a-uuid'],
        ));
        $this->assertSame(422, $badFilter->status());

        // Cancel a separate draft PO
        $draft = $services->forTenant($managerTenant)->procurement()->createPurchaseOrder([
            'supplier_id' => $supplier['id'],
            'branch_id' => $branchId,
            'lines' => [['variant_id' => $plainVariant, 'quantity' => '1', 'unit_cost' => '80.00']],
        ], $manager->id);
        $cancelResp = $controller->cancel($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/procurement/' . $draft['id'] . '/cancel',
            $services,
            $managerTenant,
            $manager,
            [],
            [],
            ['id' => $draft['id']],
        ));
        $this->assertSame(302, $cancelResp->status());
        $this->assertSame('cancelled', $services->forTenant($managerTenant)->procurement()->getPurchaseOrder($draft['id'])['status']);
    }

    public function testOrdersListDetailProgressPaymentCancelAndExtensionPanelHook(): void
    {
        $services = $this->bootServices();
        $org = $this->kit->organization('ord-' . bin2hex(random_bytes(3)));
        $manager = $this->kit->member($org, 'manager@example.test', 'manager');
        $viewer = $this->kit->member($org, 'staff@example.test', 'staff');
        $cashier = $this->kit->member($org, 'cashier@example.test', 'cashier');

        $managerTenant = $this->makeTenant($org, 'manager');
        $viewerTenant = $this->makeTenant($org, 'staff');
        $cashierTenant = $this->makeTenant($org, 'cashier');

        $this->shop->steps();
        $branchId = $this->shop->branch('P1', true, false);
        $variantId = $this->shop->variant(['sku' => 'CHAIR-1', 'name' => 'Narrah Chair', 'price' => '1000.00']);
        $this->shop->stock($branchId, $variantId, '10', '400.00');
        $customer = $this->shop->customer('buyer@example.test');

        $tenantServices = $services->forTenant($managerTenant);
        $orderA = $tenantServices->orders()->createOrder($customer, [['variant_id' => $variantId, 'quantity' => '2']], ['method' => 'pickup', 'branch_id' => $branchId], []);
        $orderB = $tenantServices->orders()->createOrder($customer, [['variant_id' => $variantId, 'quantity' => '1']], ['method' => 'pickup', 'branch_id' => $branchId], []);
        $orderAId = (string) $orderA['id'];
        $orderBId = (string) $orderB['id'];

        $tenantServices->hooks()->add(Hooks::ORDER_DETAIL_PANELS, static function (array $panels, array $context): array {
            $panels[] = [
                'id' => 'warranty-panel',
                'title' => 'Warranty & <Check>',
                'subtitle' => 'Order ' . $context['order']['order_number'],
                'bodyHtml' => '<p>2-year frame warranty included.</p>',
            ];

            return $panels;
        });

        $controller = new OrdersController($services);

        // Cashier lacks sales:view; staff has sales:view only (200 on list, 403 on edit)
        $this->assertHttpStatus(403, fn () => $controller->index(
            $this->staffRequest('GET', '/' . $org->slug . '/admin/orders', $services, $cashierTenant, $cashier),
        ));
        $listView = $controller->index($this->staffRequest('GET', '/' . $org->slug . '/admin/orders', $services, $viewerTenant, $viewer));
        $this->assertSame(200, $listView->status());
        $this->assertStringContainsString((string) $orderA['order_number'], $listView->body());

        $this->assertHttpStatus(403, fn () => $controller->advance(
            $this->staffRequest('POST', '/' . $org->slug . '/admin/orders/' . $orderAId . '/progress', $services, $viewerTenant, $viewer, [], ['progress_key' => 'confirmed'], ['id' => $orderAId]),
        ));
        $this->assertHttpStatus(403, fn () => $controller->recordPayment(
            $this->staffRequest('POST', '/' . $org->slug . '/admin/orders/' . $orderAId . '/payments', $services, $viewerTenant, $viewer, [], ['method' => 'cash', 'amount' => '100'], ['id' => $orderAId]),
        ));

        // Detail renders progress pipeline, payment status separately, and escaped hook panel
        $detail = $controller->show($this->staffRequest('GET', '/' . $org->slug . '/admin/orders/' . $orderAId, $services, $managerTenant, $manager, [], [], ['id' => $orderAId]));
        $this->assertSame(200, $detail->status());
        $this->assertStringContainsString('Fulfilment progress', $detail->body());
        $this->assertStringContainsString('Payment status', $detail->body());
        $this->assertStringContainsString('Warranty &amp; &lt;Check&gt;', $detail->body());
        $this->assertStringContainsString('2-year frame warranty included.', $detail->body());

        // Payment validation: missing reference on gcash & overpayment both return 422 with inline field errors
        $missingRef = $controller->recordPayment($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/orders/' . $orderAId . '/payments',
            $services,
            $managerTenant,
            $manager,
            [],
            ['method' => 'gcash', 'amount' => '500.00', 'reference' => ''],
            ['id' => $orderAId],
        ));
        $this->assertSame(422, $missingRef->status());
        $this->assertStringContainsString('Enter the reference number for this payment.', $missingRef->body());

        $overpay = $controller->recordPayment($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/orders/' . $orderAId . '/payments',
            $services,
            $managerTenant,
            $manager,
            [],
            ['method' => 'cash', 'amount' => '9999.00', 'reference' => ''],
            ['id' => $orderAId],
        ));
        $this->assertSame(422, $overpay->status());
        $this->assertStringContainsString('This is more than the balance', $overpay->body());

        // Partial payment updates paid/balance without altering progress_key; second payment marks order paid
        $paidPart = $controller->recordPayment($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/orders/' . $orderAId . '/payments',
            $services,
            $managerTenant,
            $manager,
            [],
            ['method' => 'gcash', 'amount' => '1000.00', 'reference' => 'GC-8812'],
            ['id' => $orderAId],
        ));
        $this->assertSame(302, $paidPart->status());
        $afterPay = $tenantServices->orders()->getOrder($orderAId);
        $this->assertSame('1000.00', $afterPay['payment_summary']['paid']);
        $this->assertSame('1000.00', $afterPay['payment_summary']['balance']);
        $this->assertSame('placed', $afterPay['progress_key']);

        // Advancing progress step leaves payment_summary & payment_status unchanged
        $advanced = $controller->advance($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/orders/' . $orderAId . '/progress',
            $services,
            $managerTenant,
            $manager,
            [],
            ['progress_key' => 'confirmed'],
            ['id' => $orderAId],
        ));
        $this->assertSame(302, $advanced->status());
        $afterAdv = $tenantServices->orders()->getOrder($orderAId);
        $this->assertSame('confirmed', $afterAdv['progress_key']);
        $this->assertSame('1000.00', $afterAdv['payment_summary']['paid']);

        $paidFull = $controller->recordPayment($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/orders/' . $orderAId . '/payments',
            $services,
            $managerTenant,
            $manager,
            [],
            ['method' => 'cash', 'amount' => '1000.00', 'reference' => ''],
            ['id' => $orderAId],
        ));
        $this->assertSame(302, $paidFull->status());
        $afterFull = $tenantServices->orders()->getOrder($orderAId);
        $this->assertSame('paid', $afterFull['payment_status']);
        $this->assertSame('confirmed', $afterFull['progress_key']);

        $paidDetail = $controller->show($this->staffRequest('GET', '/' . $org->slug . '/admin/orders/' . $orderAId, $services, $managerTenant, $manager, [], [], ['id' => $orderAId]));
        $this->assertSame(200, $paidDetail->status());
        $this->assertStringContainsString('GC-8812', $paidDetail->body());
        $this->assertStringContainsString('Paid', $paidDetail->body());

        // Cancelling orderB requires a reason (422 when too short), releases reserved stock when valid
        $badCancel = $controller->cancel($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/orders/' . $orderBId . '/cancel',
            $services,
            $managerTenant,
            $manager,
            [],
            ['reason' => 'no'],
            ['id' => $orderBId],
        ));
        $this->assertSame(422, $badCancel->status());
        $this->assertStringContainsString('Give a reason (3 to 255 characters).', $badCancel->body());

        $okCancel = $controller->cancel($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/orders/' . $orderBId . '/cancel',
            $services,
            $managerTenant,
            $manager,
            [],
            ['reason' => 'Customer switched items'],
            ['id' => $orderBId],
        ));
        $this->assertSame(302, $okCancel->status());
        $this->assertNotNull($tenantServices->orders()->getOrder($orderBId)['cancelled_at']);

        // Filtered orders query
        $filtered = $controller->index($this->staffRequest(
            'GET',
            '/' . $org->slug . '/admin/orders',
            $services,
            $managerTenant,
            $manager,
            ['payment_status' => 'paid', 'cancelled' => 'false', 'q' => $orderA['order_number']],
        ));
        $this->assertSame(200, $filtered->status());
        $this->assertStringContainsString((string) $orderA['order_number'], $filtered->body());
        $this->assertStringNotContainsString((string) $orderB['order_number'], $filtered->body());
    }

    public function testCustomersListAndAdminOnlyPasswordResetLink(): void
    {
        $services = $this->bootServices();
        $org = $this->kit->organization('cust-' . bin2hex(random_bytes(3)));
        $admin = $this->kit->member($org, 'admin@example.test', 'admin');
        $manager = $this->kit->member($org, 'manager@example.test', 'manager');
        $viewer = $this->kit->member($org, 'staff@example.test', 'staff');
        $cashier = $this->kit->member($org, 'cashier@example.test', 'cashier');

        $adminTenant = $this->makeTenant($org, 'admin');
        $managerTenant = $this->makeTenant($org, 'manager');
        $viewerTenant = $this->makeTenant($org, 'staff');
        $cashierTenant = $this->makeTenant($org, 'cashier');

        $orgCustomerRepo = new OrganizationCustomerRepository($adminTenant->database());
        $services->auth()->registerCustomer('Elena Santos', 'elena@example.test', 'a-strong-pass-123', null, $orgCustomerRepo);
        $elenaId = (string) $this->shop->scalar('SELECT id FROM customers WHERE email = ?', ['elena@example.test']);
        $this->assertNotSame('', $elenaId);
        $elenaRow = $services->forTenant($adminTenant)->orders()->getCustomer($elenaId);

        $guest = $this->shop->customer('walkin@example.test', 'wholesale', 'business');
        $this->shop->steps();
        $branch = $this->shop->branch('B1');
        $variant = $this->shop->variant(['sku' => 'TBL-1', 'price' => '500.00']);
        $this->shop->stock($branch, $variant, '5', '200.00');
        $services->forTenant($adminTenant)->orders()->createOrder($elenaRow, [['variant_id' => $variant, 'quantity' => '1']], ['method' => 'pickup', 'branch_id' => $branch], []);

        $controller = new CustomersController($services);

        // Cashier lacks sales:view; staff can view customers (sales:view) and cannot issue reset links
        $this->assertHttpStatus(403, fn () => $controller->index(
            $this->staffRequest('GET', '/' . $org->slug . '/admin/customers', $services, $cashierTenant, $cashier),
        ));
        $list = $controller->index($this->staffRequest(
            'GET',
            '/' . $org->slug . '/admin/customers',
            $services,
            $viewerTenant,
            $viewer,
            ['has_account' => '1', 'sort' => '-spent'],
        ));
        $this->assertSame(200, $list->status());
        $this->assertStringContainsString('Elena Santos', $list->body());
        $this->assertStringNotContainsString('walkin@example.test', $list->body());
        $this->assertStringNotContainsString('Issue reset link', $list->body());

        // Manager has sales:edit but is NOT an organization admin -> PasswordResetService enforces 403!
        $this->assertHttpStatus(403, fn () => $controller->issueResetLink(
            $this->staffRequest('POST', '/' . $org->slug . '/admin/customers/' . $elenaId . '/reset-link', $services, $managerTenant, $manager, [], [], ['id' => $elenaId]),
        ));

        // Walk-in without a core account returns 422 cleanly for Admin
        $walkinResult = $controller->issueResetLink($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/customers/' . $guest['id'] . '/reset-link',
            $services,
            $adminTenant,
            $admin,
            [],
            [],
            ['id' => (string) $guest['id']],
        ));
        $this->assertSame(422, $walkinResult->status());
        $this->assertStringContainsString('No customer of this organization has that email address.', $walkinResult->body());

        // Organization Admin receives a usable one-time reset URL
        $resetResp = $controller->issueResetLink($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/customers/' . $elenaId . '/reset-link',
            $services,
            $adminTenant,
            $admin,
            [],
            [],
            ['id' => $elenaId],
        ));
        $this->assertSame(200, $resetResp->status());
        $this->assertStringContainsString('One-time password reset link ready', $resetResp->body());
        $this->assertMatchesRegularExpression('#/' . preg_quote($org->slug, '#') . '/reset-password\?token=([A-Za-z0-9_-]+)#', $resetResp->body());

        preg_match('#reset-password\?token=([A-Za-z0-9_-]+)#', $resetResp->body(), $m);
        $consumed = $services->resets()->consume($m[1], 'elena-fresh-pass-99');
        $this->assertSame('customer', $consumed['subject_type']);
        $this->assertHttpStatus(422, fn () => $services->resets()->consume($m[1], 'reuse-is-blocked'));
    }

    public function testPosSalesListNeedsReviewQueueAndResolve(): void
    {
        $services = $this->bootServices();
        $org = $this->kit->organization('pos-' . bin2hex(random_bytes(3)));
        $manager = $this->kit->member($org, 'manager@example.test', 'manager');
        $viewer = $this->kit->member($org, 'staff@example.test', 'staff');
        $cashier = $this->kit->member($org, 'cashier@example.test', 'cashier');

        $managerTenant = $this->makeTenant($org, 'manager');
        $viewerTenant = $this->makeTenant($org, 'staff');
        $cashierTenant = $this->makeTenant($org, 'cashier');

        $branchId = $this->shop->branch('S1');
        $terminalId = $this->shop->terminal($branchId, 'T1');
        $shiftId = $this->shop->shift($terminalId, '500.00');
        $variantId = $this->shop->variant(['sku' => 'LAMP-1', 'name' => 'Capiz Lamp', 'price' => '250.00']);
        // Stock is 1, so selling 3 creates a needs_review sale during offline POS sync
        $this->shop->stock($branchId, $variantId, '1', '100.00');

        $pos = $services->forTenant($managerTenant)->pos();
        $okSale = $pos->syncSale([
            'id' => Uuid::v4(),
            'receipt_number' => 'T1-000001',
            'terminal_id' => $terminalId,
            'shift_id' => $shiftId,
            'sold_at' => (new \DateTimeImmutable())->format('c'),
            'lines' => [['variant_id' => $variantId, 'quantity' => '1', 'unit_price' => '250.00']],
            'payments' => [['method' => 'cash', 'amount' => '250.00']],
        ], $cashier->id);
        $this->assertSame('completed', $okSale['status']);

        $flaggedSale = $pos->syncSale([
            'id' => Uuid::v4(),
            'receipt_number' => 'T1-000002',
            'terminal_id' => $terminalId,
            'shift_id' => $shiftId,
            'sold_at' => (new \DateTimeImmutable())->format('c'),
            'lines' => [['variant_id' => $variantId, 'quantity' => '3', 'unit_price' => '250.00']],
            'payments' => [['method' => 'cash', 'amount' => '750.00']],
        ], $cashier->id);
        $this->assertSame('needs_review', $flaggedSale['status']);
        $flaggedId = (string) $flaggedSale['sale_id'];

        $controller = new PosSalesController($services);

        // Queue filter + badge count
        $queue = $controller->index($this->staffRequest(
            'GET',
            '/' . $org->slug . '/admin/pos-sales',
            $services,
            $viewerTenant,
            $viewer,
            ['status' => 'needs_review'],
        ));
        $this->assertSame(200, $queue->status());
        $this->assertStringContainsString('Needs review (1)', $queue->body());
        $this->assertStringContainsString('T1-000002', $queue->body());
        $this->assertStringNotContainsString('T1-000001', $queue->body());

        // Detail renders review reason; Staff (sales:view) cannot resolve review
        $saleDetail = $controller->show($this->staffRequest('GET', '/' . $org->slug . '/admin/pos-sales/' . $flaggedId, $services, $viewerTenant, $viewer, [], [], ['id' => $flaggedId]));
        $this->assertSame(200, $saleDetail->status());
        $this->assertStringContainsString('Needs review', $saleDetail->body());
        $this->assertStringContainsString('Capiz Lamp', $saleDetail->body());

        $this->assertHttpStatus(403, fn () => $controller->resolve(
            $this->staffRequest('POST', '/' . $org->slug . '/admin/pos-sales/' . $flaggedId . '/resolve', $services, $viewerTenant, $viewer, [], ['note' => 'Checked stock'], ['id' => $flaggedId]),
        ));

        // Short note fails with 422 inline field error
        $shortNote = $controller->resolve($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/pos-sales/' . $flaggedId . '/resolve',
            $services,
            $managerTenant,
            $manager,
            [],
            ['note' => 'ok'],
            ['id' => $flaggedId],
        ));
        $this->assertSame(422, $shortNote->status());
        $this->assertStringContainsString('Write a short note on what you checked (3 to 255 characters).', $shortNote->body());

        // Valid note marks sale completed and clears queue
        $resolved = $controller->resolve($this->staffRequest(
            'POST',
            '/' . $org->slug . '/admin/pos-sales/' . $flaggedId . '/resolve',
            $services,
            $managerTenant,
            $manager,
            [],
            ['note' => 'Counted bin and recorded matching stock adjustment'],
            ['id' => $flaggedId],
        ));
        $this->assertSame(302, $resolved->status());
        $this->assertSame('completed', $pos->getSale($flaggedId)['status']);
        $this->assertSame(0, $pos->needsReviewCount());
    }
}
