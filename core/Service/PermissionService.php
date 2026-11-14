<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\Modules;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\MembershipRepository;
use Gfh\Core\Repository\PermissionRepository;
use Gfh\Core\Tenancy\Organization;

/**
 * The single answer to "may this person do this here?": can(user, organization, module, action).
 *
 *  - No organization: a group page (Super Admin app). Decided by the person's group role.
 *  - An organization: an operations module. The person needs an active membership there, and the
 *    organization's matrix (its own override, else the default) for their role decides.
 *  - Super Admin and Group Viewer never get edit or delete on operations, even if they also hold a membership.
 */
final class PermissionService
{
    /** @var array<string, array<string, array{view: bool, edit: bool, delete: bool}>> */
    private array $matrices = [];
    /** @var array<string, array<string, mixed>|null> */
    private array $memberships = [];

    public function __construct(
        private readonly MembershipRepository $membershipRepository,
        private readonly PermissionRepository $permissionRepository,
    ) {
    }

    public function can(?StaffUser $user, ?Organization $organization, string $module, string $action): bool
    {
        if ($user === null || !$user->isActive() || !in_array($action, Modules::ACTIONS, true)) {
            return false;
        }

        if ($organization === null) {
            return $user->orgRole !== null && Modules::groupAllows($user->orgRole, $module, $action);
        }

        if (!Modules::isOrganizationModule($module)) {
            return false;
        }

        // Oversight roles may look, never change.
        if ($user->isGroupLevel() && $action !== 'view') {
            return false;
        }

        $membership = $this->activeMembership($user, $organization);
        if ($membership === null) {
            return false;
        }

        return $this->matrix($organization, (string) $membership['role'])[$module][$action] ?? false;
    }

    /** @throws HttpException 403 */
    public function assert(?StaffUser $user, ?Organization $organization, string $module, string $action): void
    {
        if (!$this->can($user, $organization, $module, $action)) {
            throw HttpException::forbidden();
        }
    }

    /** The person's active membership in the organization, or null. Used by the TenantResolver. */
    public function activeMembership(StaffUser $user, Organization $organization): ?array
    {
        if ($user->id === null || !$user->isActive()) {
            return null;
        }

        $key = $user->id . '|' . $organization->id;
        if (!isset($this->memberships[$key])) {
            $row = $this->membershipRepository->find($user->id, $organization->id);
            if ($row === null || $row['status'] !== 'active') {
                return null; // misses are not cached, so a membership added later is seen
            }
            $this->memberships[$key] = $row;
        }

        return $this->memberships[$key];
    }

    /** @return array<string, array{view: bool, edit: bool, delete: bool}> */
    private function matrix(Organization $organization, string $role): array
    {
        $key = $organization->id . '|' . $role;

        return $this->matrices[$key] ??= $this->permissionRepository->matrix($organization->id, $role);
    }
}
