<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

/**
 * Plain-language names for the core organization settings, in one place. SettingsService::CORE holds each key's
 * default and range; this class holds only the wording the Settings page shows. Extension settings bring their own
 * label in their manifest, so they are not listed here.
 */
final class SettingLabels
{
    /** @var array<string, array{label: string, help: string}> */
    private const CORE = [
        'deposit_percent' => [
            'label' => 'Deposit for made-to-order orders (%)',
            'help' => 'Share of the total asked up front when an order has a made-to-order item. 0 means no deposit.',
        ],
        'reservation_hours' => [
            'label' => 'Stock reservation time (hours)',
            'help' => 'How long stock is held for an online order before the reservation lapses.',
        ],
        'near_expiry_days' => [
            'label' => 'Near-expiry warning (days)',
            'help' => 'Stock batches that expire within this many days are flagged as near expiry.',
        ],
    ];

    /** The label for a core key, or the key itself if it has none. */
    public static function label(string $key): string
    {
        return self::CORE[$key]['label'] ?? $key;
    }

    public static function help(string $key): string
    {
        return self::CORE[$key]['help'] ?? '';
    }
}
