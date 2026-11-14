<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/** Inserts into an audit_log table. The same SQL serves the core log and an organization's log. Insert only. */
final class AuditRepository
{
    public function __construct(private readonly \PDO $pdo, private readonly bool $hasOrganizationColumn)
    {
    }

    /** @param array<string, mixed> $detail */
    public function insert(string $action, ?string $actorUserId, ?string $organizationId, ?string $entity, ?string $entityId, array $detail): void
    {
        $detailJson = json_encode((object) $detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($this->hasOrganizationColumn) {
            $statement = $this->pdo->prepare(
                'INSERT INTO audit_log (organization_id, actor_user_id, action, entity, entity_id, detail) '
                . 'VALUES (:organization_id, :actor, :action, :entity, :entity_id, CAST(:detail AS jsonb))',
            );
            $statement->bindValue('organization_id', $organizationId);
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO audit_log (actor_user_id, action, entity, entity_id, detail) '
                . 'VALUES (:actor, :action, :entity, :entity_id, CAST(:detail AS jsonb))',
            );
        }

        $statement->bindValue('actor', $actorUserId);
        $statement->bindValue('action', $action);
        $statement->bindValue('entity', $entity);
        $statement->bindValue('entity_id', $entityId);
        $statement->bindValue('detail', $detailJson);
        $statement->execute();
    }
}
