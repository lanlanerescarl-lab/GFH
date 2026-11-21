<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Hooks\Hooks;

/**
 * The header navigation of the Shop: the built-in Shop link, then whatever extensions add through the
 * navigation.shop hook. Items are cleaned here (a label and a path inside this site; never another host or a script
 * address), and the partial applies the same rule again when it prints them.
 */
final class ShopNavigationService
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

        $items = [['label' => 'Shop', 'url' => $prefix . '/products']];

        // An extension receives only text: where the Shop lives and which organization this is.
        $items = $this->hooks->filter(Hooks::NAVIGATION_SHOP, $items, [
            'organization' => $organizationSlug,
            'base_url' => $prefix,
        ]);

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
                'current' => !empty($item['current']) || $item['url'] === $currentPath
                    || ($item['url'] === $prefix . '/products' && str_starts_with($currentPath, $prefix . '/products')),
            ];
        }

        return $clean;
    }
}
