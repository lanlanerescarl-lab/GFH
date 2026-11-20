<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

/**
 * The Settings hub's sections, registered in this one place. The hub page (/{org}/admin/settings) prints one card per
 * entry, so adding a section means adding one entry here plus its own routes and controller; nothing else changes.
 *
 * Every Settings page and every Settings service asks for the same permission, named once here so the pages and the
 * services cannot drift apart: Reports & Admin with edit, which only an Admin has.
 */
final class SettingsSections
{
    public const MODULE = 'reports_admin';
    public const ACTION = 'edit';

    /**
     * Paths are relative to /{org}/admin.
     *
     * @var list<array{key: string, label: string, description: string, path: string}>
     */
    public const ALL = [
        [
            'key' => 'branches',
            'label' => 'Branches',
            'description' => 'Stores and warehouses: their codes, addresses, and which one takes pickups or ships online orders.',
            'path' => '/settings/branches',
        ],
        [
            'key' => 'terminals',
            'label' => 'Terminals',
            'description' => 'The POS terminals at each branch. A terminal code starts every receipt number printed at that counter.',
            'path' => '/settings/terminals',
        ],
        [
            'key' => 'discounts',
            'label' => 'Discount rules',
            'description' => 'Percent discounts the POS can apply, such as senior citizen or promo rules. Deactivate a rule instead of deleting it.',
            'path' => '/settings/discounts',
        ],
        [
            'key' => 'order-steps',
            'label' => 'Order steps',
            'description' => 'The stages an order moves through, from placed to complete, and which orders pass through each one.',
            'path' => '/settings/order-steps',
        ],
        [
            'key' => 'general',
            'label' => 'Organization settings',
            'description' => 'Deposit percentage, reservation time, near-expiry warning and any settings added by extensions.',
            'path' => '/settings/general',
        ],
    ];
}
