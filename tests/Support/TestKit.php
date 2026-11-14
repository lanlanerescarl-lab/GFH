<?php

declare(strict_types=1);

namespace Gfh\Tests\Support;

use Gfh\Core\Auth\PasswordPolicy;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Config\Config;
use Gfh\Core\Repository\CustomerAccountRepository;
use Gfh\Core\Repository\LoginAttemptRepository;
use Gfh\Core\Repository\MembershipRepository;
use Gfh\Core\Repository\OrganizationCustomerRepository;
use Gfh\Core\Repository\OrganizationRepository;
use Gfh\Core\Repository\PasswordResetRepository;
use Gfh\Core\Repository\PermissionRepository;
use Gfh\Core\Repository\UserRepository;
use Gfh\Core\Service\AccountService;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Service\AuthService;
use Gfh\Core\Service\PasswordResetService;
use Gfh\Core\Service\PermissionService;
use Gfh\Core\Tenancy\Organization;

/** Builds the services on the test connections and creates test data. */
final class TestKit
{
    public const PASSWORD = 'correct-horse-battery';

    public readonly Config $config;
    public readonly PasswordPolicy $passwords;
    public readonly UserRepository $users;
    public readonly CustomerAccountRepository $customers;
    public readonly MembershipRepository $memberships;
    public readonly OrganizationRepository $organizations;
    public readonly OrganizationCustomerRepository $orgCustomers;
    public readonly AuditService $audit;
    public readonly PermissionService $permissions;
    public readonly AuthService $auth;
    public readonly AccountService $accounts;
    public readonly PasswordResetService $resets;

    public function __construct(public readonly \PDO $core, public readonly \PDO $org)
    {
        $this->config = new Config([
            'LOGIN_MAX_ATTEMPTS' => '3',
            'LOGIN_LOCKOUT_MINUTES' => '15',
            'APP_KEY' => 'test-key',
            'APP_URL' => 'http://localhost:8000',
            'RESET_TOKEN_HOURS' => '24',
        ]);
        $this->passwords = new PasswordPolicy();
        $this->users = new UserRepository($core);
        $this->customers = new CustomerAccountRepository($core);
        $this->memberships = new MembershipRepository($core);
        $this->organizations = new OrganizationRepository($core);
        $this->orgCustomers = new OrganizationCustomerRepository($org);
        $this->audit = new AuditService($core);
        $this->permissions = new PermissionService($this->memberships, new PermissionRepository($core));
        $this->auth = new AuthService(
            $this->users,
            $this->customers,
            new LoginAttemptRepository($core),
            $this->audit,
            $this->passwords,
            $this->config,
        );
        $this->accounts = new AccountService($core, $this->users, $this->memberships, $this->permissions, $this->audit, $this->passwords);
        $this->resets = new PasswordResetService(
            $core,
            new PasswordResetRepository($core),
            $this->users,
            $this->customers,
            $this->accounts,
            $this->permissions,
            $this->audit,
            $this->passwords,
            $this->config,
        );
    }

    public function organization(string $slug): Organization
    {
        return $this->organizations->create($slug, 'Test ' . $slug, strtoupper($slug), 'not-a-real-secret', 'public', [], []);
    }

    /** Creates a staff account. A null password makes it a pending account without a password. */
    public function staff(string $email, ?string $password = self::PASSWORD, ?string $orgRole = null, string $status = 'active', string $name = 'Test Person'): StaffUser
    {
        $hash = $password === null ? null : $this->passwords->hash($password);
        $id = $this->users->create($name, $email, $hash, $orgRole, $status);

        return new StaffUser($id, $name, $email, $orgRole, $status);
    }

    /** Creates a staff account with a membership in the organization. */
    public function member(Organization $organization, string $email, string $role, string $membershipStatus = 'active'): StaffUser
    {
        $user = $this->staff($email);
        $this->memberships->create((string) $user->id, $organization->id, $role, $membershipStatus);

        return $user;
    }

    /** Adds an existing account to another organization. */
    public function join(Organization $organization, StaffUser $user, string $role): void
    {
        $this->memberships->create((string) $user->id, $organization->id, $role);
    }

    /** @return array<string, mixed> */
    public function userRow(StaffUser $user): array
    {
        return $this->users->findById((string) $user->id) ?? throw new \RuntimeException('User row missing.');
    }

    /** Pulls the token out of a generated reset link. */
    public function tokenFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['token'];
    }
}
