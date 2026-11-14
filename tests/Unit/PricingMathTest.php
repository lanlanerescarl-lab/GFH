<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Service\PricingService;
use Gfh\Core\Support\QuantityRules;
use PHPUnit\Framework\TestCase;

final class PricingMathTest extends TestCase
{
    public function testVatIsExtractedAtTwelvePercentToTheCent(): void
    {
        // [VAT-inclusive total in centavos, expected VAT-free amount, expected VAT]
        foreach ([[11200, 10000, 1200], [100, 89, 11], [9999, 8928, 1071], [100000, 89286, 10714], [1, 1, 0], [0, 0, 0]] as [$total, $net, $vat]) {
            $result = PricingService::extractVat($total);
            $this->assertSame($net, $result['net'], "net of $total");
            $this->assertSame($vat, $result['vat'], "vat of $total");
        }
    }

    public function testVatAddsBackUpAndStaysWithinHalfACentOfExactTwelveOverOneTwelve(): void
    {
        for ($total = 0; $total <= 5000; $total++) {
            $result = PricingService::extractVat($total);
            $this->assertSame($total, $result['net'] + $result['vat']);
            $this->assertGreaterThanOrEqual(0, $result['vat']);
            $this->assertLessThanOrEqual(0.5000001, abs($result['vat'] - $total * 12 / 112), "total $total");
        }
    }

    public function testASeniorOrPwdDiscountRemovesVatThenTwentyPercent(): void
    {
        $result = PricingService::applyDiscount(112000, ['percent' => '20.00', 'vat_exempt' => true]);

        $this->assertSame(80000, $result['total']);
        $this->assertSame(32000, $result['discount_total']);
        $this->assertSame(0, $result['vat_amount']);
        $this->assertSame(112000, $result['total'] + $result['discount_total']);
    }

    public function testAnOrdinaryDiscountTakesThePercentOffThenExtractsVat(): void
    {
        $result = PricingService::applyDiscount(112000, ['percent' => '10.00', 'vat_exempt' => false]);

        $this->assertSame(100800, $result['total']);
        $this->assertSame(11200, $result['discount_total']);
        $this->assertSame(10800, $result['vat_amount']);
    }

    public function testTiersApplyByTheirBasisAndTheLowestMatchingPriceWins(): void
    {
        $variant = ['retail_price' => '100.00'];
        $tiers = [
            ['basis' => 'quantity', 'tier_key' => null, 'label' => '10 or more', 'min_qty' => '10.000', 'price' => '90.00'],
            ['basis' => 'quantity', 'tier_key' => null, 'label' => '50 or more', 'min_qty' => '50.000', 'price' => '80.00'],
            ['basis' => 'customer_tier', 'tier_key' => 'Gold', 'label' => 'Gold', 'min_qty' => '1.000', 'price' => '85.00'],
            ['basis' => 'customer_type', 'tier_key' => 'Dealer', 'label' => 'Dealer', 'min_qty' => '1.000', 'price' => '70.00'],
            ['basis' => 'quantity', 'tier_key' => null, 'label' => 'Too high', 'min_qty' => '1.000', 'price' => '120.00'],
        ];

        $cases = [
            ['guest, 1 piece', 1000, null, 10000, null],
            ['guest, 10 pieces', 10000, null, 9000, '10 or more'],
            ['guest, 50 pieces', 50000, null, 8000, '50 or more'],
            ['Gold customer, 1 piece', 1000, ['tier' => 'gold'], 8500, 'Gold'],
            ['Gold customer, 10 pieces', 10000, ['tier' => 'Gold'], 8500, 'Gold'],
            ['Gold customer, 50 pieces', 50000, ['tier' => 'Gold'], 8000, '50 or more'],
            ['Silver customer, 1 piece', 1000, ['tier' => 'Silver'], 10000, null],
            ['Dealer, 1 piece', 1000, ['type' => 'Dealer'], 7000, 'Dealer'],
            ['a guest never gets a customer tier', 1000, ['tier' => null, 'type' => null], 10000, null],
        ];
        foreach ($cases as [$label, $quantity, $customer, $price, $tier]) {
            $result = PricingService::bestPrice($variant, $tiers, $quantity, $customer);
            $this->assertSame($price, $result['price'], $label);
            $this->assertSame($tier, $result['label'], $label);
        }
    }

    public function testWholeNumbersOnlyUnlessTheVariantAllowsDecimals(): void
    {
        $whole = ['allow_decimal_qty' => false, 'qty_step' => '1.000', 'min_qty' => '1.000'];
        $this->assertSame(2000, QuantityRules::forSale($whole, '2'));
        foreach (['2.5', '0', '-1', 'abc'] as $bad) {
            try {
                QuantityRules::forSale($whole, $bad);
                $this->fail("'$bad' should be refused for a whole-number variant");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $weighed = ['allow_decimal_qty' => true, 'qty_step' => '0.250', 'min_qty' => '0.500'];
        $this->assertSame(750, QuantityRules::forSale($weighed, '0.75'));
        $this->assertSame(1000, QuantityRules::forSale($weighed, 1));
        foreach (['0.30', '0.25', '0.1234'] as $bad) {
            try {
                QuantityRules::forSale($weighed, $bad);
                $this->fail("'$bad' should be refused for a weighed variant");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
