<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Support\Uuid;
use Gfh\Tests\Support\DatabaseTestCase;

final class PosTest extends DatabaseTestCase
{
    private string $branch = '';
    private string $terminal = '';
    private string $shift = '';
    private string $variant = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = $this->shop->branch('S1');
        $this->terminal = $this->shop->terminal($this->branch, 'T3');
        $this->shift = $this->shop->shift($this->terminal, '1000.00');
        $this->variant = $this->shop->variant(['sku' => 'COFFEE-250', 'price' => '112.00']);
        $this->shop->stock($this->branch, $this->variant, '5', '50.00');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function sale(array $overrides = []): array
    {
        return $overrides + [
            'id' => Uuid::v4(),
            'receipt_number' => 'T3-000001',
            'terminal_id' => $this->terminal,
            'shift_id' => $this->shift,
            'sold_at' => (new \DateTimeImmutable())->format('c'),
            'lines' => [['variant_id' => $this->variant, 'quantity' => '1', 'unit_price' => '112.00']],
            'payments' => [['method' => 'cash', 'amount' => '112.00']],
        ];
    }

    private function numberOf(string $sql): int
    {
        return (int) $this->shop->scalar($sql);
    }

    public function testASaleUploadedTwiceCreatesOneSaleAndOneSetOfMovements(): void
    {
        $payload = $this->sale();
        $pos = $this->shop->services->pos();

        $first = $pos->syncSale($payload);
        $second = $pos->syncSale($payload);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['sale_id'], $second['sale_id']);
        $this->assertSame('completed', $first['status']);
        $this->assertSame(1, $this->numberOf('SELECT COUNT(*) FROM pos_sales'));
        $this->assertSame(1, $this->numberOf('SELECT COUNT(*) FROM pos_sale_lines'));
        $this->assertSame(1, $this->numberOf('SELECT COUNT(*) FROM payments WHERE pos_sale_id IS NOT NULL'));
        $this->assertSame(1, $this->numberOf("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'sale_pos'"));
        $this->assertSame('4.000', $this->shop->services->inventory()->onHand($this->branch, $this->variant));
    }

    public function testTotalsAndVatAreWorkedOutOnTheServerAndCostIsSnapshotted(): void
    {
        $sale = $this->shop->services->pos()->syncSale($this->sale() + ['total' => '1.00']);

        $stored = $this->shop->services->pos()->getSale($sale['sale_id']);
        $this->assertSame('112.00', $stored['total']);
        $this->assertSame('12.00', $stored['vat_amount']);
        $this->assertSame('112.00', $stored['subtotal']);
        $this->assertSame('50.0000', $stored['lines'][0]['unit_cost']);
        $this->assertSame('COFFEE-250', $stored['lines'][0]['sku']);
    }

    public function testAnOfflineSaleKeepsThePriceItWasSoldAt(): void
    {
        $sale = $this->shop->services->pos()->syncSale($this->sale([
            'lines' => [['variant_id' => $this->variant, 'quantity' => '1', 'unit_price' => '100.00']],
            'payments' => [['method' => 'cash', 'amount' => '100.00']],
        ]));

        $stored = $this->shop->services->pos()->getSale($sale['sale_id']);
        $this->assertSame('100.00', $stored['total']);
        $this->assertSame('10.71', $stored['vat_amount']);
    }

    public function testAnOversellIsKeptAndFlaggedForReviewNotRejected(): void
    {
        $pos = $this->shop->services->pos();

        $result = $pos->syncSale($this->sale([
            'lines' => [['variant_id' => $this->variant, 'quantity' => '8', 'unit_price' => '112.00']],
            'payments' => [['method' => 'cash', 'amount' => '896.00']],
        ]));

        $this->assertSame('needs_review', $result['status']);
        $this->assertStringContainsString('COFFEE-250: sold 3 more than the stock on record', (string) $result['review_reason']);
        $this->assertSame(1, $this->numberOf('SELECT COUNT(*) FROM pos_sales'), 'the sale is kept');
        $this->assertSame('-3.000', $this->shop->services->inventory()->onHand($this->branch, $this->variant));
        $this->assertSame(1, $this->numberOf("SELECT COUNT(*) FROM audit_log WHERE action = 'pos.sale_flagged'"));

        $request = new \Gfh\Core\Http\Request('GET', '/x', ['status' => 'needs_review']);
        $queue = $pos->listSales(\Gfh\Core\Support\ListQuery::fromRequest($request, ['sold' => 's.sold_at'], 'sold', ['status']));
        $this->assertSame(1, $queue['pagination']['total']);

        $resolved = $pos->resolveReview($result['sale_id'], 'Counted the shelf; stock record was wrong', null);
        $this->assertSame('completed', $resolved['status']);
        $this->assertHttpStatus(422, fn () => $pos->resolveReview($result['sale_id'], 'Again please', null));
    }

    public function testASaleThatAnInvalidPaymentTotalCannotBeStoredPartially(): void
    {
        $error = $this->assertHttpStatus(422, fn () => $this->shop->services->pos()->syncSale($this->sale([
            'payments' => [['method' => 'cash', 'amount' => '100.00']],
        ])));

        $this->assertArrayHasKey('payments', $error->fields());
        $this->assertSame(0, $this->numberOf('SELECT COUNT(*) FROM pos_sales'));
        $this->assertSame(0, $this->numberOf("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'sale_pos'"));
    }

    public function testReceiptNumbersCarryTheTerminalCodeAndAreUnique(): void
    {
        $pos = $this->shop->services->pos();

        $wrongTerminal = $this->assertHttpStatus(422, fn () => $pos->syncSale($this->sale(['receipt_number' => 'T9-000001'])));
        $this->assertArrayHasKey('receipt_number', $wrongTerminal->fields());
        $this->assertArrayHasKey('receipt_number', $this->assertHttpStatus(422, fn () => $pos->syncSale($this->sale(['receipt_number' => 'T3-12'])))->fields());

        $pos->syncSale($this->sale());
        $this->assertHttpStatus(409, fn () => $pos->syncSale($this->sale()));

        // A second terminal numbers its own sales: the same counter under another prefix is fine.
        $other = $this->shop->terminal($this->branch, 'T4');
        $otherShift = $this->shop->shift($other);
        $ok = $pos->syncSale($this->sale(['receipt_number' => 'T4-000001', 'terminal_id' => $other, 'shift_id' => $otherShift]));
        $this->assertSame('T4-000001', $ok['receipt_number']);
    }

    public function testTheNextReceiptNumberContinuesFromTheLastOne(): void
    {
        $pos = $this->shop->services->pos();
        $pos->syncSale($this->sale(['receipt_number' => 'T3-000041']));
        $pos->closeShift($this->shift, '1112.00', null);

        $result = $pos->openShift($this->terminal, Uuid::v4(), '500.00');

        $this->assertSame('T3-000042', $result['next_receipt_number']);
    }

    public function testSeniorAndPwdDiscountsExistButAreInactiveUntilSwitchedOn(): void
    {
        $this->shop->discountRule('senior', '20.00', true, false);
        $pos = $this->shop->services->pos();
        $payload = $this->sale([
            'discount' => ['key' => 'senior', 'id_ref_last4' => '4821'],
            'payments' => [['method' => 'cash', 'amount' => '80.00']],
        ]);

        $this->assertArrayHasKey('discount', $this->assertHttpStatus(422, fn () => $pos->syncSale($payload))->fields());

        $this->org->exec("UPDATE discount_rules SET active = true WHERE key = 'senior'");
        $sale = $pos->getSale($pos->syncSale($payload)['sale_id']);

        $this->assertSame('80.00', $sale['total']);
        $this->assertSame('32.00', $sale['discount_total']);
        $this->assertSame('0.00', $sale['vat_amount']);
        $this->assertSame('4821', $sale['discounts'][0]['id_ref_last4']);
    }

    public function testSplitPaymentsMustAddUpToTheTotal(): void
    {
        $sale = $this->shop->services->pos()->syncSale($this->sale([
            'payments' => [['method' => 'cash', 'amount' => '100.00'], ['method' => 'gcash', 'amount' => '12.00', 'reference' => 'GC-778899']],
        ]));

        $this->assertSame('completed', $sale['status']);
        $this->assertCount(2, $this->shop->services->pos()->getSale($sale['sale_id'])['payments']);
    }

    public function testClosingAShiftComparesCountedCashWithTheCashTaken(): void
    {
        $pos = $this->shop->services->pos();
        $pos->syncSale($this->sale());
        $pos->syncSale($this->sale([
            'receipt_number' => 'T3-000002',
            'lines' => [['variant_id' => $this->variant, 'quantity' => '1', 'unit_price' => '50.00']],
            'payments' => [['method' => 'gcash', 'amount' => '50.00', 'reference' => 'GC-100200']],
        ]));

        $closed = $pos->closeShift($this->shift, '1100.00', null);

        $this->assertSame('closed', $closed['status']);
        $this->assertSame('1112.00', $closed['expected_cash'], '1000 opening + 112 cash; the GCash payment is not in the drawer');
        $this->assertSame('-12.00', $closed['variance']);
        $this->assertArrayHasKey('shift', $this->assertHttpStatus(422, fn () => $pos->closeShift($this->shift, '1112.00', null))->fields());
    }

    public function testATerminalHasOneOpenShiftAtATime(): void
    {
        $this->assertHttpStatus(409, fn () => $this->shop->services->pos()->openShift($this->terminal, Uuid::v4(), '500.00'));
        $this->assertArrayHasKey('opening_cash', $this->assertHttpStatus(422, fn () => $this->shop->services->pos()->openShift($this->terminal, Uuid::v4(), 'lots'))->fields());
    }

    public function testASaleMadeAfterTheShiftWasClosedIsRefusedButOneMadeBeforeIsKept(): void
    {
        $pos = $this->shop->services->pos();
        $before = (new \DateTimeImmutable('-1 minute'))->format('c');
        $pos->closeShift($this->shift, '1000.00', null);

        $kept = $pos->syncSale($this->sale(['sold_at' => $before]));
        $this->assertSame('completed', $kept['status']);

        $this->assertHttpStatus(409, fn () => $pos->syncSale($this->sale(['receipt_number' => 'T3-000002', 'sold_at' => (new \DateTimeImmutable('+5 minutes'))->format('c')])));
    }

    public function testTheSnapshotCarriesWhatATerminalNeedsToSellOffline(): void
    {
        $this->shop->discountRule('senior', '20.00', true, false);
        $this->shop->discountRule('promo5', '5.00', false, true);
        $this->org->prepare("INSERT INTO price_tiers (variant_id, basis, label, min_qty, price) VALUES (?, 'quantity', '3 or more', 3, 100.00)")->execute([$this->variant]);
        $this->org->prepare("INSERT INTO price_tiers (variant_id, basis, tier_key, label, min_qty, price) VALUES (?, 'customer_tier', 'Gold', 'Gold', 1, 90.00)")->execute([$this->variant]);

        $snapshot = $this->shop->services->pos()->snapshot($this->terminal);

        $this->assertSame('T3', $snapshot['terminal']['code']);
        $this->assertSame(12, $snapshot['vat_rate_percent']);
        $this->assertContains('cash', $snapshot['payment_methods']);
        $this->assertSame(['promo5'], array_column($snapshot['discount_rules'], 'key'), 'only active rules');
        $product = $snapshot['products'][0];
        $this->assertSame('112.00', $product['price']);
        $this->assertSame('5.000', $product['on_hand']);
        $this->assertCount(1, $product['tiers'], 'only quantity tiers go to the terminal');
        $this->assertSame(40, strlen($snapshot['etag']));
    }
}
