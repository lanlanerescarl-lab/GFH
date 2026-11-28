<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\SuperAdminUserRepository;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Organization;

/**
 * Super Admin write coordinator for Users and Access (SA.2a).
 *
 * Enforces PermissionService::can($actor, null, 'users_access', 'edit'|'delete') at the service level,
 * resolves target organizations without selecting connection_encrypted or opening an organization database,
 * and delegates every account, membership, and password-reset rule to AccountService and PasswordResetService.
 */
final class SuperAdminUserService
{
    public function __construct(
        private readonly SuperAdminUserRepository $users,
        private readonly AccountService $accounts,
        private readonly PasswordResetService $resets,
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * Creates a staff account (or adds a membership for an existing email) in one organization.
     *
     * @return array{user_id: string, created_user: bool, membership: string}
     */
    public function createStaffAccount(
        StaffUser $actor,
        string $organizationId,
        string $email,
        string $fullName,
        string $role,
        ?string $password = null,
    ): array {
        $this->permissions->assert($actor, null, 'users_access', 'edit');
        $organization = $this->requireOrganizationField($organizationId);

        return $this->accounts->createStaffAccount($actor, $organization, $email, $fullName, $role, $password);
    }

    /**
     * Creates a Super Admin or Group Viewer account.
     *
     * @return array{user_id: string}
     */
    public function createGroupAccount(
        StaffUser $actor,
        string $email,
        string $fullName,
        string $orgRole,
        ?string $password = null,
    ): array {
        $this->permissions->assert($actor, null, 'users_access', 'edit');

        return $this->accounts->createGroupAccount($actor, $email, $fullName, $orgRole, $password);
    }

    /**
     * Adds an organization membership to an existing account (reusing AccountService's existing-email flow).
     *
     * @return array{user_id: string, created_user: bool, membership: string}
     */
    public function addMembership(StaffUser $actor, string $userId, string $organizationId, string $role): array
    {
        $this->permissions->assert($actor, null, 'users_access', 'edit');
        $user = $this->requireUser($userId);
        $organization = $this->requireOrganizationField($organizationId);

        return $this->accounts->createStaffAccount(
            $actor,
            $organization,
            (string) $user['email'],
            (string) $user['full_name'],
            $role,
        );
    }

    /** Changes a user's role in one organization. Enforces the last-active-Admin rule in AccountService. */
    public function changeRole(StaffUser $actor, string $userId, string $organizationId, string $newRole): void
    {
        $this->permissions->assert($actor, null, 'users_access', 'edit');
        $organization = $this->requireOrganizationParam($organizationId);

        $this->accounts->changeRole($actor, $organization, $userId, $newRole);
    }

    /** Deactivates a user's membership in one organization. Enforces the last-active-Admin rule in AccountService. */
    public function deactivateMembership(StaffUser $actor, string $userId, string $organizationId): void
    {
        $this->permissions->assert($actor, null, 'users_access', 'delete');
        $organization = $this->requireOrganizationParam($organizationId);

        $this->accounts->deactivate($actor, $organization, $userId);
    }

    /** Reactivates a user's membership in one organization. */
    public function reactivateMembership(StaffUser $actor, string $userId, string $organizationId): void
    {
        $this->permissions->assert($actor, null, 'users_access', 'edit');
        $organization = $this->requireOrganizationParam($organizationId);

        $this->accounts->reactivate($actor, $organization, $userId);
    }

    /**
     * Issues a one-time password reset link through PasswordResetService for an account with at least one membership.
     * Group-only accounts (no memberships) are refused here; a group password-reset route is out of scope for SA.2a.
     *
     * @return array{
     *     user: array<string, mixed>,
     *     organization: array{id: string, name: string, slug: string},
     *     link: array{url: string, expires_at: string},
     * }
     */
    public function issueResetLink(StaffUser $actor, string $userId, string $organizationId = ''): array
    {
        $this->permissions->assert($actor, null, 'users_access', 'edit');
        $user = $this->requireUser($userId);

        /** @var list<array<string, mixed>> $memberships */
        $memberships = is_array($user['memberships'] ?? null) ? $user['memberships'] : [];
        if ($memberships === []) {
            throw HttpException::unprocessable([
                'organization_id' => 'This account has no organization membership. A one-time reset link requires at least one membership.',
            ]);
        }

        $chosenOrgId = trim($organizationId);
        if ($chosenOrgId === '') {
            if (count($memberships) === 1) {
                $chosenOrgId = (string) $memberships[0]['organization_id'];
            } else {
                throw HttpException::unprocessable([
                    'organization_id' => 'Choose an organization for the reset link.',
                ]);
            }
        }

        $belongs = false;
        foreach ($memberships as $membership) {
            if ((string) $membership['organization_id'] === $chosenOrgId) {
                $belongs = true;
                break;
            }
        }
        if (!$belongs) {
            throw HttpException::unprocessable([
                'organization_id' => 'Choose an organization this account belongs to.',
            ]);
        }

        $organization = $this->requireOrganizationField($chosenOrgId);
        $link = $this->resets->issueForStaff($actor, $organization, (string) $user['email']);

        return [
            'user' => SuperAdminUserQueryService::presentUser($user),
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'link' => $link,
        ];
    }

    /** @return array<string, mixed> */
    private function requireUser(string $userId): array
    {
        if (!Uuid::isValid($userId)) {
            throw HttpException::notFound('That account could not be found.');
        }

        return $this->users->findById($userId) ?? throw HttpException::notFound('That account could not be found.');
    }

    private function requireOrganizationField(string $organizationId): Organization
    {
        $clean = trim($organizationId);
        if ($clean === '' || !Uuid::isValid($clean)) {
            throw HttpException::unprocessable(['organization_id' => 'Choose an organization.']);
        }

        $organization = $this->users->findOrganizationForService($clean);
        if ($organization === null) {
            throw HttpException::unprocessable(['organization_id' => 'Choose a registered organization.']);
        }

        return $organization;
    }

    private function requireOrganizationParam(string $organizationId): Organization
    {
        if (!Uuid::isValid($organizationId)) {
            throw HttpException::notFound('That organization could not be found.');
        }

        return $this->users->findOrganizationForService($organizationId)
            ?? throw HttpException::notFound('That organization could not be found.');
    }
}
