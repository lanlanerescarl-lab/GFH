<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Support\Uuid;
use Gfh\Tests\Support\DatabaseTestCase;

final class ReportTest extends DatabaseTestCase
{
    private string $branch = '';
    private string $terminal = '';
    private string $shift = '';
    private int $receipt = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = $this->shop->branch('S1');
        $this->terminal = $this->shop->terminal($this->branch, 'T3');
        $this->shift = $this->shop->shift($this->terminal);
    }

    private function sell(string $variant, string $price = '112.00'): void
    {
        $this->shop->services->pos()->syncSale([
            'id' => Uuid::v4(),
            'receipt_number' => sprintf('T3-%06d', ++$this->receipt),
            'terminal_id' => $this->terminal,
            'shift_id' => $this->shift,
            'sold_at' => (new \DateTimeImmutable())->format('c'),
            'lines' => [['variant_id' => $variant, 'quantity' => '1', 'unit_price' => $price]],
            'payments' => [['method' => 'cash', 'amount' => $price]],
        ]);
    }

    public function testGrossProfitIsNetSalesLessCostOfTheLinesThatHaveACost(): void
    {
        $costed = $this->shop->variant(['sku' => 'WITH-COST']);
        $this->shop->stock($this->branch, $costed, '5', '50.00');
        $uncosted = $this->shop->variant(['sku' => 'NO-COST']);
        $this->shop->services->inventory()->adjust($this->branch, $uncosted, '5', 'Opening count', null);

        $this->sell($costed);
        $today = $this->shop->services->reports()->dashboard()['periods']['today'];
        $this->assertSame('100.00', $today['net_sales'], '112.00 less 12.00 VAT');
        $this->assertSame('50.00', $today['cost']);
        $this->assertSame('50.00', $today['gross_profit']);
        $this->assertSame('100.00', $today['cost_complete_pct']);
        $this->assertSame(1, $today['orders']);

        // A sale of an item with no known cost counts as sales but not as profit, and lowers completeness.
        $this->sell($uncosted);
        $today = $this->shop->services->reports()->dashboard()['periods']['today'];
        $this->assertSame('200.00', $today['net_sales']);
        $this->assertSame('50.00', $today['gross_profit'], 'the uncosted line is left out of profit');
        $this->assertSame('50.00', $today['cost_complete_pct']);
        $this->assertSame(2, $today['orders']);
    }

    public function testVoidedAndCancelledSalesAreNotCounted(): void
    {
        $variant = $this->shop->variant();
        $this->shop->stock($this->branch, $variant, '5', '50.00');
        $this->sell($variant);
        $this->org->exec("UPDATE pos_sales SET status = 'voided'");

        $this->assertSame('0.00', $this->shop->services->reports()->dashboard()['periods']['today']['net_sales']);
    }

    public function testPeriodsSeriesAndKpisComeFromTheContractViews(): void
    {
        $low = $this->shop->variant(['sku' => 'LOW-ONE', 'reorder' => '5']);
        $this->shop->variant(['sku' => 'NO-STOCK']);
        $this->shop->stock($this->branch, $low, '3', '10.00');

        $dashboard = $this->shop->services->reports()->dashboard();

        $this->assertSame(1, $dashboard['kpis']['low_stock_variants']);
        $this->assertSame(1, $dashboard['kpis']['out_of_stock_variants']);
        $this->assertSame([], $dashboard['series']);
        $this->assertSame('0.00', $dashboard['periods']['month_to_date']['gross_profit']);
        $this->assertNull($dashboard['periods']['last_30_days']['cost_complete_pct'], 'no sales, so no completeness to report');
        $this->assertSame([], $dashboard['widgets']);
        $this->assertContains('Low stock: LOW-ONE has 3 left (reorder at 5)', array_column($dashboard['alerts'], 'message'));

        $variant = $this->shop->variant();
        $this->shop->stock($this->branch, $variant, '2', '40.00');
        $this->sell($variant);
        $dashboard = $this->shop->services->reports()->dashboard();
        $this->assertCount(1, $dashboard['series']);
        $this->assertSame('100.00', $dashboard['series'][0]['net_revenue']);
        $this->assertSame('100.00', $dashboard['periods']['last_30_days']['net_sales']);
    }
}
