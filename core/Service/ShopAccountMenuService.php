<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Hooks\Hooks;

/**
 * The menu of a signed-in customer's account pages: Profile, Addresses, Password and My orders, then whatever
 * extensions add through the account.menu hook. Items are cleaned here (a label, and a path inside this site; never
 * another host or a script address) and the account-menu partial applies the same rule again when it prints them.
 */
final class ShopAccountMenuService
{
    public function __construct(private readonly Hooks $hooks)
    {
    }

    /**
     * @return list<array{label: string, url: string, current: bool}>
     */
    public function items(string $basePath, string $organizationSlug, string $currentPath): array
    {
        $prefix = $basePath . '/' . $organizationSlug . '/shop';

        $builtIn = $items = [
            ['label' => 'Profile', 'url' => $prefix . '/account/profile'],
            ['label' => 'Addresses', 'url' => $prefix . '/account/addresses'],
            ['label' => 'Password', 'url' => $prefix . '/account/password'],
            ['label' => 'My orders', 'url' => $prefix . '/orders'],
        ];

        // An extension receives only text: where the Shop lives and which organization this is, like navigation.shop.
        // The extension loader isolates an extension whose entry file fails while it loads, but not a callback that
        // throws later, when the hook runs. A menu is not worth a broken account page, so such a callback is skipped
        // and the built-in items stay (Throwable on purpose: a type error in extension code must not take the page down).
        try {
            $items = $this->hooks->filter(Hooks::ACCOUNT_MENU, $items, [
                'organization' => $organizationSlug,
                'base_url' => $prefix,
            ]);
        } catch (\Throwable) {
            $items = $builtIn;
        }

        $clean = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item) || !isset($item['label'], $item['url']) || !is_string($item['label']) || !is_string($item['url'])) {
                continue;
            }
            $label = trim($item['label']);
            if ($label === '' || preg_match('#^/(?!/)[^\s\\\\]*$#D', $item['url']) !== 1) {
                continue;
            }
            $clean[] = [
                'label' => $label,
                'url' => $item['url'],
                'current' => !empty($item['current']) || $this->isCurrent($item['url'], $currentPath, $prefix),
            ];
        }

        return $clean;
    }

    /** A page and the pages under it (an address's edit form belongs to Addresses; an order belongs to My orders). */
    private function isCurrent(string $url, string $currentPath, string $prefix): bool
    {
        if ($url === $currentPath) {
            return true;
        }

        return in_array($url, [$prefix . '/account/addresses', $prefix . '/orders'], true) && str_starts_with($currentPath, $url . '/');
    }
}
