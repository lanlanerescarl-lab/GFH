<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

/**
 * Exact decimal arithmetic on integers. Money is held as centavos (scale 2), quantities as thousandths (scale 3)
 * and unit costs as ten-thousandths (scale 4). Floats are never used for money, and rounding is always
 * half away from zero, so a total is the same wherever it is calculated.
 */
final class Decimal
{
    /**
     * Reads "12.5", 12 or 12.5 into whole units at $scale decimals (12.5 at scale 2 is 1250).
     * Returns null when the value is not a plain decimal or has more decimals than $scale.
     */
    public static function parse(mixed $value, int $scale): ?int
    {
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            // JSON numbers arrive as floats. Two extra decimals must be zero, so 0.1 + 0.2 style noise is rejected.
            $text = sprintf('%.' . ($scale + 2) . 'F', $value);
            if (substr($text, -2) !== '00') {
                return null;
            }
            $text = substr($text, 0, -2);
            $text = rtrim($text, '.');
        } elseif (is_string($value)) {
            $text = trim($value);
        } else {
            return null;
        }

        if (preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,' . max(1, $scale) . '}))?$/', $text, $m) !== 1) {
            return null;
        }
        if ($scale === 0 && isset($m[3])) {
            return null;
        }

        $fraction = str_pad($m[3] ?? '', $scale, '0');
        $units = (int) ($m[2] . $fraction);

        return $m[1] === '-' ? -$units : $units;
    }

    public static function format(int $units, int $scale): string
    {
        $sign = $units < 0 ? '-' : '';
        $digits = str_pad((string) abs($units), $scale + 1, '0', STR_PAD_LEFT);

        return $scale === 0 ? $sign . $digits : $sign . substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }

    /** $a * $b, refusing instead of silently overflowing. */
    public static function mul(int $a, int $b): int
    {
        if ($a !== 0 && abs($b) > intdiv(PHP_INT_MAX, abs($a))) {
            throw new \RangeException('The amount is too large to calculate.');
        }

        return $a * $b;
    }

    /** Round-half-away-from-zero of $a * $b / $divisor, in integers. */
    public static function mulDiv(int $a, int $b, int $divisor): int
    {
        if ($divisor <= 0) {
            throw new \InvalidArgumentException('The divisor must be positive.');
        }
        if ($a !== 0 && abs($b) > intdiv(PHP_INT_MAX, abs($a))) {
            throw new \RangeException('The amount is too large to calculate.');
        }

        $product = $a * $b;
        $quotient = intdiv(2 * abs($product) + $divisor, 2 * $divisor);

        return $product < 0 ? -$quotient : $quotient;
    }
}
