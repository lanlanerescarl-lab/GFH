<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\SuperAdminAuditRepository;
use Gfh\Core\Repository\SuperAdminOrganizationRepository;

/**
 * Read-only Super Admin Overview service (SA.1b, core database only).
 * Returns organization status counts, the registry list, and the 10 newest core audit events.
 * Enforces PermissionService::can($actor, null, 'overview', 'view') at the service level.
 */
final class SuperAdminOverviewService
{
    public const RECENT_LIMIT = 10;

    public function __construct(
        private readonly SuperAdminOrganizationRepository $organizations,
        private readonly SuperAdminAuditRepository $audit,
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * @return array{
     *     counts: array{total: int, active: int, suspended: int, archived: int},
     *     organizations: list<array<string, mixed>>,
     *     recent_activity: list<array<string, mixed>>,
     * }
     * @throws HttpException 403 when the actor lacks overview:view
     */
    public function overview(StaffUser $actor): array
    {
        $this->permissions->assert($actor, null, 'overview', 'view');

        $counts = $this->organizations->countByStatus();
        $orgRows = $this->organizations->list();
        $recentRows = $this->audit->recent(self::RECENT_LIMIT);

        return [
            'counts' => $counts,
            'organizations' => array_map(
                static fn (array $row): array => SuperAdminOrganizationQueryService::presentListRow($row),
                $orgRows,
            ),
            'recent_activity' => array_map(
                static fn (array $row): array => SuperAdminAuditQueryService::presentEvent($row),
                $recentRows,
            ),
        ];
    }
}
