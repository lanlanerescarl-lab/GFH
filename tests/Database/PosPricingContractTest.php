<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Service\PricingService;
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\Uuid;
use Gfh\Tests\Support\DatabaseTestCase;

/**
 * The POS pricing contract (tests/fixtures/pos-pricing.json). The same cases are run here through the real server
 * pricing and sale recomputation, and in tests/js/pricing.test.js through the browser's pricing.js. The expected
 * figures were worked out independently with exact fractions, so a drift in either implementation fails a test.
 */
final class PosPricingContractTest extends DatabaseTestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cases(): array
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/pos-pricing.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixture['cases'] as $index => $case) {
            $cases[sprintf('%02d %s', $index + 1, $case['name'])] = [$case + ['index' => $index]];
        }

        return $cases;
    }

    public function testTheFixtureHasAtLeastTwentyFiveCases(): void
    {
        $this->assertGreaterThanOrEqual(25, count(self::cases()));
    }

    /**
     * @dataProvider cases
     * @param array<string, mixed> $case
     */
    public function testServerPricingAndSaleRecomputationMatchTheContract(array $case): void
    {
        $branch = $this->shop->branch('S1');
        $terminal = $this->shop->terminal($branch, 'T3');
        $shift = $this->shop->shift($terminal, '1000.00');
        $pos = $this->shop->services->pos();

        // The products of the case, as real catalog rows with their quantity tiers.
        $variants = [];
        foreach ($case['products'] as $key => $product) {
            $variantId = $this->shop->variant([
                'sku' => 'FX-' . strtoupper($key), 'price' => $product['price'], 'decimals' => $product['decimals'],
                'step' => $product['step'], 'min' => $product['min'],
            ]);
            foreach ($product['tiers'] as $tier) {
                $this->shop->scalar(
                    "INSERT INTO price_tiers (variant_id, basis, label, min_qty, price) VALUES (?, 'quantity', ?, ?, ?) RETURNING id",
                    [$variantId, $tier['label'], $tier['min_qty'], $tier['price']],
                );
            }
            $this->shop->stock($branch, $variantId, '2000', '1.00');
            $variants[$key] = $variantId;
        }

        // The POS ticket lists a product once, so entries for the same product are added together first.
        $merged = [];
        foreach ($case['lines'] as $line) {
            $merged[$line['product']] = ($merged[$line['product']] ?? 0) + (Decimal::parse($line['quantity'], 3) ?? 0);
        }

        $rule = null;
        $ruleKey = null;
        if ($case['discount'] !== null) {
            $ruleKey = 'fx' . $case['index'];
            $this->shop->discountRule($ruleKey, $case['discount']['percent'], (bool) $case['discount']['vat_exempt'], true);
            $rule = ['percent' => $case['discount']['percent'], 'vat_exempt' => $case['discount']['vat_exempt']];
        }

        // 1. The server's own price for each line (the tier choice), and its totals for the sale.
        $saleLines = [];
        $totalLines = [];
        foreach ($merged as $key => $quantityMilli) {
            $variant = ['retail_price' => $case['products'][$key]['price']];
            $tiers = array_map(static fn (array $tier): array => ['basis' => 'quantity', 'min_qty' => $tier['min_qty'], 'price' => $tier['price'], 'label' => $tier['label'], 'tier_key' => null], $case['products'][$key]['tiers']);
            $best = PricingService::bestPrice($variant, $tiers, $quantityMilli, null);
            $saleLines[$key] = ['variant_id' => $variants[$key], 'quantity' => Decimal::format($quantityMilli, 3), 'unit_price' => Decimal::format($best['price'], 2)];
            $totalLines[] = ['quantity_milli' => $quantityMilli, 'unit_price' => $best['price'], 'discount' => 0];
            $expectedLine = $this->expectedLine($case, $key);
            $this->assertSame($expectedLine['unit_price'], Decimal::format($best['price'], 2), "unit price of {$key}");
            $this->assertSame($expectedLine['tier_label'], $best['label'], "tier label of {$key}");
        }

        $totals = $this->shop->services->pricing()->totalsForSale($totalLines, $rule);
        $this->assertSame($case['expected']['subtotal'], Decimal::format($totals['subtotal'], 2));
        $this->assertSame($case['expected']['discount_total'], Decimal::format($totals['discount_total'], 2));
        $this->assertSame($case['expected']['total'], Decimal::format($totals['total'], 2));
        $this->assertSame($case['expected']['vat_amount'], Decimal::format($totals['vat_amount'], 2));

        // 2. The whole sale through the POS service: what is stored must be the contract's figures, whatever the client claimed.
        $payload = [
            'id' => Uuid::v4(),
            'receipt_number' => sprintf('T3-%06d', $case['index'] + 1),
            'terminal_id' => $terminal,
            'shift_id' => $shift,
            'sold_at' => (new \DateTimeImmutable())->format('c'),
            'lines' => array_values($saleLines),
            'payments' => [['method' => 'cash', 'amount' => $case['expected']['total']]],
            'total' => '0.01',
        ];
        if ($ruleKey !== null) {
            $payload['discount'] = ['key' => $ruleKey, 'id_ref_last4' => 'AB12'];
        }

        $result = $pos->syncSale($payload);
        $this->assertSame('completed', $result['status']);
        $this->assertSame($case['expected']['total'], $result['total']);

        $stored = $pos->getSale($result['sale_id']);
        $this->assertSame($case['expected']['subtotal'], $stored['subtotal']);
        $this->assertSame($case['expected']['discount_total'], $stored['discount_total']);
        $this->assertSame($case['expected']['total'], $stored['total']);
        $this->assertSame($case['expected']['vat_amount'], $stored['vat_amount']);

        $storedLines = [];
        foreach ($stored['lines'] as $line) {
            $storedLines[$line['sku']] = $line;
        }
        foreach ($case['expected']['lines'] as $expected) {
            $line = $storedLines['FX-' . strtoupper($expected['product'])] ?? $this->fail('the stored sale has a line for ' . $expected['product']);
            $this->assertSame($expected['unit_price'], $line['unit_price']);
            $this->assertSame($expected['line_total'], $line['line_total']);
            $this->assertSame($expected['quantity'], $line['qty']);
        }
        $this->assertCount(count($case['expected']['lines']), $stored['lines']);

        // The payments recorded add up to the total exactly, which is what the POS app sends.
        $paid = array_sum(array_map(static fn (array $payment): int => Decimal::parse((string) $payment['amount'], 2) ?? 0, $stored['payments']));
        $this->assertSame(Decimal::parse($case['expected']['total'], 2), $paid);
    }

    /**
     * @param array<string, mixed> $case
     * @return array<string, mixed>
     */
    private function expectedLine(array $case, string $product): array
    {
        foreach ($case['expected']['lines'] as $line) {
            if ($line['product'] === $product) {
                return $line;
            }
        }
        $this->fail('the fixture has no expected line for ' . $product);
    }
}
