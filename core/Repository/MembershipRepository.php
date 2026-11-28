<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\SqlWhere;

/** All SQL for memberships: the role a person holds in each organization. */
final class MembershipRepository
{
    /**
     * What the Team list shows as a person's status. A person is inactive when either the membership or the account
     * is switched off, and pending until they have set a password through a reset link.
     */
    private const TEAM_STATUS = "CASE WHEN m.status = 'inactive' OR u.status = 'inactive' THEN 'inactive' "
        . "WHEN m.status = 'pending' OR u.status = 'pending' THEN 'pending' ELSE 'active' END";

    /** Public sort name => SQL expression, for ListQuery::fromRequest(). Fixed here, never taken from a request. */
    public const TEAM_SORTS = [
        'name' => 'u.full_name',
        'email' => 'u.email',
        'role' => 'm.role',
        'status' => self::TEAM_STATUS,
        'joined' => 'm.created_at',
        'last_login' => 'u.last_login_at',
    ];

    public function __construct(private readonly \PDO $core)
    {
    }

    /** @return array{id: string, user_id: string, organization_id: string, role: string, status: string}|null */
    public function find(string $userId, string $organizationId): ?array
    {
        $statement = $this->core->prepare(
            'SELECT id, user_id, organization_id, role, status FROM memberships '
            . 'WHERE user_id = :user_id AND organization_id = :organization_id',
        );
        $statement->execute(['user_id' => $userId, 'organization_id' => $organizationId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array{organization_id: string, role: string, status: string}> */
    public function forUser(string $userId): array
    {
        $statement = $this->core->prepare('SELECT organization_id, role, status FROM memberships WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    /** Ids of the other organizations this person has a membership in, whatever its status. */
    public function otherOrganizationIds(string $userId, string $exceptOrganizationId): array
    {
        $statement = $this->core->prepare(
            'SELECT organization_id FROM memberships WHERE user_id = :user_id AND organization_id <> :organization_id',
        );
        $statement->execute(['user_id' => $userId, 'organization_id' => $exceptOrganizationId]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function countActiveOtherMemberships(string $userId, string $exceptOrganizationId): int
    {
        $statement = $this->core->prepare(
            "SELECT COUNT(*) FROM memberships WHERE user_id = :user_id AND organization_id <> :organization_id AND status = 'active'",
        );
        $statement->execute(['user_id' => $userId, 'organization_id' => $exceptOrganizationId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Locks active Admin memberships in one organization and returns how many other active users still hold an active
     * Admin membership there. Must be called inside a transaction so concurrent demotions or deactivations serialize.
     */
    public function countOtherActiveAdminsForUpdate(string $organizationId, string $exceptUserId): int
    {
        $statement = $this->core->prepare(
            'SELECT m.user_id FROM memberships m JOIN users u ON u.id = m.user_id '
            . "WHERE m.organization_id = :organization_id AND m.role = 'admin' AND m.status = 'active' AND u.status = 'active' "
            . 'FOR UPDATE OF m',
        );
        $statement->execute(['organization_id' => $organizationId]);

        $count = 0;
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $userId) {
            if ((string) $userId !== $exceptUserId) {
                ++$count;
            }
        }

        return $count;
    }

    public function create(string $userId, string $organizationId, string $role, string $status = 'active'): void
    {
        $statement = $this->core->prepare(
            'INSERT INTO memberships (user_id, organization_id, role, status) VALUES (:user_id, :organization_id, :role, :status)',
        );
        $statement->execute(['user_id' => $userId, 'organization_id' => $organizationId, 'role' => $role, 'status' => $status]);
    }

    public function update(string $userId, string $organizationId, string $role, string $status): void
    {
        $statement = $this->core->prepare(
            'UPDATE memberships SET role = :role, status = :status WHERE user_id = :user_id AND organization_id = :organization_id',
        );
        $statement->execute(['role' => $role, 'status' => $status, 'user_id' => $userId, 'organization_id' => $organizationId]);
    }

    /**
     * One organization's team. Group-level accounts (Super Admin, Group Viewer) are not team members and are left out.
     * Every query here is tied to the organization id, so another organization's people can never appear.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} the page of rows and the total number of matches
     */
    public function listTeam(string $organizationId, ListQuery $query, ?string $role, ?string $status): array
    {
        $where = new SqlWhere();
        $where->add('m.organization_id = ?', $organizationId);
        $where->add('u.org_role IS NULL');
        if ($role !== null) {
            $where->add('m.role = ?', $role);
        }
        if ($status !== null) {
            $where->add(self::TEAM_STATUS . ' = ?', $status);
        }
        if (($pattern = $query->likePattern()) !== null) {
            $where->add('u.full_name ILIKE ? OR u.email ILIKE ?', $pattern, $pattern);
        }

        $from = 'FROM memberships m JOIN users u ON u.id = m.user_id ';

        $count = $this->core->prepare('SELECT COUNT(*) ' . $from . $where->sql());
        $count->execute($where->params());
        $total = (int) $count->fetchColumn();

        $rows = $this->core->prepare(
            'SELECT u.id, u.full_name, u.email, u.last_login_at, m.role, m.created_at AS joined_at, '
            . self::TEAM_STATUS . ' AS status '
            . $from . $where->sql()
            . ' ORDER BY ' . $query->orderBy . ', u.id ASC LIMIT ' . $query->perPage . ' OFFSET ' . $query->offset(),
        );
        $rows->execute($where->params());

        return [$rows->fetchAll(), $total];
    }

    /**
     * One member of this organization's team, or null. The organization filter is what keeps another organization's
     * people out of the Team pages, so it must stay in this query.
     *
     * @return array<string, mixed>|null
     */
    public function findTeamMember(string $userId, string $organizationId): ?array
    {
        $statement = $this->core->prepare(
            'SELECT u.id, u.full_name, u.email, u.last_login_at, m.role, m.created_at AS joined_at, '
            . self::TEAM_STATUS . ' AS status '
            . 'FROM memberships m JOIN users u ON u.id = m.user_id '
            . 'WHERE m.organization_id = ? AND m.user_id = ? AND u.org_role IS NULL',
        );
        $statement->execute([$organizationId, $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
