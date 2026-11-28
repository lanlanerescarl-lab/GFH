<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\Modules;
use Gfh\Core\Auth\PasswordPolicy;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Database\Database;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\MembershipRepository;
use Gfh\Core\Repository\UserRepository;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\Validation;
use Gfh\Core\Tenancy\Organization;

/**
 * Creates and manages staff accounts and memberships. Shared by the Admin app and the Super Admin app.
 *
 * The rules, enforced here and nowhere else:
 *  - Super Admin may do anything to any account.
 *  - An organization Admin may only create, change and deactivate Staff and Cashier accounts, only in their
 *    own organization. Admin and Manager accounts, and other organizations' members, are out of reach.
 *  - An Admin may add a membership for an email that already exists, but that never changes the person's
 *    password or profile. And an Admin cannot change the password or profile of a person who also belongs to
 *    another organization (they can still manage that person's membership in their own).
 *  - Every change is written to the audit log.
 */
final class AccountService
{
    /** Sort names the Team list accepts (name => SQL expression, fixed in the repository). */
    public const TEAM_SORTS = MembershipRepository::TEAM_SORTS;

    public const TEAM_STATUSES = ['active', 'inactive', 'pending'];

    public function __construct(
        private readonly \PDO $core,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
        private readonly PasswordPolicy $passwords,
    ) {
    }

    /**
     * Adds a person to an organization's team, creating their account if the email is new.
     * With no password the account starts "pending" and the person sets a password through a reset link.
     *
     * @return array{user_id: string, created_user: bool, membership: string}
     * @throws HttpException 403 for a rule violation, 422 for invalid fields
     */
    public function createStaffAccount(
        StaffUser $actor,
        Organization $organization,
        string $email,
        string $fullName,
        string $role,
        ?string $password = null,
    ): array {
        $this->assertMayManageTeam($actor, $organization);

        $cleanEmail = Validation::email($email);
        $errors = [];
        if ($cleanEmail === null) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (!in_array($role, Modules::ROLES, true)) {
            $errors['role'] = 'Choose Admin, Manager, Staff or Cashier.';
        }
        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        if (!$actor->isSuperAdmin() && !in_array($role, Modules::ORG_ADMIN_MANAGEABLE_ROLES, true)) {
            throw HttpException::forbidden('Only a Super Admin can create Admin or Manager accounts.');
        }

        $existing = $this->users->findByEmail((string) $cleanEmail);

        return Database::transaction($this->core, function () use ($actor, $organization, $cleanEmail, $fullName, $role, $password, $existing): array {
            if ($existing !== null) {
                return $this->addMembershipForExistingUser($actor, $organization, $existing, $role);
            }

            $cleanName = Validation::personName($fullName);
            if ($cleanName === null) {
                throw HttpException::unprocessable(['full_name' => 'Enter the full name (2 to 120 characters).']);
            }

            $hash = null;
            if ($password !== null && $password !== '') {
                $this->passwords->assertValid($password, (string) $cleanEmail);
                $hash = $this->passwords->hash($password);
            }

            $userId = $this->users->create($cleanName, (string) $cleanEmail, $hash, null, $hash === null ? 'pending' : 'active');
            $this->memberships->create($userId, $organization->id, $role);
            $this->audit->core('user.created', $actor->id, $organization->id, 'user', $userId, ['role' => $role, 'via' => $actor->isSystem ? 'cli' : 'app']);
            $this->audit->core('membership.added', $actor->id, $organization->id, 'user', $userId, ['role' => $role]);

            return ['user_id' => $userId, 'created_user' => true, 'membership' => 'added'];
        });
    }

