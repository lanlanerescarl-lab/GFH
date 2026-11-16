<?php

declare(strict_types=1);

namespace Gfh\Core\Tenancy;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Database\Database;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\OrganizationRepository;
use Gfh\Core\Service\PermissionService;

/**
 * Turns the {org} part of a URL into an organization, and checks who may use it.
 * Connection details come only from the core registry, never from the request: the slug is only a lookup key.
 * A request touches exactly one organization database plus the core database.
 */
final class TenantResolver
{
    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly PermissionService $permissions,
        private readonly Database $database,
        private readonly ?\PDO $orgConnection = null,
    ) {
    }

    /**
     * Finds the organization for a slug. Unknown or archived organizations look the same (404).
     *
     * @throws HttpException 404 not found, 403 when the organization is suspended
     */
    public function resolve(string $slug): Tenant
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,30}[a-z0-9]$/', $slug) !== 1) {
            throw HttpException::notFound();
        }

        $organization = $this->organizations->findBySlug($slug);
        if ($organization === null || $organization->status === 'archived') {
            throw HttpException::notFound();
        }
        if ($organization->status === 'suspended') {
            throw HttpException::forbidden('This organization is currently unavailable.');
        }

        return new Tenant($organization, $this->database, connection: $this->orgConnection);
    }

    /**
     * Confirms the signed-in staff member has an active membership in the organization.
     * Group-level accounts (Super Admin, Group Viewer) get no access to organization apps through this door.
     *
     * @throws HttpException 403
     */
    public function authorizeStaff(Tenant $tenant, StaffUser $user): Tenant
    {
        $membership = $this->permissions->activeMembership($user, $tenant->organization);
        if ($membership === null) {
            throw HttpException::forbidden('Your account does not have access to this organization.');
        }

        return $tenant->withMembership($membership);
    }
}
