<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/**
 * Read-only core-database queries for the Super Admin organization registry screens (SA.1b).
 *
 * Security rule: NEVER select connection_encrypted, db_schema, or settings. Only the safe registry
 * metadata columns are selected, using parameterized SQL exclusively.
 */
final class SuperAdminOrganizationRepository
{
    private const SAFE_COLUMNS = 'id, slug, name, short_name, status, migration_version, template_version, '
        . 'branding::text AS branding, created_at, updated_at';

    public function __construct(private readonly \PDO $core)
    {
    }

    /**
     * Counts organizations by lifecycle status in one query.
     *
     * @return array{total: int, active: int, suspended: int, archived: int}
     */
    public function countByStatus(): array
    {
        $statement = $this->core->query('SELECT status, COUNT(*)::int AS cnt FROM organizations GROUP BY status');
        $counts = ['total' => 0, 'active' => 0, 'suspended' => 0, 'archived' => 0];

        if ($statement === false) {
            return $counts;
        }

        foreach ($statement->fetchAll() as $row) {
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['cnt'] ?? 0);
            $counts['total'] += $count;
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $count;
            }
        }

        return $counts;
    }

    /**
     * Lists organizations with optional name/short_name/slug search and status filter,
     * attaching enabled feature keys without N+1 queries.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $search = null, ?string $status = null): array
    {
        $where = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $where[] = "(name ILIKE :q ESCAPE '\\' OR short_name ILIKE :q ESCAPE '\\' OR slug ILIKE :q ESCAPE '\\')";
            $params['q'] = '%' . $escaped . '%';
        }

        if ($status !== null && $status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        $sql = 'SELECT ' . self::SAFE_COLUMNS . ' FROM organizations';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY lower(name) ASC, slug ASC';

        $statement = $this->core->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();

        if ($rows === []) {
            return [];
        }

        $featuresByOrg = $this->enabledFeaturesForOrganizations(
            array_map(static fn (array $row): string => (string) $row['id'], $rows),
        );

        foreach ($rows as &$row) {
            $row['features'] = $featuresByOrg[(string) $row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    /**
     * Fetches one organization by slug with its enabled feature keys, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $statement = $this->core->prepare(
            'SELECT ' . self::SAFE_COLUMNS . ' FROM organizations WHERE slug = :slug',
        );
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $features = $this->enabledFeaturesForOrganizations([(string) $row['id']]);
        $row['features'] = $features[(string) $row['id']] ?? [];

        return $row;
    }

    /**
     * @param list<string> $organizationIds
     * @return array<string, list<string>>
     */
    private function enabledFeaturesForOrganizations(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($organizationIds), '?'));
        $statement = $this->core->prepare(
            'SELECT organization_id, feature_key FROM organization_features '
            . 'WHERE enabled = true AND organization_id IN (' . $placeholders . ') '
            . 'ORDER BY feature_key ASC',
        );
        $statement->execute(array_values($organizationIds));

        $grouped = [];
        foreach ($statement->fetchAll() as $row) {
            $orgId = (string) $row['organization_id'];
            $grouped[$orgId][] = (string) $row['feature_key'];
        }

        return $grouped;
    }
}
