<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\Modules;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Tenancy\Organization;

/**
 * Builds the Admin sidebar for one person in one organization.
 *
 *  1. The built-in items are kept only if PermissionService::can(user, organization, module, 'view') allows them.
 *  2. The list then passes through the navigation.admin hook, so an extension can add, reorder or remove items.
 *     The hook context carries a "can" callable, and an item an extension adds with a "module" key is checked with
 *     can() as well, so an extension cannot show a page to someone who may not open it.
 *  3. Anything malformed or pointing outside this site is dropped, and the item for the current page is marked.
 *
 * Who sees what is decided here and nowhere else; the layout only prints the list.
 */
final class AdminNavigationService
{
    /**
     * The built-in sidebar. Paths are relative to /{org}/admin. Pages arrive in Stage 5; the list is the
     * information architecture they will fill.
     *
     * An item may carry an optional "action" (view, edit or delete). It is the permission the page itself asks for, and
     * it defaults to view. Team and Settings need edit, so a Manager is not shown links that would answer 403.
     *
     * @var list<array{key: string, label: string, path: string, module: string, group: string, action?: string}>
     */
    public const ITEMS = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'path' => '', 'module' => 'dashboard', 'group' => 'Overview'],
        ['key' => 'sales', 'label' => 'Orders', 'path' => '/orders', 'module' => 'sales', 'group' => 'Sell'],
        ['key' => 'customers', 'label' => 'Customers', 'path' => '/customers', 'module' => 'sales', 'group' => 'Sell'],
        ['key' => 'products', 'label' => 'Products', 'path' => '/products', 'module' => 'products', 'group' => 'Stock'],
        ['key' => 'inventory', 'label' => 'Inventory', 'path' => '/inventory', 'module' => 'inventory', 'group' => 'Stock'],
        ['key' => 'procurement', 'label' => 'Procurement', 'path' => '/procurement', 'module' => 'procurement', 'group' => 'Stock'],
        ['key' => 'reports', 'label' => 'Reports', 'path' => '/reports', 'module' => 'reports_admin', 'group' => 'Manage'],
        ['key' => 'team', 'label' => 'Team', 'path' => '/team', 'module' => 'reports_admin', 'group' => 'Manage', 'action' => 'edit'],
        ['key' => 'settings', 'label' => 'Settings', 'path' => '/settings', 'module' => 'reports_admin', 'group' => 'Manage', 'action' => 'edit'],
    ];

    /** Shown only outside production, to anyone who may open the dashboard. */
    public const UI_KIT_ITEM = ['key' => 'ui-kit', 'label' => 'UI kit', 'path' => '/ui-kit', 'module' => 'dashboard', 'group' => 'Developer'];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly Hooks $hooks,
        private readonly bool $includeUiKit = false,
    ) {
    }

    /**
     * @param string $basePath    the install folder prefix (Request::basePath()), usually ""
     * @param string $currentPath the full path of the page being shown, including $basePath
     * @return list<array{key: string, label: string, url: string, group: string, current: bool}>
     */
    public function items(StaffUser $user, Organization $organization, string $basePath, string $currentPath): array
    {
        $prefix = $basePath . '/' . $organization->slug . '/admin';
        $can = fn (string $module, string $action = 'view'): bool => $this->permissions->can($user, $organization, $module, $action);

        $definitions = self::ITEMS;
        if ($this->includeUiKit) {
            $definitions[] = self::UI_KIT_ITEM;
        }

        $items = [];
        foreach ($definitions as $definition) {
            $action = $definition['action'] ?? 'view';
            if (!$can($definition['module'], $action)) {
                continue;
            }
            $items[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'url' => $prefix . $definition['path'],
                'group' => $definition['group'],
                'module' => $definition['module'],
                'action' => $action,
            ];
        }

        $items = $this->hooks->filter(Hooks::NAVIGATION_ADMIN, $items, [
            'user' => $user,
            'organization' => $organization,
            'base_url' => $prefix,
            'can' => $can,
        ]);

        return $this->markCurrent($this->clean(is_array($items) ? $items : [], $can), $currentPath, $prefix);
    }

    /**
     * @param array<mixed> $items
     * @param callable(string, string=): bool $can
     * @return list<array{key: string, label: string, url: string, group: string, current: bool}>
     */
    private function clean(array $items, callable $can): array
    {
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['label'], $item['url']) || !is_string($item['label']) || !is_string($item['url'])) {
                continue;
            }
            $label = trim($item['label']);
            // Same rule as the partial: a path inside this site only, never another host or a script URL.
            if ($label === '' || preg_match('#^/(?!/)[^\s\\\\]*$#D', $item['url']) !== 1) {
                continue;
            }
            if (isset($item['module'])) {
                // An extension item may name the action it needs; anything that is not a known action falls back to view.
                $action = isset($item['action']) && is_string($item['action']) && in_array($item['action'], Modules::ACTIONS, true) ? $item['action'] : 'view';
                if (!is_string($item['module']) || !$can($item['module'], $action)) {
                    continue;
                }
            }

            $clean[] = [
                'key' => isset($item['key']) && is_string($item['key']) ? $item['key'] : $item['url'],
                'label' => $label,
                'url' => $item['url'],
                'group' => isset($item['group']) && is_string($item['group']) ? $item['group'] : '',
                'current' => false,
            ];
        }

        return $clean;
    }

    /**
     * The longest URL that is the current path, or a folder above it, is the current page. So
     * /demo/admin/products/12 marks Products. The Admin home (the Dashboard) only matches itself, otherwise it would
     * light up on every page that has no sidebar item of its own.
     *
     * @param list<array{key: string, label: string, url: string, group: string, current: bool}> $items
     * @return list<array{key: string, label: string, url: string, group: string, current: bool}>
     */
    private function markCurrent(array $items, string $currentPath, string $home): array
    {
        $current = rtrim($currentPath, '/');
        $best = null;
        $bestLength = -1;

        foreach ($items as $index => $item) {
            $url = rtrim(explode('?', $item['url'], 2)[0], '/');
            $matches = $current === $url || ($url !== $home && str_starts_with($current, $url . '/'));
            if ($matches && strlen($url) > $bestLength) {
                $best = $index;
                $bestLength = strlen($url);
            }
        }

        if ($best !== null) {
            $items[$best]['current'] = true;
        }

        return $items;
    }
}
