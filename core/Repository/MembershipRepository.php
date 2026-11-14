<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/** All SQL for memberships: the role a person holds in each organization. */
final class MembershipRepository
{
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
}
