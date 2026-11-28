<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\Modules;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Config\Config;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\SuperAdminUserRepository;
use Gfh\Core\Support\Uuid;

/**
 * Read-only Super Admin query service for Users and Access (/superadmin/users, SA.2a).
 *
 * Enforces PermissionService::can($actor, null, 'users_access', 'view') at the service level,
 * keeps account status and each membership status separate, formats timestamps in Asia/Manila,
 * and never exposes a password hash or token.
 */
final class SuperAdminUserQueryService
{
    public const PER_PAGE = 25;
    public const ACCOUNT_TYPES = ['super_admin', 'group_viewer', 'org_staff'];
    public const STATUSES = ['active', 'inactive', 'pending'];
    public const SORTS = ['name', '-name', 'last_login', '-last_login'];

    public function __construct(
        private readonly SuperAdminUserRepository $users,
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * Lists accounts across all organizations with filters, sorting, and 25-row pagination.
     *
     * @param array<string, mixed> $query raw query parameters (q, account_type, organization, role, status, sort, page)
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, pages: int, from: int, to: int},
     *     sort: string,
     *     filters: array{q: string, account_type: string, organization: string, role: string, status: string},
     *     options: array{organizations: list<array{id: string, slug: string, name: string, status: string}>},
     *     has_active_filters: bool,
     * }
     * @throws HttpException 403 when the actor lacks users_access:view
     */
    public function list(StaffUser $actor, array $query = []): array
    {
        $this->permissions->assert($actor, null, 'users_access', 'view');

        $q = self::cleanText($query['q'] ?? null, 100);

        $accountTypeRaw = strtolower(self::cleanText($query['account_type'] ?? null, 32));
        $accountType = in_array($accountTypeRaw, self::ACCOUNT_TYPES, true) ? $accountTypeRaw : '';

        $orgRaw = self::cleanText($query['organization'] ?? null, 64);
        $organization = ($orgRaw === 'none' || Uuid::isValid($orgRaw)) ? $orgRaw : '';

        $roleRaw = strtolower(self::cleanText($query['role'] ?? null, 32));
        $role = in_array($roleRaw, Modules::ROLES, true) ? $roleRaw : '';

        $statusRaw = strtolower(self::cleanText($query['status'] ?? null, 32));
        $status = in_array($statusRaw, self::STATUSES, true) ? $statusRaw : '';

        $sortRaw = self::cleanText($query['sort'] ?? null, 32);
        $sort = in_array($sortRaw, self::SORTS, true) ? $sortRaw : 'name';

        $page = self::parsePage($query['page'] ?? 1);

        $repoFilters = [
            'q' => $q !== '' ? $q : null,
            'account_type' => $accountType !== '' ? $accountType : null,
            'organization' => $organization !== '' ? $organization : null,
            'role' => $role !== '' ? $role : null,
            'status' => $status !== '' ? $status : null,
        ];

        $result = $this->users->query($repoFilters, $sort, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $total = $result['total'];
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        if ($page > $pages && $total > 0) {
            $page = $pages;
            $result = $this->users->query($repoFilters, $sort, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        }

        $items = array_map(static fn (array $row): array => self::presentUser($row), $result['rows']);
        $fromRow = $total === 0 ? 0 : (($page - 1) * self::PER_PAGE) + 1;
        $toRow = $total === 0 ? 0 : min($total, $fromRow + count($items) - 1);

        $hasActiveFilters = $q !== ''
            || $accountType !== ''
            || $organization !== ''
            || $role !== ''
            || $status !== '';

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'pages' => $pages,
                'from' => $fromRow,
                'to' => $toRow,
            ],
            'sort' => $sort,
            'filters' => [
                'q' => $q,
                'account_type' => $accountType,
                'organization' => $organization,
                'role' => $role,
                'status' => $status,
            ],
            'options' => [
                'organizations' => $this->users->organizationOptions(),
            ],
            'has_active_filters' => $hasActiveFilters,
        ];
    }

    /**
     * Loads one account and its organization memberships for /superadmin/users/{id}.
     *
     * @return array{
     *     user: array<string, mixed>,
     *     organizations: list<array{id: string, slug: string, name: string, status: string}>,
     * }
     * @throws HttpException 403 when unauthorized, 404 when the id is invalid or unknown
     */
    public function detail(StaffUser $actor, string $userId): array
    {
        $this->permissions->assert($actor, null, 'users_access', 'view');

        if (!Uuid::isValid($userId)) {
            throw HttpException::notFound('That account could not be found.');
        }

        $row = $this->users->findById($userId);
        if ($row === null) {
            throw HttpException::notFound('That account could not be found.');
        }

        return [
            'user' => self::presentUser($row),
            'organizations' => $this->users->organizationOptions(),
        ];
    }

    /**
     * Safe organization list for Super Admin create forms.
     *
     * @return list<array{id: string, slug: string, name: string, status: string}>
     */
    public function organizationOptions(StaffUser $actor): array
    {
        $this->permissions->assert($actor, null, 'users_access', 'view');

        return $this->users->organizationOptions();
    }

    /**
     * Formats one account row with its separate account status and per-membership statuses.
     * Whitelists safe fields only so password_hash or tokens can never pass through.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentUser(array $row): array
    {
        $orgRole = isset($row['org_role']) && is_string($row['org_role']) && $row['org_role'] !== ''
            ? $row['org_role']
            : null;
        $status = (string) ($row['status'] ?? 'pending');

        $rawMemberships = is_array($row['memberships'] ?? null) ? $row['memberships'] : [];
        $memberships = [];
        foreach ($rawMemberships as $membership) {
            if (!is_array($membership)) {
                continue;
            }
            $mRole = (string) ($membership['role'] ?? '');
            $mStatus = (string) ($membership['status'] ?? 'inactive');
            $memberships[] = [
                'id' => (string) ($membership['id'] ?? ''),
                'organization_id' => (string) ($membership['organization_id'] ?? ''),
                'organization_name' => (string) ($membership['organization_name'] ?? ''),
                'organization_slug' => (string) ($membership['organization_slug'] ?? ''),
                'organization_status' => (string) ($membership['organization_status'] ?? 'active'),
                'role' => $mRole,
                'role_label' => self::orgRoleLabel($mRole),
                'status' => $mStatus,
                'status_label' => self::statusLabel($mStatus),
                'status_tone' => self::statusTone($mStatus),
                'joined_at_manila' => self::formatManila($membership['created_at'] ?? null),
            ];
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'org_role' => $orgRole,
            'group_role_label' => self::groupRoleLabel($orgRole),
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'status_tone' => self::statusTone($status),
            'last_login_at_manila' => self::formatManila($row['last_login_at'] ?? null, 'Never'),
            'created_at_manila' => self::formatManila($row['created_at'] ?? null),
            'updated_at_manila' => self::formatManila($row['updated_at'] ?? null),
            'memberships' => $memberships,
        ];
    }

    public static function groupRoleLabel(?string $orgRole): string
    {
        return match ($orgRole) {
            'super_admin' => 'Super Admin',
            'group_viewer' => 'Group Viewer',
            default => 'None',
        };
    }

    public static function orgRoleLabel(string $role): string
    {
        return match ($role) {
            'admin' => 'Admin',
            'manager' => 'Manager',
            'staff' => 'Staff',
            'cashier' => 'Cashier',
            default => ucfirst($role),
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'inactive' => 'Inactive',
            'pending' => 'Pending',
            default => ucfirst($status),
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'active' => 'ok',
            'pending' => 'warn',
            'inactive' => 'neutral',
            default => 'neutral',
        };
    }

    public static function formatManila(mixed $timestamp, string $empty = '—'): string
    {
        if (!is_string($timestamp) || trim($timestamp) === '') {
            return $empty;
        }

        try {
            $dt = (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone(Config::TIMEZONE));

            return $dt->format('d M Y, H:i') . ' ' . Config::TIMEZONE;
        } catch (\Throwable) {
            return $empty;
        }
    }

    private static function cleanText(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim($value), 0, $maxLength, 'UTF-8');
    }

    private static function parsePage(mixed $value): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,6}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return 1;
    }
}
