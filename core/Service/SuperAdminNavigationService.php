<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\Modules;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;

/**
 * Builds the Super Admin sidebar from Modules::groupAllows (checked through PermissionService::can).
 * A page the signed-in person may not view is omitted.
 */
final class SuperAdminNavigationService
{
    /**
     * The six Super Admin pages in the approved sidebar order (docs/superadmin-design/design-brief.md).
     * Paths are relative to /superadmin.
     *
     * @var list<array{key: string, label: string, path: string, module: string}>
     */
    public const ITEMS = [
        ['key' => 'overview', 'label' => 'Overview', 'path' => '', 'module' => 'overview'],
        ['key' => 'organizations', 'label' => 'Organizations', 'path' => '/organizations', 'module' => 'organizations'],
        ['key' => 'consolidated_reports', 'label' => 'Consolidated reports', 'path' => '/reports', 'module' => 'consolidated_reports'],
        ['key' => 'users_access', 'label' => 'Users and access', 'path' => '/users', 'module' => 'users_access'],
        ['key' => 'audit_log', 'label' => 'Audit log', 'path' => '/audit', 'module' => 'audit_log'],
        ['key' => 'system_settings', 'label' => 'System settings', 'path' => '/settings', 'module' => 'system_settings'],
    ];

    public function __construct(private readonly PermissionService $permissions)
    {
    }

    /**
     * Service-level permission gate plus sidebar items for a Super Admin page.
     *
     * @return list<array{key: string, label: string, url: string, module: string, current: bool}>
     * @throws HttpException 403 when the person may not view the module
     */
    public function forPage(StaffUser $user, string $module, string $basePath, string $currentPath): array
    {
        $this->permissions->assert($user, null, $module, 'view');

        return $this->items($user, $basePath, $currentPath);
    }

    /**
     * @return list<array{key: string, label: string, url: string, module: string, current: bool}>
     */
    public function items(StaffUser $user, string $basePath, string $currentPath): array
    {
        if (!$user->isActive() || !in_array($user->orgRole, Modules::GROUP_ROLES, true)) {
            return [];
        }

        $prefix = $basePath . '/superadmin';
        $items = [];

        foreach (self::ITEMS as $definition) {
            if (
                !Modules::groupAllows($user->orgRole, $definition['module'], 'view')
                || !$this->permissions->can($user, null, $definition['module'], 'view')
            ) {
                continue;
            }

            $items[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'url' => $prefix . $definition['path'],
                'module' => $definition['module'],
                'current' => false,
            ];
        }

        return $this->markCurrent($items, $currentPath, $prefix);
    }

    /**
     * Longest matching path wins; /superadmin itself only matches exact so Overview does not stay active on subpages.
     *
     * @param list<array{key: string, label: string, url: string, module: string, current: bool}> $items
     * @return list<array{key: string, label: string, url: string, module: string, current: bool}>
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
