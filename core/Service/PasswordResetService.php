<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\PasswordPolicy;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Config\Config;
use Gfh\Core\Database\Database;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\CustomerAccountRepository;
use Gfh\Core\Repository\OrganizationCustomerRepository;
use Gfh\Core\Repository\PasswordResetRepository;
use Gfh\Core\Repository\UserRepository;
use Gfh\Core\Support\Validation;
use Gfh\Core\Tenancy\Organization;

/**
 * One-time password reset links. There is no email service: an authorised admin generates a link and passes it on.
 *
 *  - Staff links: an organization Admin for their own Staff and Cashier accounts (AccountService decides), or Super Admin.
 *  - Customer links: an organization Admin for customers linked to that organization, or Super Admin.
 *
 * Only a hash of the token is stored, a link works once, a newer link retires older ones, and it expires.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly \PDO $core,
        private readonly PasswordResetRepository $tokens,
        private readonly UserRepository $users,
        private readonly CustomerAccountRepository $customers,
        private readonly AccountService $accounts,
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
        private readonly PasswordPolicy $passwords,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{url: string, expires_at: string}
     * @throws HttpException
     */
    public function issueForStaff(StaffUser $actor, Organization $organization, string $email): array
    {
        $target = $this->accounts->findTeamMemberForCredentials($actor, $organization, $email);

        return $this->issue('user', (string) $target['id'], $actor, $organization);
    }

    /**
     * @return array{url: string, expires_at: string}
     * @throws HttpException
     */
    public function issueForCustomer(StaffUser $actor, Organization $organization, OrganizationCustomerRepository $organizationCustomers, string $email): array
    {
        $this->assertMayIssueForCustomers($actor, $organization);

        $cleanEmail = Validation::email($email);
        if ($cleanEmail === null) {
            throw HttpException::unprocessable(['email' => 'Enter a valid email address.']);
        }

        $notFound = HttpException::unprocessable(['email' => 'No customer of this organization has that email address.']);

        $customer = $this->customers->findByEmail($cleanEmail);
        if ($customer === null) {
            throw $notFound;
        }
        if (!$actor->isSuperAdmin() && $organizationCustomers->findByCoreId((string) $customer['id']) === null) {
            throw $notFound;
        }

        return $this->issue('customer', (string) $customer['id'], $actor, $organization);
    }

    /**
     * Sets a new password from a link. Returns whose password was set.
     *
     * @return array{subject_type: string}
     * @throws HttpException 422 for a bad password or an invalid, used or expired link
     */
    public function consume(string $token, string $newPassword): array
    {
        $invalid = HttpException::unprocessable(['token' => 'This reset link is invalid or has expired.']);

        $token = trim($token);
        if (preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token) !== 1) {
            throw $invalid;
        }

        $row = $this->tokens->findValid(hash('sha256', $token));
        if ($row === null) {
            throw $invalid;
        }

        $subjectType = (string) $row['subject_type'];
        $subjectId = (string) $row['subject_id'];
        $subject = $subjectType === 'user' ? $this->users->findById($subjectId) : $this->customers->findById($subjectId);
        if ($subject === null) {
            throw $invalid;
        }

        // Checked before the link is used up, so a too-short password does not waste it.
        $this->passwords->assertValid($newPassword, (string) $subject['email']);
        $hash = $this->passwords->hash($newPassword);

        Database::transaction($this->core, function () use ($row, $subjectType, $subjectId, $hash, $invalid): void {
            if (!$this->tokens->markUsed((string) $row['id'])) {
                throw $invalid;
            }

            if ($subjectType === 'user') {
                $this->users->setPassword($subjectId, $hash);
            } else {
                $this->customers->setPassword($subjectId, $hash);
            }

            $this->audit->core('password_reset.completed', $subjectType === 'user' ? $subjectId : null, null, $subjectType, $subjectId, []);
        });

        return ['subject_type' => $subjectType];
    }

    /** @return array{url: string, expires_at: string} */
    private function issue(string $subjectType, string $subjectId, StaffUser $actor, Organization $organization): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hours = max(1, $this->config->int('RESET_TOKEN_HOURS', 24));
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . $hours . ' hours');

        Database::transaction($this->core, function () use ($subjectType, $subjectId, $token, $expiresAt, $actor, $organization): void {
            $this->tokens->supersedeUnused($subjectType, $subjectId);
            $this->tokens->create($subjectType, $subjectId, hash('sha256', $token), $expiresAt, $actor->id);
            // The token itself is never written to the audit log.
            $this->audit->core('password_reset.issued', $actor->id, $organization->id, $subjectType, $subjectId, ['expires_at' => $expiresAt->format('c')]);
        });

        $base = rtrim($this->config->string('APP_URL', 'http://localhost:8000'), '/');

        return [
            'url' => $base . '/' . $organization->slug . '/reset-password?token=' . $token,
            'expires_at' => $expiresAt->setTimezone(new \DateTimeZone(Config::TIMEZONE))->format('d M Y, g:i A'),
        ];
    }

    private function assertMayIssueForCustomers(StaffUser $actor, Organization $organization): void
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
            || !$this->permissions->can($actor, $organization, 'sales', 'edit')) {
            throw HttpException::forbidden();
        }
    }
}
