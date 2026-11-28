<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/**
 * Read-only core-database queries for the Super Admin audit log and recent activity screens (SA.1b).
 *
 * Joins users (full_name only — never password_hash) and organizations (name/slug only — never
 * connection_encrypted). All SQL is SELECT-only and parameterized.
 */
final class SuperAdminAuditRepository
{
    private const BASE_SELECT = 'SELECT a.id, a.occurred_at, a.organization_id, a.actor_user_id, '
        . 'a.action, a.entity, a.entity_id, a.detail::text AS detail, '
        . 'u.full_name AS actor_name, '
        . 'o.name AS organization_name, o.slug AS organization_slug '
        . 'FROM audit_log a '
        . 'LEFT JOIN users u ON u.id = a.actor_user_id '
        . 'LEFT JOIN organizations o ON o.id = a.organization_id';

    public function __construct(private readonly \PDO $core)
    {
    }

    /**
     * Returns the newest core audit events for the Overview screen.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $statement = $this->core->prepare(
            self::BASE_SELECT . ' ORDER BY a.occurred_at DESC, a.id DESC LIMIT :limit',
        );
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Queries the core audit log with search, filters, time sort direction, and pagination.
     *
     * @param array{
     *     q?: string|null,
     *     organization?: string|null,
     *     actor?: string|null,
     *     action?: string|null,
     *     entity?: string|null,
     *     from_utc?: string|null,
     *     to_utc?: string|null,
     * } $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function query(array $filters, string $direction, int $limit, int $offset): array
    {
        $where = [];
        $params = [];

        $search = $filters['q'] ?? null;
        if (is_string($search) && $search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $where[] = "(a.action ILIKE :q ESCAPE '\\' OR COALESCE(u.full_name, '') ILIKE :q ESCAPE '\\' OR COALESCE(a.entity_id, '') ILIKE :q ESCAPE '\\')";
            $params['q'] = '%' . $escaped . '%';
        }

        $organization = $filters['organization'] ?? null;
        if ($organization === 'core') {
            $where[] = 'a.organization_id IS NULL';
        } elseif (is_string($organization) && $organization !== '') {
            $where[] = 'a.organization_id = :organization_id';
            $params['organization_id'] = $organization;
        }

        $actor = $filters['actor'] ?? null;
        if ($actor === 'system') {
            $where[] = 'a.actor_user_id IS NULL';
        } elseif (is_string($actor) && $actor !== '') {
            $where[] = 'a.actor_user_id = :actor_user_id';
            $params['actor_user_id'] = $actor;
        }

        $action = $filters['action'] ?? null;
        if (is_string($action) && $action !== '') {
            $where[] = 'a.action = :action';
            $params['action'] = $action;
        }

        $entity = $filters['entity'] ?? null;
        if (is_string($entity) && $entity !== '') {
            $where[] = 'a.entity = :entity';
            $params['entity'] = $entity;
        }

        $fromUtc = $filters['from_utc'] ?? null;
        if (is_string($fromUtc) && $fromUtc !== '') {
            $where[] = 'a.occurred_at >= CAST(:from_utc AS timestamptz)';
            $params['from_utc'] = $fromUtc;
        }

        $toUtc = $filters['to_utc'] ?? null;
        if (is_string($toUtc) && $toUtc !== '') {
            $where[] = 'a.occurred_at < CAST(:to_utc AS timestamptz)';
            $params['to_utc'] = $toUtc;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $countSql = 'SELECT COUNT(*)::int FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id' . $whereSql;
        $countStmt = $this->core->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        if ($total === 0) {
            return ['rows' => [], 'total' => 0];
        }

        $dir = $direction === 'asc' ? 'ASC' : 'DESC';
        $dataSql = self::BASE_SELECT . $whereSql . " ORDER BY a.occurred_at {$dir}, a.id {$dir} LIMIT :limit OFFSET :offset";
        $dataStmt = $this->core->prepare($dataSql);
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        $dataStmt->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $dataStmt->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'rows' => $dataStmt->fetchAll(),
            'total' => $total,
        ];
    }

    /**
     * Fetches one audit event by UUID (for no-JS drawer inspection fallback).
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $statement = $this->core->prepare(self::BASE_SELECT . ' WHERE a.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Populates the filter bar dropdowns from the core database.
     *
     * @return array{
     *     organizations: list<array{id: string, name: string}>,
     *     actors: list<array{id: string, name: string}>,
     *     actions: list<string>,
     *     entities: list<string>,
     * }
     */
    public function filterOptions(): array
    {
        $orgsStmt = $this->core->query('SELECT id, name FROM organizations ORDER BY lower(name) ASC, slug ASC');
        $organizations = [];
        if ($orgsStmt !== false) {
            foreach ($orgsStmt->fetchAll() as $row) {
                $organizations[] = ['id' => (string) $row['id'], 'name' => (string) $row['name']];
            }
        }

        $actorsStmt = $this->core->query('SELECT id, full_name FROM users ORDER BY lower(full_name) ASC, id ASC');
        $actors = [];
        if ($actorsStmt !== false) {
            foreach ($actorsStmt->fetchAll() as $row) {
                $actors[] = ['id' => (string) $row['id'], 'name' => (string) $row['full_name']];
            }
        }

        $actionsStmt = $this->core->query('SELECT DISTINCT action FROM audit_log ORDER BY action ASC');
        $actions = $actionsStmt !== false ? array_map('strval', $actionsStmt->fetchAll(\PDO::FETCH_COLUMN)) : [];

        $entitiesStmt = $this->core->query("SELECT DISTINCT entity FROM audit_log WHERE entity IS NOT NULL AND entity <> '' ORDER BY entity ASC");
        $entities = $entitiesStmt !== false ? array_map('strval', $entitiesStmt->fetchAll(\PDO::FETCH_COLUMN)) : [];

        return [
            'organizations' => $organizations,
            'actors' => $actors,
            'actions' => $actions,
            'entities' => $entities,
        ];
    }
}
