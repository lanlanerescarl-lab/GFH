<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\CatalogRepository;
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\QuantityRules;
use Gfh\Core\Support\Uuid;

/**
 * The one place prices, VAT, discounts and totals are worked out. The Shop, POS and Admin all use it.
 * The server always recomputes: prices, tiers and fees sent by a browser are never trusted.
 *
 * Money rules:
 *  - Prices are VAT-inclusive centavos. VAT is 12% and is extracted: net = round(total / 1.12), VAT = total - net.
 *  - A tier applies when its basis matches (quantity reached, or the customer's tier or type matches);
 *    the lowest matching price wins, and it is never higher than the retail price.
 *  - A VAT-exempt discount (senior, PWD) removes the VAT first, then takes the percentage off the VAT-free price.
 *  - total = subtotal - discount_total + delivery_fee + assembly_fee + installation_fee, and vat_amount is the VAT inside it.
 */
final class PricingService
{
    public const VAT_RATE_PERCENT = 12;

    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly SettingsService $settings,
        private readonly Hooks $hooks,
    ) {
    }

    /**
     * Splits a VAT-inclusive total into its VAT-free amount and VAT.
     *
     * @return array{net: int, vat: int} centavos
     */
    public static function extractVat(int $totalCentavos): array
    {
        $net = Decimal::mulDiv($totalCentavos, 100, 100 + self::VAT_RATE_PERCENT);

        return ['net' => $net, 'vat' => $totalCentavos - $net];
    }

    /**
     * The unit price for a quantity: retail, or the lowest matching tier price.
     *
     * @param array<string, mixed>                 $variant a variant row (retail_price)
     * @param list<array<string, mixed>>           $tiers   the variant's price_tiers rows
     * @param array{tier?: ?string, type?: ?string}|null $customer the signed-in customer's organization record, or null for a guest
     * @return array{price: int, label: ?string} centavos and the tier's label when a tier won
     */
    public static function bestPrice(array $variant, array $tiers, int $quantityMilli, ?array $customer): array
    {
        $best = Decimal::parse((string) $variant['retail_price'], 2) ?? 0;
        $label = null;

        foreach ($tiers as $tier) {
            $minimum = Decimal::parse((string) $tier['min_qty'], 3) ?? 1000;
            $price = Decimal::parse((string) $tier['price'], 2);
            if ($price === null || $quantityMilli < $minimum) {
                continue;
            }

            $matches = match ($tier['basis']) {
                'quantity' => true,
                'customer_tier' => $customer !== null && ($customer['tier'] ?? null) !== null && strcasecmp((string) $tier['tier_key'], (string) $customer['tier']) === 0,
                'customer_type' => $customer !== null && ($customer['type'] ?? null) !== null && strcasecmp((string) $tier['tier_key'], (string) $customer['type']) === 0,
                default => false,
            };

            if ($matches && $price < $best) {
                $best = $price;
                $label = (string) $tier['label'];
            }
        }

        return ['price' => $best, 'label' => $label];
    }

    /**
     * Applies a discount rule to a VAT-inclusive subtotal.
     *
     * @param array<string, mixed> $rule a discount_rules row (percent, vat_exempt)
     * @return array{discount_total: int, total: int, vat_amount: int} centavos; total = subtotal - discount_total
     */
    public static function applyDiscount(int $subtotal, array $rule): array
    {
        $percent = Decimal::parse((string) $rule['percent'], 2) ?? 0; // 20.00 is 2000

        if ((bool) $rule['vat_exempt']) {
            $net = self::extractVat($subtotal)['net'];
            $total = $net - Decimal::mulDiv($net, $percent, 10000);

            return ['discount_total' => $subtotal - $total, 'total' => $total, 'vat_amount' => 0];
        }

        $discount = Decimal::mulDiv($subtotal, $percent, 10000);
        $total = $subtotal - $discount;

        return ['discount_total' => $discount, 'total' => $total, 'vat_amount' => self::extractVat($total)['vat']];
    }

    /**
     * Prices a cart from variant ids and quantities.
     *
     * @param list<array<string, mixed>> $items   each {variant_id, quantity}
     * @param array{customer?: ?array<string, mixed>, discount_rule?: ?string, channel?: string, fulfillment?: array<string, mixed>, checkout?: array<string, mixed>} $context
     * @throws HttpException 422 with field errors
     */
    public function priceCart(array $items, array $context = []): PricedCart
    {
        $channel = $context['channel'] ?? 'online';
        $customer = $context['customer'] ?? null;

        if ($items === []) {
            throw HttpException::unprocessable(['items' => 'The cart is empty.']);
        }
        if (count($items) > 100) {
            throw HttpException::unprocessable(['items' => 'A cart can hold at most 100 lines.']);
        }

        // Same variant twice? Add the quantities, so a quantity tier sees the true total.
        $merged = [];
        $errors = [];
        foreach ($items as $index => $item) {
            $variantId = is_array($item) ? ($item['variant_id'] ?? null) : null;
            $milli = is_array($item) ? Decimal::parse($item['quantity'] ?? null, 3) : null;
            if (!Uuid::isValid($variantId)) {
                $errors["items.$index.variant_id"] = 'Choose a product.';
                continue;
            }
            if ($milli === null) {
                $errors["items.$index.quantity"] = 'Enter a valid quantity.';
                continue;
            }
            $merged[(string) $variantId] = ($merged[(string) $variantId] ?? 0) + $milli;
        }
        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        $variants = $this->catalog->variantsWithProducts(array_keys($merged));
        $tiers = $this->catalog->tiersForVariants(array_keys($merged));
        $tiersByVariant = [];
        foreach ($tiers as $tier) {
            $tiersByVariant[(string) $tier['variant_id']][] = $tier;
        }

        $lines = [];
        $subtotal = 0;
        try {
            foreach ($merged as $variantId => $milli) {
                $variant = $variants[$variantId] ?? null;
                if ($variant === null || $variant['status'] !== 'active' || $variant['product_status'] !== 'active') {
                    $errors['items'] = 'Some products in the cart are no longer available.';
                    continue;
                }

                try {
                    $milli = QuantityRules::forSale($variant, Decimal::format($milli, 3));
                } catch (\InvalidArgumentException $exception) {
                    $errors['items.' . $variant['sku'] . '.quantity'] = $variant['sku'] . ': ' . $exception->getMessage();
                    continue;
                }

                $price = self::bestPrice($variant, $tiersByVariant[$variantId] ?? [], $milli, is_array($customer) ? $customer : null);
                $lineTotal = Decimal::mulDiv($milli, $price['price'], 1000);
                $subtotal += $lineTotal;

                $lines[] = [
                    'variant_id' => $variantId,
                    'sku' => $variant['sku'],
                    'product_name' => $variant['product_name'],
                    'variant_name' => $variant['name'],
                    'unit' => $variant['unit'],
                    'quantity_milli' => $milli,
                    'unit_price' => $price['price'],
                    'tier_label' => $price['label'],
                    'line_total' => $lineTotal,
                    'is_made_to_order' => false,
                    'track_batches' => (bool) $variant['track_batches'],
                ];
            }
        } catch (\RangeException $exception) {
            throw HttpException::unprocessable(['items' => $exception->getMessage()]);
        }
        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        // Discount (a rule chosen by key; inactive rules cannot be used).
        $discountTotal = 0;
        $vat = null;
        $rule = null;
        $ruleKey = $context['discount_rule'] ?? null;
        if (is_string($ruleKey) && $ruleKey !== '') {
            $rule = $this->catalog->discountRuleByKey($ruleKey);
            if ($rule === null || !(bool) $rule['active']) {
                throw HttpException::unprocessable(['discount' => 'That discount is not available.']);
            }
            $applied = self::applyDiscount($subtotal, $rule);
            $discountTotal = $applied['discount_total'];
        }
        $exempt = $rule !== null && (bool) $rule['vat_exempt'];

        // Fees and rules from extensions apply to online orders only.
        $fees = ['delivery_fee' => 0, 'assembly_fee' => 0, 'installation_fee' => 0];
        $depositDue = null;
        $promisedDate = null;
        $madeToOrder = [];
        if ($channel === 'online') {
            $adjustments = $this->adjustments($lines, $subtotal, $context, array_keys($merged));
            $fees = $adjustments['fees'];
            $depositDue = $adjustments['deposit_due'];
            $promisedDate = $adjustments['promised_date'];
            $madeToOrder = $adjustments['made_to_order'];
        }

        foreach ($lines as $i => $line) {
            $lines[$i]['is_made_to_order'] = in_array($line['variant_id'], $madeToOrder, true);
        }

        $feeTotal = array_sum($fees);
        $productPortion = $subtotal - $discountTotal;
        $total = $productPortion + $feeTotal;
        $vatAmount = $exempt ? self::extractVat($feeTotal)['vat'] : self::extractVat($total)['vat'];

        if ($depositDue === null) {
            $percent = $this->settings->int('deposit_percent');
            $depositDue = ($percent > 0 && $madeToOrder !== []) ? Decimal::mulDiv($total, $percent, 100) : 0;
        }
        $depositDue = min($depositDue, $total);

        return new PricedCart(
            $lines, $subtotal, $discountTotal, $rule, $fees['delivery_fee'], $fees['assembly_fee'], $fees['installation_fee'],
            $vatAmount, $total, $depositDue, $promisedDate, $madeToOrder,
        );
    }

    /**
     * Totals for a sale rung up at a terminal, from the prices the terminal used. An offline sale keeps the price
     * it was sold at, so unit prices come from the sale, but every total is recomputed here.
     *
     * @param list<array{quantity_milli: int, unit_price: int, discount: int}> $lines
     * @param array<string, mixed>|null $rule
     * @return array{line_totals: list<int>, subtotal: int, discount_total: int, total: int, vat_amount: int}
     */
    public function totalsForSale(array $lines, ?array $rule): array
    {
        $lineTotals = [];
        $subtotal = 0;
        foreach ($lines as $line) {
            $total = Decimal::mulDiv($line['quantity_milli'], $line['unit_price'], 1000) - $line['discount'];
            if ($total < 0) {
                throw HttpException::unprocessable(['lines' => 'A line discount is larger than the line.']);
            }
            $lineTotals[] = $total;
            $subtotal += $total;
        }

        if ($rule === null) {
            return ['line_totals' => $lineTotals, 'subtotal' => $subtotal, 'discount_total' => 0, 'total' => $subtotal, 'vat_amount' => self::extractVat($subtotal)['vat']];
        }

        $applied = self::applyDiscount($subtotal, $rule);

        return ['line_totals' => $lineTotals, 'subtotal' => $subtotal, 'discount_total' => $applied['discount_total'], 'total' => $applied['total'], 'vat_amount' => $applied['vat_amount']];
    }

    /**
     * Runs the checkout.adjustments hook and checks what comes back.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>       $context
     * @param list<string>               $variantIds
     * @return array{fees: array<string, int>, deposit_due: ?int, promised_date: ?string, made_to_order: list<string>}
     */
    private function adjustments(array $lines, int $subtotal, array $context, array $variantIds): array
    {
        $default = [
            'delivery_fee' => '0.00',
            'assembly_fee' => '0.00',
            'installation_fee' => '0.00',
            'deposit_due' => null,
            'promised_date' => null,
            'made_to_order_variants' => [],
        ];

        $result = $this->hooks->filter(Hooks::CHECKOUT_ADJUSTMENTS, $default, [
            'lines' => array_map(static fn (array $l): array => [
                'variant_id' => $l['variant_id'], 'sku' => $l['sku'], 'quantity' => Decimal::format($l['quantity_milli'], 3), 'line_total' => Decimal::format($l['line_total'], 2),
            ], $lines),
            'subtotal' => Decimal::format($subtotal, 2),
            'fulfillment' => $context['fulfillment'] ?? [],
            'checkout' => $context['checkout'] ?? [],
            'customer' => $context['customer'] ?? null,
        ]);

        $fees = [];
        foreach (['delivery_fee', 'assembly_fee', 'installation_fee'] as $key) {
            $amount = Decimal::parse($result[$key] ?? '0.00', 2);
            if ($amount === null || $amount < 0 || $amount > 100000000) {
                throw new \LogicException(sprintf('A checkout.adjustments hook returned an invalid %s.', $key));
            }
            $fees[$key] = $amount;
        }

        $deposit = null;
        if (isset($result['deposit_due'])) {
            $deposit = Decimal::parse($result['deposit_due'], 2);
            if ($deposit === null || $deposit < 0) {
                throw new \LogicException('A checkout.adjustments hook returned an invalid deposit_due.');
            }
        }

        $promised = null;
        if (isset($result['promised_date']) && $result['promised_date'] !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $result['promised_date']);
            if ($date === false || $date->format('Y-m-d') !== $result['promised_date']) {
                throw new \LogicException('A checkout.adjustments hook returned an invalid promised_date.');
            }
            $promised = $date->format('Y-m-d');
        }

        $madeToOrder = array_values(array_intersect((array) ($result['made_to_order_variants'] ?? []), $variantIds));

        return ['fees' => $fees, 'deposit_due' => $deposit, 'promised_date' => $promised, 'made_to_order' => $madeToOrder];
    }
}
