<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Support\Decimal;

/**
 * A cart priced on the server. Amounts are centavos (integers) so nothing drifts; toArray() formats them
 * as "112.00" strings for the API.
 */
final class PricedCart
{
    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>|null  $discountRule
     * @param list<string>               $madeToOrderVariantIds
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $subtotal,
        public readonly int $discountTotal,
        public readonly ?array $discountRule,
        public readonly int $deliveryFee,
        public readonly int $assemblyFee,
        public readonly int $installationFee,
        public readonly int $vatAmount,
        public readonly int $total,
        public readonly int $depositDue,
        public readonly ?string $promisedDate,
        public readonly array $madeToOrderVariantIds,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $lines[] = [
                'variant_id' => $line['variant_id'],
                'sku' => $line['sku'],
                'product_name' => $line['product_name'],
                'variant_name' => $line['variant_name'],
                'unit' => $line['unit'],
                'quantity' => Decimal::format($line['quantity_milli'], 3),
                'unit_price' => Decimal::format($line['unit_price'], 2),
                'tier_applied' => $line['tier_label'],
                'line_total' => Decimal::format($line['line_total'], 2),
                'is_made_to_order' => $line['is_made_to_order'],
            ];
        }

        return [
            'lines' => $lines,
            'subtotal' => Decimal::format($this->subtotal, 2),
            'discount_total' => Decimal::format($this->discountTotal, 2),
            'discount' => $this->discountRule === null ? null : ['key' => $this->discountRule['key'], 'label' => $this->discountRule['label']],
            'delivery_fee' => Decimal::format($this->deliveryFee, 2),
            'assembly_fee' => Decimal::format($this->assemblyFee, 2),
            'installation_fee' => Decimal::format($this->installationFee, 2),
            'total' => Decimal::format($this->total, 2),
            'vat_amount' => Decimal::format($this->vatAmount, 2),
            'net_of_vat' => Decimal::format($this->total - $this->vatAmount, 2),
            'deposit_due' => Decimal::format($this->depositDue, 2),
            'promised_date' => $this->promisedDate,
        ];
    }
}
