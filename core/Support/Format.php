<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

use Gfh\Core\Config\Config;

/**
 * Display formatting for peso amounts, quantities, percentages and Asia/Manila dates (DD Mon YYYY).
 * Never converts money or quantities to float.
 */
final class Format
{
    public static function peso(string|int|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '₱0.00';
        }

        $units = Decimal::parse($amount, 2);
        if ($units !== null) {
            $formatted = Decimal::format($units, 2);
        } else {
            // More than two decimals (a weighted-average cost keeps four): round half up to the centavo for display.
            // Cutting the digits off would show 124.6667 as 124.66; the stored value is never changed here.
            $text = trim((string) $amount);
            if (preg_match('/^(-?)(\d{1,16})(?:\.(\d+))?$/', $text, $m) !== 1) {
                return '₱0.00';
            }
            $fraction = $m[3] ?? '';
            $centavos = (int) ($m[2] . str_pad(substr($fraction, 0, 2), 2, '0'));
            if (isset($fraction[2]) && (int) $fraction[2] >= 5) {
                ++$centavos;
            }
            $formatted = ($m[1] === '-' && $centavos !== 0 ? '-' : '') . Decimal::format($centavos, 2);
        }

        $negative = str_starts_with($formatted, '-');
        $unsigned = $negative ? substr($formatted, 1) : $formatted;
        [$whole, $frac] = explode('.', $unsigned, 2);
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return ($negative ? '-₱' : '₱') . $grouped . '.' . $frac;
    }

    /** True when something has been paid but not the whole total (amounts in pesos, up to two decimals). */
    public static function isPartlyPaid(string|int|null $paid, string|int|null $total): bool
    {
        $paidUnits = Decimal::parse($paid ?? '0', 2);
        $totalUnits = Decimal::parse($total ?? '0', 2);

        return $paidUnits !== null && $totalUnits !== null && $paidUnits > 0 && $paidUnits < $totalUnits;
    }

    /**
     * The payment status as people read it. The stored status only says "deposit_paid" when the order has a deposit
     * requirement, so an order with a partial payment and no deposit still reads "unpaid" in the database. Display
     * only: the stored states and the rule that sets them are not touched.
     */
    public static function paymentStatus(string $status, string|int|null $paid, string|int|null $total): string
    {
        if ($status !== 'refunded' && self::isPartlyPaid($paid, $total)) {
            $label = $status === 'deposit_paid' ? 'Deposit paid' : 'Partly paid';

            return sprintf('%s (%s of %s)', $label, self::peso($paid), self::peso($total));
        }

        return match ($status) {
            'unpaid' => 'Unpaid',
            'deposit_paid' => 'Deposit paid',
            'paid' => 'Paid',
            'refunded' => 'Refunded',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function qty(string|int|null $quantity): string
    {
        if ($quantity === null || $quantity === '') {
            return '0';
        }

        $milli = Decimal::parse($quantity, 3);
        $formatted = $milli !== null ? Decimal::format($milli, 3) : trim((string) $quantity);
        if (!str_contains($formatted, '.')) {
            return (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $formatted);
        }

        [$whole, $frac] = explode('.', $formatted, 2);
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);
        $frac = rtrim($frac, '0');

        return $frac === '' ? $grouped : $grouped . '.' . $frac;
    }

    public static function pct(string|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $hundredths = Decimal::parse($value, 2);
        $formatted = $hundredths !== null ? Decimal::format($hundredths, 2) : trim((string) $value);

        return $formatted . '%';
    }

    public static function date(string|\DateTimeInterface|null $value): string
    {
        $dt = self::toManila($value);

        return $dt === null ? '—' : $dt->format('d M Y');
    }

    public static function dateTime(string|\DateTimeInterface|null $value): string
    {
        $dt = self::toManila($value);

        return $dt === null ? '—' : $dt->format('d M Y, H:i');
    }

    private static function toManila(string|\DateTimeInterface|null $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $zone = new \DateTimeZone(Config::TIMEZONE);
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone($zone);
        }

        $raw = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $zone);

            return $parsed !== false ? $parsed : null;
        }

        try {
            return (new \DateTimeImmutable($raw))->setTimezone($zone);
        } catch (\Throwable) {
            return null;
        }
    }
}
