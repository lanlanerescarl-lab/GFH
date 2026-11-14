<?php

declare(strict_types=1);

namespace Gfh\Core\Auth;

/**
 * The names used by can(): actions, roles and modules.
 * Organization modules are the sidebar areas of an organization's apps. Group modules belong to the
 * Super Admin app and are asked with no organization.
 */
final class Modules
{
    public const ACTIONS = ['view', 'edit', 'delete'];

    /** Roles a person can hold inside one organization. */
    public const ROLES = ['admin', 'manager', 'staff', 'cashier'];

    /** The only roles an organization Admin may create or change. Admin and Manager belong to Super Admin. */
    public const ORG_ADMIN_MANAGEABLE_ROLES = ['staff', 'cashier'];

    /** Group-level roles, held on the user record rather than through a membership. */
    public const GROUP_ROLES = ['super_admin', 'group_viewer'];

    public const ORGANIZATION = ['dashboard', 'inventory', 'products', 'procurement', 'sales', 'reports_admin', 'pos'];

    /**
     * What each group role may do on each group page, as [view, edit, delete].
     * The audit log is append-only, so nobody edits it; oversight pages are read-only for everyone.
     */
    private const GROUP = [
        'super_admin' => [
            'overview' => [true, false, false],
            'organizations' => [true, false, false],
            'consolidated_reports' => [true, false, false],
            'users_access' => [true, true, true],
            'audit_log' => [true, false, false],
            'system_settings' => [true, true, false],
        ],
        'group_viewer' => [
            'overview' => [true, false, false],
            'organizations' => [true, false, false],
            'consolidated_reports' => [true, false, false],
            'users_access' => [true, false, false],
            'audit_log' => [true, false, false],
            'system_settings' => [true, false, false],
        ],
    ];

    public static function isOrganizationModule(string $module): bool
    {
        return in_array($module, self::ORGANIZATION, true);
    }

    public static function groupAllows(string $groupRole, string $module, string $action): bool
    {
        $index = array_search($action, self::ACTIONS, true);
        if ($index === false) {
            return false;
        }

        return self::GROUP[$groupRole][$module][$index] ?? false;
    }
}