    /**
     * Creates a Super Admin or Group Viewer account. Super Admin only.
     *
     * @return array{user_id: string}
     */
    public function createGroupAccount(StaffUser $actor, string $email, string $fullName, string $orgRole, ?string $password = null): array
    {
        if (!$actor->isActive() || !$actor->isSuperAdmin()) {
            throw HttpException::forbidden('Only a Super Admin can create group-level accounts.');
        }

        $cleanEmail = Validation::email($email);
        $cleanName = Validation::personName($fullName);
        $errors = [];
        if ($cleanEmail === null) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($cleanName === null) {
            $errors['full_name'] = 'Enter the full name (2 to 120 characters).';
        }
        if (!in_array($orgRole, Modules::GROUP_ROLES, true)) {
            $errors['org_role'] = 'Choose Super Admin or Group Viewer.';
        }
        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }
        if ($this->users->findByEmail((string) $cleanEmail) !== null) {
            throw HttpException::conflict('An account with this email already exists.', ['email' => 'This email already has an account.']);
        }

        $hash = null;
        if ($password !== null && $password !== '') {
            $this->passwords->assertValid($password, (string) $cleanEmail);
            $hash = $this->passwords->hash($password);
        }

        return Database::transaction($this->core, function () use ($actor, $cleanEmail, $cleanName, $orgRole, $hash): array {
            $userId = $this->users->create((string) $cleanName, (string) $cleanEmail, $hash, $orgRole, $hash === null ? 'pending' : 'active');
            $this->audit->core('user.created', $actor->id, null, 'user', $userId, ['org_role' => $orgRole, 'via' => $actor->isSystem ? 'cli' : 'app']);

            return ['user_id' => $userId];
        });
    }

    /**
     * The organization's team for the Team page, with the same list parameters as every other list. Admin and Manager
     * accounts are included so the page shows the whole team; the other methods here still refuse to change them.
     *
     * Filters: "role" (admin, manager, staff or cashier) and "status" (active, inactive or pending). Search matches
     * name and email.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string}
     * @throws HttpException 403 unless the actor may manage this team, 422 for an unknown role or status filter
     */
    public function listTeam(StaffUser $actor, Organization $organization, ListQuery $query): array
    {
        $this->assertMayManageTeam($actor, $organization);

        $role = $query->filter('role');
        $status = $query->filter('status');
        $errors = [];
        if (is_string($role) && $role !== '' && !in_array($role, Modules::ROLES, true)) {
            $errors['role'] = 'Choose Admin, Manager, Staff or Cashier.';
        }
        if (is_string($status) && $status !== '' && !in_array($status, self::TEAM_STATUSES, true)) {
            $errors['status'] = 'Choose active, inactive or pending.';
        }
        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        [$rows, $total] = $this->memberships->listTeam(
            $organization->id,
            $query,
            is_string($role) && $role !== '' ? $role : null,
            is_string($status) && $status !== '' ? $status : null,
        );

        $rows = array_map(fn (array $row): array => $this->withManageable($actor, $row), $rows);

        return $query->result($rows, $total);
    }

    /**
     * One person on this organization's team, for the edit screen and for the reset-link screen. Someone who is not on
     * this team (another organization's member, a group-level account, an unknown id) gets the same 404.
     *
     * @return array<string, mixed>
     * @throws HttpException 403 unless the actor may manage this team, 404 when the person is not on it
     */
    public function teamMember(StaffUser $actor, Organization $organization, string $userId): array
    {
        $this->assertMayManageTeam($actor, $organization);

        $notFound = HttpException::notFound('That person is not on your team.');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $userId) !== 1) {
            throw $notFound;
        }

        $member = $this->memberships->findTeamMember($userId, $organization->id) ?? throw $notFound;

        return $this->withManageable($actor, $member);
    }

    /**
     * Saves the edit screen in one step: name and email, and the role when it changed. It only calls updateStaffProfile()
     * and changeRole(), so every rule of those two applies unchanged, and either both changes are kept or neither is.
     */
    public function updateTeamMember(StaffUser $actor, Organization $organization, string $targetUserId, string $fullName, string $email, string $role): void
    {
        $member = $this->teamMember($actor, $organization, $targetUserId);

        Database::transaction($this->core, function () use ($actor, $organization, $targetUserId, $fullName, $email, $role, $member): void {
            $this->updateStaffProfile($actor, $organization, $targetUserId, $fullName, $email);
            if ($role !== (string) $member['role']) {
                $this->changeRole($actor, $organization, $targetUserId, $role);
            }
        });
    }

    /** Changes a team member's name and email. Refused for anyone who also belongs to another organization. */
    public function updateStaffProfile(StaffUser $actor, Organization $organization, string $targetUserId, string $fullName, string $email): void
    {
        $target = $this->loadTarget($targetUserId);
        $this->assertMayManageMember($actor, $organization, $target, true);

        $cleanName = Validation::personName($fullName);
        $cleanEmail = Validation::email($email);
        $errors = [];
        if ($cleanName === null) {
            $errors['full_name'] = 'Enter the full name (2 to 120 characters).';
        }
        if ($cleanEmail === null) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        $other = $this->users->findByEmail((string) $cleanEmail);
        if ($other !== null && (string) $other['id'] !== $targetUserId) {
            throw HttpException::unprocessable(['email' => 'Another account already uses this email.']);
        }

        Database::transaction($this->core, function () use ($actor, $organization, $target, $targetUserId, $cleanName, $cleanEmail): void {
            $this->users->updateProfile($targetUserId, (string) $cleanName, (string) $cleanEmail);
            $this->audit->core('user.profile_updated', $actor->id, $organization->id, 'user', $targetUserId, [
                'name_changed' => $cleanName !== (string) $target['full_name'],
                'email_changed' => $cleanEmail !== strtolower((string) $target['email']),
            ]);
        });
    }

    /** Changes a person's role in this organization. An organization Admin can only move people between Staff and Cashier. */
    public function changeRole(StaffUser $actor, Organization $organization, string $targetUserId, string $newRole): void
    {
        if (!in_array($newRole, Modules::ROLES, true)) {
            throw HttpException::unprocessable(['role' => 'Choose Admin, Manager, Staff or Cashier.']);
        }

        $target = $this->loadTarget($targetUserId);
        $membership = $this->assertMayManageMember($actor, $organization, $target, false);
        $this->assertNotSelf($actor, $targetUserId, 'You cannot change your own role.');

        if (!$actor->isSuperAdmin() && !in_array($newRole, Modules::ORG_ADMIN_MANAGEABLE_ROLES, true)) {
            throw HttpException::forbidden('Only a Super Admin can give someone the Admin or Manager role.');
        }
        if ($membership === null) {
            throw HttpException::unprocessable(['user' => 'That person is not on this team.']);
        }

        Database::transaction($this->core, function () use ($actor, $organization, $targetUserId, $target, $membership, $newRole): void {
            if ($newRole !== 'admin') {
                $this->assertNotLastActiveAdmin(
                    $organization,
                    $target,
                    $membership,
                    'role',
                    'This person is the last active Admin of this organization and cannot be moved to another role.',
                );
            }

            $this->memberships->update($targetUserId, $organization->id, $newRole, (string) $membership['status']);
            $this->audit->core('membership.role_changed', $actor->id, $organization->id, 'user', $targetUserId, [
                'from' => $membership['role'],
                'to' => $newRole,
            ]);
        });
    }

    /**
     * Removes a person's access to this organization. Their account itself is switched off only when this was
     * their last active membership and they hold no group-level role.
     */
    public function deactivate(StaffUser $actor, Organization $organization, string $targetUserId): void
    {
        $target = $this->loadTarget($targetUserId);
        $membership = $this->assertMayManageMember($actor, $organization, $target, false);
        $this->assertNotSelf($actor, $targetUserId, 'You cannot deactivate your own account.');

        if ($membership === null) {
            throw HttpException::unprocessable(['user' => 'That person is not on this team.']);
        }

        Database::transaction($this->core, function () use ($actor, $organization, $targetUserId, $target, $membership): void {
            $this->assertNotLastActiveAdmin(
                $organization,
                $target,
                $membership,
                'user',
                'This person is the last active Admin of this organization and cannot be deactivated.',
            );

            $this->memberships->update($targetUserId, $organization->id, (string) $membership['role'], 'inactive');
            $this->audit->core('membership.deactivated', $actor->id, $organization->id, 'user', $targetUserId, ['role' => $membership['role']]);

            $stillActive = $this->memberships->countActiveOtherMemberships($targetUserId, $organization->id) > 0;
            if (!$stillActive && $target['org_role'] === null && $target['status'] === 'active') {
                $this->users->setStatus($targetUserId, 'inactive');
                $this->audit->core('user.deactivated', $actor->id, $organization->id, 'user', $targetUserId, []);
            }
        });
    }

    /** Gives a deactivated person their access back. */
    public function reactivate(StaffUser $actor, Organization $organization, string $targetUserId): void
    {
        $target = $this->loadTarget($targetUserId);
        $membership = $this->assertMayManageMember($actor, $organization, $target, false);

        if ($membership === null) {
            throw HttpException::unprocessable(['user' => 'That person is not on this team.']);
        }

        Database::transaction($this->core, function () use ($actor, $organization, $targetUserId, $target, $membership): void {
            $this->memberships->update($targetUserId, $organization->id, (string) $membership['role'], 'active');
            $this->audit->core('membership.reactivated', $actor->id, $organization->id, 'user', $targetUserId, ['role' => $membership['role']]);

            // The account itself is only touched when this organization is the person's only one.
            if ($target['status'] === 'inactive' && $this->memberships->otherOrganizationIds($targetUserId, $organization->id) === []) {
                $this->users->setStatus($targetUserId, is_string($target['password_hash']) ? 'active' : 'pending');
                $this->audit->core('user.reactivated', $actor->id, $organization->id, 'user', $targetUserId, []);
            }
        });
    }

    /**
     * Finds a team member by email for an action that changes their credentials (a password reset link).
     * An unknown email and a person who is not on this team give the same answer, so an Admin cannot use this
     * to find out who has an account elsewhere.
     *
     * @return array<string, mixed> the user row
     * @throws HttpException 422 not on this team, 403 a rule forbids it
     */
    public function findTeamMemberForCredentials(StaffUser $actor, Organization $organization, string $email): array
    {
        $this->assertMayManageTeam($actor, $organization);

        $cleanEmail = Validation::email($email);
        if ($cleanEmail === null) {
            throw HttpException::unprocessable(['email' => 'Enter a valid email address.']);
        }

        $notOnTeam = HttpException::unprocessable(['email' => 'No one on this team has that email address.']);

        $target = $this->users->findByEmail($cleanEmail);
        if ($target === null) {
            throw $notOnTeam;
        }
        if (!$actor->isSuperAdmin() && $this->memberships->find((string) $target['id'], $organization->id) === null) {
            throw $notOnTeam;
        }

        $this->assertMayManageMember($actor, $organization, $target, true);

        return $target;
    }

    /**
     * @param array<string, mixed> $existing the user row of the email that already exists
     * @return array{user_id: string, created_user: bool, membership: string}
     */
    private function addMembershipForExistingUser(StaffUser $actor, Organization $organization, array $existing, string $role): array
    {
        // The existing account is never modified here: no name, email, password or status change.
        if ($existing['org_role'] !== null && !$actor->isSuperAdmin()) {
            throw HttpException::forbidden('That account cannot be added to an organization team.');
        }

        $userId = (string) $existing['id'];
        $membership = $this->memberships->find($userId, $organization->id);

        if ($membership === null) {
            $this->memberships->create($userId, $organization->id, $role);
            $this->audit->core('membership.added', $actor->id, $organization->id, 'user', $userId, ['role' => $role, 'existing_account' => true]);

            return ['user_id' => $userId, 'created_user' => false, 'membership' => 'added'];
        }

        if ($membership['status'] === 'active') {
            throw HttpException::unprocessable(['email' => 'This person is already on the team.']);
        }

        if (!$actor->isSuperAdmin() && !in_array((string) $membership['role'], Modules::ORG_ADMIN_MANAGEABLE_ROLES, true)) {
            throw HttpException::forbidden('Only a Super Admin can change Admin or Manager accounts.');
        }

        $this->memberships->update($userId, $organization->id, $role, 'active');
        $this->audit->core('membership.reactivated', $actor->id, $organization->id, 'user', $userId, ['role' => $role]);

        return ['user_id' => $userId, 'created_user' => false, 'membership' => 'reactivated'];
    }

    /** The actor must be a Super Admin, or an Admin of this organization who may edit Reports & Admin. */
    private function assertMayManageTeam(StaffUser $actor, Organization $organization): void
    {
        if (!$actor->isActive()) {
            throw HttpException::forbidden();
        }
        if ($actor->isSuperAdmin()) {
            return;
        }

        $membership = $this->permissions->activeMembership($actor, $organization);
        if ($membership === null
            || $membership['role'] !== 'admin'
            || !$this->permissions->can($actor, $organization, 'reports_admin', 'edit')) {
            throw HttpException::forbidden();
        }
    }

    /**
     * Checks that the actor may act on this person. Returns the person's membership here (null only for a Super Admin
     * acting on someone with no membership).
     *
     * @param array<string, mixed> $target
     * @return array<string, mixed>|null
     */
    private function assertMayManageMember(StaffUser $actor, Organization $organization, array $target, bool $changesCredentials): ?array
    {
        $this->assertMayManageTeam($actor, $organization);

        $membership = $this->memberships->find((string) $target['id'], $organization->id);
        if ($actor->isSuperAdmin()) {
            return $membership;
        }

        // Other organizations' members look exactly like people who do not exist.
        if ($membership === null) {
            throw HttpException::forbidden('That person is not on your team.');
        }
        if (!in_array((string) $membership['role'], Modules::ORG_ADMIN_MANAGEABLE_ROLES, true) || $target['org_role'] !== null) {
            throw HttpException::forbidden('Only a Super Admin can change Admin or Manager accounts.');
        }
        if ($changesCredentials && $this->memberships->otherOrganizationIds((string) $target['id'], $organization->id) !== []) {
            throw HttpException::forbidden('This person also belongs to another organization, so only a Super Admin can change their profile or password.');
        }

        return $membership;
    }

    /**
     * Marks whether the actor may change this row. It mirrors assertMayManageMember() for the Team screens: only
     * Staff and Cashier, and never the actor's own account. The checks in the change methods remain the real gate.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withManageable(StaffUser $actor, array $row): array
    {
        $row['manageable'] = in_array((string) $row['role'], Modules::ORG_ADMIN_MANAGEABLE_ROLES, true)
            && ($actor->id === null || $actor->id !== (string) $row['id']);

        return $row;
    }

    /** @return array<string, mixed> */
    private function loadTarget(string $userId): array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $userId) !== 1) {
            throw HttpException::notFound('That account could not be found.');
        }

        return $this->users->findById($userId) ?? throw HttpException::notFound('That account could not be found.');
    }

    private function assertNotSelf(StaffUser $actor, string $targetUserId, string $message): void
    {
        if ($actor->id !== null && $actor->id === $targetUserId) {
            throw HttpException::forbidden($message);
        }
    }

    /**
     * Protects an organization from being left with no active Admin who can sign in and manage it.
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $membership
     */
    private function assertNotLastActiveAdmin(
        Organization $organization,
        array $target,
        array $membership,
        string $field,
        string $message,
    ): void {
        if (
            (string) $membership['role'] !== 'admin'
            || (string) $membership['status'] !== 'active'
            || (string) $target['status'] !== 'active'
        ) {
            return;
        }

        if ($this->memberships->countOtherActiveAdminsForUpdate($organization->id, (string) $target['id']) === 0) {
            throw HttpException::unprocessable([$field => $message]);
        }
    }
}
