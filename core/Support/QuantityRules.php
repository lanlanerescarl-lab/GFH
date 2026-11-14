<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

/**
 * Quantity rules for variants. Quantities are whole numbers unless the variant allows decimals, in which case
 * they must follow its step and minimum. Quantities are carried as thousandths (scale 3).
 */
final class QuantityRules
{
    public const MAX_MILLI = 1000000000; // one million units

    /**
     * For carts and sales: whole numbers (or the variant's step), and at least the variant's minimum.
     *
     * @param array<string, mixed> $variant a product_variants row
     * @return int thousandths
     * @throws \InvalidArgumentException with a message suited to a field error
     */
    public static function forSale(array $variant, mixed $raw): int
    {
        $milli = self::base($variant, $raw);

        if ((bool) $variant['allow_decimal_qty']) {
            $step = Decimal::parse((string) $variant['qty_step'], 3) ?? 1000;
            if ($milli % $step !== 0) {
                throw new \InvalidArgumentException(sprintf('Use a multiple of %s.', rtrim(rtrim(Decimal::format($step, 3), '0'), '.')));
            }
        }

        $minimum = Decimal::parse((string) $variant['min_qty'], 3) ?? 1000;
        if ($milli < $minimum) {
            throw new \InvalidArgumentException(sprintf('The minimum is %s.', rtrim(rtrim(Decimal::format($minimum, 3), '0'), '.')));
        }

        return $milli;
    }

    /**
     * For stock movements: greater than zero, whole unless the variant allows decimals, three decimals at most.
     *
     * @param array<string, mixed> $variant
     * @throws \InvalidArgumentException
     */
    public static function forStock(array $variant, mixed $raw): int
    {
        return self::base($variant, $raw);
    }

    /** @param array<string, mixed> $variant */
    private static function base(array $variant, mixed $raw): int
    {
        $milli = Decimal::parse($raw, 3);
        if ($milli === null) {
            throw new \InvalidArgumentException('Enter a valid quantity (up to 3 decimals).');
        }
        if ($milli <= 0) {
            throw new \InvalidArgumentException('The quantity must be greater than zero.');
        }
        if ($milli > self::MAX_MILLI) {
            throw new \InvalidArgumentException('That quantity is too large.');
        }
        if (!(bool) $variant['allow_decimal_qty'] && $milli % 1000 !== 0) {
            throw new \InvalidArgumentException('Use a whole number.');
        }

        return $milli;
    }
}
