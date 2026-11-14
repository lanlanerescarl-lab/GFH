<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Tests\Support\DatabaseTestCase;

final class ProcurementTest extends DatabaseTestCase
{
    private string $branch = '';
    private string $supplier = '';
    private string $plain = '';
    private string $tracked = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = $this->shop->branch('W1', false);
        $this->plain = $this->shop->variant(['sku' => 'FLOUR-1KG']);
        $this->tracked = $this->shop->variant(['sku' => 'MILK-1L', 'track_batches' => true]);
        $this->supplier = $this->shop->services->procurement()->createSupplier(['name' => 'Negros Contract Packers', 'contact_email' => 'Orders@Packers.example.test', 'lead_time_days' => '5'], null)['id'];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed> the line for a SKU (lines of one order share a creation time, so order is not reliable)
     */
    private function line(array $order, string $sku): array
    {
        foreach ($order['lines'] as $line) {
            if ($line['sku'] === $sku) {
                return $line;
            }
        }

        throw new \RuntimeException('No line for ' . $sku);
    }

    /** @return array<string, mixed> a sent purchase order for 10 flour at 40.00 and 5 milk at 100.00 */
    private function sentOrder(): array
    {
        $procurement = $this->shop->services->procurement();
        $created = $procurement->createPurchaseOrder([
            'supplier_id' => $this->supplier,
            'branch_id' => $this->branch,
            'expected_on' => '2026-12-01',
            'lines' => [
                ['variant_id' => $this->plain, 'quantity' => '10', 'unit_cost' => '40.00'],
                ['variant_id' => $this->tracked, 'quantity' => '5', 'unit_cost' => '100.00'],
            ],
        ], null);
        $procurement->sendPurchaseOrder($created['id'], null);

        return $procurement->getPurchaseOrder($created['id']);
    }

    public function testSuppliersAreValidatedAndUnique(): void
    {
        $procurement = $this->shop->services->procurement();

        $this->assertSame('orders@packers.example.test', $procurement->getSupplier($this->supplier)['contact_email']);
        $error = $this->assertHttpStatus(422, fn () => $procurement->createSupplier(['name' => 'negros contract packers', 'contact_email' => 'nope', 'lead_time_days' => '999'], null));
        $this->assertSame(['name', 'contact_email', 'lead_time_days'], array_keys($error->fields()));
    }

    public function testPurchaseOrdersAreNumberedAndValidated(): void
    {
        $procurement = $this->shop->services->procurement();
        $order = $this->sentOrder();

        $this->assertMatchesRegularExpression('/^PO-\d{4}-0001$/', $order['po_number']);
        $this->assertCount(2, $order['lines']);

        $error = $this->assertHttpStatus(422, fn () => $procurement->createPurchaseOrder([
            'supplier_id' => $this->supplier, 'branch_id' => $this->branch,
            'lines' => [['variant_id' => $this->plain, 'quantity' => '1.5', 'unit_cost' => '-1']],
        ], null));
        $this->assertArrayHasKey('lines.0.quantity', $error->fields());
    }

    public function testReceivingUpdatesStockAndAverageCostAndRecordsRejectedQuantities(): void
    {
        $procurement = $this->shop->services->procurement();
        $inventory = $this->shop->services->inventory();
        $order = $this->sentOrder();
        $flour = $this->line($order, 'FLOUR-1KG');
        $milk = $this->line($order, 'MILK-1L');

        // Milk tracks batches, so a batch code is required and the whole receipt is refused without it.
        $missing = $this->assertHttpStatus(422, fn () => $procurement->receive($order['id'], [
            ['line_id' => $flour['id'], 'qty_received' => '6', 'qty_rejected' => '1'],
            ['line_id' => $milk['id'], 'qty_received' => '5'],
        ], null));
        $this->assertArrayHasKey('lines.1.batch_code', $missing->fields());
        $this->assertSame('0.000', $inventory->onHand($this->branch, $this->plain), 'a refused receipt changes nothing');

        $partial = $procurement->receive($order['id'], [
            ['line_id' => $flour['id'], 'qty_received' => '6', 'qty_rejected' => '1'],
            ['line_id' => $milk['id'], 'qty_received' => '5', 'batch_code' => 'MILK-2611-A', 'expires_on' => '2026-12-20', 'bin' => 'COLD-2'],
        ], null);

        $this->assertSame('partially_received', $partial['status']);
        $this->assertSame('6.000', $inventory->onHand($this->branch, $this->plain), 'rejected goods do not enter stock');
        $this->assertSame('5.000', $inventory->onHand($this->branch, $this->tracked));
        $this->assertSame('40.0000', (string) $this->shop->scalar('SELECT avg_cost FROM product_variants WHERE id = ?', [$this->plain]));
        $this->assertSame('2026-12-20', (string) $this->shop->scalar("SELECT expires_on FROM stock_batches WHERE batch_code = 'MILK-2611-A'"));
        $this->assertSame('COLD-2', (string) $this->shop->scalar("SELECT bin FROM stock_batches WHERE batch_code = 'MILK-2611-A'"));

        // 10 ordered, 6 received and 1 rejected: 3 left to come.
        $tooMuch = $this->assertHttpStatus(422, fn () => $procurement->receive($order['id'], [['line_id' => $flour['id'], 'qty_received' => '4']], null));
        $this->assertStringContainsString('Only 3 is still', $tooMuch->fields()['lines.0.qty_received']);

        $done = $procurement->receive($order['id'], [['line_id' => $flour['id'], 'qty_received' => '3']], null);
        $this->assertSame('received', $done['status']);
        $this->assertSame('9.000', $inventory->onHand($this->branch, $this->plain));
        $this->assertSame(3, (int) $this->shop->scalar("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'receipt'"));
    }

    public function testGoodsCanOnlyBeReceivedAgainstASentOrder(): void
    {
        $procurement = $this->shop->services->procurement();
        $created = $procurement->createPurchaseOrder([
            'supplier_id' => $this->supplier, 'branch_id' => $this->branch,
            'lines' => [['variant_id' => $this->plain, 'quantity' => '2', 'unit_cost' => '40.00']],
        ], null);
        $line = $procurement->getPurchaseOrder($created['id'])['lines'][0];

        $this->assertHttpStatus(422, fn () => $procurement->receive($created['id'], [['line_id' => $line['id'], 'qty_received' => '2']], null));

        $procurement->cancelPurchaseOrder($created['id'], null);
        $this->assertSame('cancelled', $procurement->getPurchaseOrder($created['id'])['status']);
        $this->assertHttpStatus(422, fn () => $procurement->sendPurchaseOrder($created['id'], null));
    }

    public function testAnAveragePriceBuildsAcrossPurchaseOrders(): void
    {
        $procurement = $this->shop->services->procurement();
        $first = $this->sentOrder();
        $procurement->receive($first['id'], [['line_id' => $this->line($first, 'FLOUR-1KG')['id'], 'qty_received' => '10']], null);

        $second = $procurement->createPurchaseOrder([
            'supplier_id' => $this->supplier, 'branch_id' => $this->branch,
            'lines' => [['variant_id' => $this->plain, 'quantity' => '10', 'unit_cost' => '60.00']],
        ], null);
        $procurement->sendPurchaseOrder($second['id'], null);
        $procurement->receive($second['id'], [['line_id' => $procurement->getPurchaseOrder($second['id'])['lines'][0]['id'], 'qty_received' => '10']], null);

        $this->assertSame('50.0000', (string) $this->shop->scalar('SELECT avg_cost FROM product_variants WHERE id = ?', [$this->plain]), '(10 x 40 + 10 x 60) / 20');
    }
}
