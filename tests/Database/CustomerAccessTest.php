<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Service\CustomerAccessService;
use Gfh\Tests\Support\DatabaseTestCase;

final class CustomerAccessTest extends DatabaseTestCase
{
    public function testACustomerCanOnlyReadTheirOwnOrders(): void
    {
        $ana = $this->kit->auth->registerCustomer('Ana Reyes', 'ana@example.test', 'a-long-password-1', null, $this->kit->orgCustomers);
        $ben = $this->kit->auth->registerCustomer('Ben Cruz', 'ben@example.test', 'a-long-password-1', null, $this->kit->orgCustomers);
        $orderId = $this->createOrderFor($ana->id);
        $service = new CustomerAccessService($this->kit->orgCustomers);

        $service->assertCanViewOrder($ana, $orderId);

        // Another customer gets "not found", the same answer as for an order that does not exist.
        $other = $this->assertHttpStatus(404, fn () => $service->assertCanViewOrder($ben, $orderId));
        $missing = $this->assertHttpStatus(404, fn () => $service->assertCanViewOrder($ben, '3f2b8c1e-9d4a-4b7e-8a61-5c0d2e9f7a10'));
        $this->assertSame($other->getMessage(), $missing->getMessage());
    }

    public function testBadOrderIdsAndInactiveCustomersAreRefused(): void
    {
        $ana = $this->kit->auth->registerCustomer('Ana Reyes', 'ana@example.test', 'a-long-password-1', null, $this->kit->orgCustomers);
        $orderId = $this->createOrderFor($ana->id);
        $service = new CustomerAccessService($this->kit->orgCustomers);

        $this->assertHttpStatus(404, fn () => $service->assertCanViewOrder($ana, 'not-a-uuid'));
        $this->assertHttpStatus(404, fn () => $service->assertCanViewOrder($ana, "' OR 1=1 --"));

        $inactive = new CustomerAccount($ana->id, $ana->fullName, $ana->email, null, 'inactive');
        $this->assertHttpStatus(404, fn () => $service->assertCanViewOrder($inactive, $orderId));
    }

    private function createOrderFor(string $coreCustomerId): string
    {
        $local = $this->kit->orgCustomers->findByCoreId($coreCustomerId);
        $this->assertNotNull($local);

        $branch = $this->org->prepare('INSERT INTO branches (code, name) VALUES (:code, :name) RETURNING id');
        $branch->execute(['code' => 'B' . bin2hex(random_bytes(3)), 'name' => 'Test Branch']);
        $branchId = (string) $branch->fetchColumn();

        $this->org->exec("INSERT INTO order_progress_steps (key, label, sort_order, maps_to) VALUES ('placed', 'Placed', 1, 'open') ON CONFLICT (key) DO NOTHING");

        $order = $this->org->prepare(
            'INSERT INTO orders (order_number, customer_id, progress_key, fulfillment_method, pickup_branch_id, subtotal, vat_amount, total) '
            . "VALUES (:number, :customer, 'placed', 'pickup', :branch, 112.00, 12.00, 112.00) RETURNING id",
        );
        $order->execute(['number' => 'SO-' . bin2hex(random_bytes(3)), 'customer' => $local['id'], 'branch' => $branchId]);

        return (string) $order->fetchColumn();
    }
}
