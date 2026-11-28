<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

use Gfh\Core\Tenancy\Organization;

/**
 * Read-only core-database queries for Super Admin Users and Access (SA.2a).
 *
 * Security rule: SELECT-only and parameterized. NEVER selects password_hash, token_hash,
 * connection_encrypted, db_schema, or settings.
 */
final class SuperAdminUserRepository
{
    private const SAFE_USER_COLUMNS = 'u.id, u.full_name, u.email, u.org_role, u.status, u.last_login_at, u.created_at, u.updated_at';

    private const SORT_SQL = [
        'name' => 'lower(u.full_name) ASC, lower(u.email) ASC, u.id ASC',
        '-name' => 'lower(u.full_name) DESC, lower(u.email) DESC, u.id DESC',
        '-last_login' => 'u.last_login_at DESC NULLS LAST, lower(u.full_name) ASC, u.id ASC',
        'last_login' => 'u.last_login_at ASC NULLS LAST, lower(u.full_name) ASC, u.id ASC',
    ];

    public function __construct(private readonly \PDO $core)
    {
    }

    /**
     * Queries staff and group accounts across all organizations with search, filters, sorting, and pagination.
     *
     * @param array{
     *     q?: string|null,
     *     account_type?: string|null,
     *     organization?: string|null,
     *     role?: string|null,
     *     status?: string|null,
     * } $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function query(array $filters, string $sort, int $limit, int $offset): array
    {
        $where = [];
        $params = [];

        $search = $filters['q'] ?? null;
        if (is_string($search) && $search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $where[] = "(u.full_name ILIKE :q ESCAPE '\\' OR u.email ILIKE :q ESCAPE '\\')";
            $params['q'] = '%' . $escaped . '%';
        }

        $accountType = $filters['account_type'] ?? null;
        if ($accountType === 'super_admin' || $accountType === 'group_viewer') {
            $where[] = 'u.org_role = :account_type';
            $params['account_type'] = $accountType;
        } elseif ($accountType === 'org_staff') {
            $where[] = 'u.org_role IS NULL';
        }

        $organization = $filters['organization'] ?? null;
        $role = $filters['role'] ?? null;
        $hasRole = is_string($role) && $role !== '';

        if ($organization === 'none') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM memberships m_none WHERE m_none.user_id = u.id)';
            if ($hasRole) {
                // An account with no memberships cannot also hold an organization role.
                $where[] = '1 = 0';
            }
        } elseif (is_string($organization) && $organization !== '' && $hasRole) {
            $where[] = 'EXISTS (SELECT 1 FROM memberships m_f WHERE m_f.user_id = u.id AND m_f.organization_id = :organization_id AND m_f.role = :org_role)';
            $params['organization_id'] = $organization;
            $params['org_role'] = $role;
        } elseif (is_string($organization) && $organization !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM memberships m_f WHERE m_f.user_id = u.id AND m_f.organization_id = :organization_id)';
            $params['organization_id'] = $organization;
        } elseif ($hasRole) {
            $where[] = 'EXISTS (SELECT 1 FROM memberships m_f WHERE m_f.user_id = u.id AND m_f.role = :org_role)';
            $params['org_role'] = $role;
        }

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->core->prepare('SELECT COUNT(*)::int FROM users u' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        if ($total === 0) {
            return ['rows' => [], 'total' => 0];
        }

        $orderBy = self::SORT_SQL[$sort] ?? self::SORT_SQL['name'];
        $dataStmt = $this->core->prepare(
            'SELECT ' . self::SAFE_USER_COLUMNS . ' FROM users u'
            . $whereSql
            . ' ORDER BY ' . $orderBy
            . ' LIMIT :limit OFFSET :offset',
        );
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        $dataStmt->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $dataStmt->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll();

        if ($rows === []) {
            return ['rows' => [], 'total' => $total];
        }

        $membershipsByUser = $this->membershipsForUsers(
            array_map(static fn (array $row): string => (string) $row['id'], $rows),
        );

        foreach ($rows as &$row) {
            $row['memberships'] = $membershipsByUser[(string) $row['id']] ?? [];
        }
        unset($row);

        return [
            'rows' => $rows,
            'total' => $total,
        ];
    }

    /**
     * Fetches one account with its organization memberships, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $userId): ?array
    {
        $statement = $this->core->prepare(
            'SELECT ' . self::SAFE_USER_COLUMNS . ' FROM users u WHERE u.id = :id',
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $memberships = $this->membershipsForUsers([(string) $row['id']]);
        $row['memberships'] = $memberships[(string) $row['id']] ?? [];

        return $row;
    }

    /**
     * Lists registered organizations (safe metadata only) for filter dropdowns and membership forms.
     *
     * @return list<array{id: string, slug: string, name: string, status: string}>
     */
    public function organizationOptions(): array
    {
        $statement = $this->core->query(
            'SELECT id, slug, name, status FROM organizations ORDER BY lower(name) ASC, slug ASC',
        );
        if ($statement === false) {
            return [];
        }

        $options = [];
        foreach ($statement->fetchAll() as $row) {
            $options[] = [
                'id' => (string) $row['id'],
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'status' => (string) $row['status'],
            ];
        }

        return $options;
    }

    /**
     * Builds an Organization value object from safe core metadata only (never selects connection_encrypted),
     * for passing into AccountService and PasswordResetService which only need id and slug.
     */
    public function findOrganizationForService(string $organizationId): ?Organization
    {
        $statement = $this->core->prepare(
            'SELECT id, slug, name, short_name, status, migration_version, template_version '
            . 'FROM organizations WHERE id = :id',
        );
        $statement->execute(['id' => $organizationId]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return new Organization(
            (string) $row['id'],
            (string) $row['slug'],
            (string) $row['name'],
            (string) $row['short_name'],
            (string) $row['status'],
            '',
            'public',
            [],
            [],
            (int) $row['migration_version'],
            isset($row['template_version']) ? (string) $row['template_version'] : null,
        );
    }

    /**
     * @param list<string> $userIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function membershipsForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $statement = $this->core->prepare(
            'SELECT m.id, m.user_id, m.organization_id, m.role, m.status, m.created_at, '
            . 'o.name AS organization_name, o.slug AS organization_slug, o.status AS organization_status '
            . 'FROM memberships m '
            . 'JOIN organizations o ON o.id = m.organization_id '
            . 'WHERE m.user_id IN (' . $placeholders . ') '
            . 'ORDER BY lower(o.name) ASC, o.slug ASC',
        );
        $statement->execute(array_values($userIds));

        $grouped = [];
        foreach ($statement->fetchAll() as $row) {
            $uid = (string) $row['user_id'];
            $grouped[$uid][] = [
                'id' => (string) $row['id'],
                'organization_id' => (string) $row['organization_id'],
                'organization_name' => (string) $row['organization_name'],
                'organization_slug' => (string) $row['organization_slug'],
                'organization_status' => (string) $row['organization_status'],
                'role' => (string) $row['role'],
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $grouped;
    }
}
