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
            $text = trim((string) $amount);
            if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $text, $m) !== 1) {
                return '₱0.00';
            }
            $formatted = $m[1] . $m[2] . '.' . str_pad(substr($m[3] ?? '', 0, 2), 2, '0');
        }

        $negative = str_starts_with($formatted, '-');
        $unsigned = $negative ? substr($formatted, 1) : $formatted;
        [$whole, $frac] = explode('.', $unsigned, 2);
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return ($negative ? '-₱' : '₱') . $grouped . '.' . $frac;
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
