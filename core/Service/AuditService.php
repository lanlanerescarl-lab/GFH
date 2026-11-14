<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Repository\AuditRepository;

/**
 * Writes append-only audit events. Group-level events (sign-ins, accounts, permissions) go to the core log;
 * events inside an organization (prices, stock, voids, order status) go to that organization's own log.
 * Never put passwords, reset tokens or full ID numbers in $detail.
 */
final class AuditService
{
    private readonly AuditRepository $core;

    public function __construct(\PDO $core)
    {
        $this->core = new AuditRepository($core, true);
    }

    /** @param array<string, mixed> $detail */
    public function core(
        string $action,
        ?string $actorUserId = null,
        ?string $organizationId = null,
        ?string $entity = null,
        ?string $entityId = null,
        array $detail = [],
    ): void {
        $this->core->insert($action, $actorUserId, $organizationId, $entity, $entityId, $detail);
    }

    /** @param array<string, mixed> $detail */
    public function organization(
        \PDO $organization,
        string $action,
        ?string $actorUserId = null,
        ?string $entity = null,
        ?string $entityId = null,
        array $detail = [],
    ): void {
        (new AuditRepository($organization, false))->insert($action, $actorUserId, null, $entity, $entityId, $detail);
    }
}
