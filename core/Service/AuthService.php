<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Auth\PasswordPolicy;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Config\Config;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\CustomerAccountRepository;
use Gfh\Core\Repository\LoginAttemptRepository;
use Gfh\Core\Repository\OrganizationCustomerRepository;
use Gfh\Core\Repository\UserRepository;
use Gfh\Core\Support\Validation;

/**
 * Checks credentials for staff and customers. It does not touch sessions; the caller does that after success.
 *
 * Sign-in never reveals which part was wrong: an unknown email, a wrong password, an inactive account and an
 * account with no password all give the same error, and take about the same time. Too many failures for one
 * email (known or not), or from one address, lock further tries for a while with one shared message.
 */
final class AuthService
{
    public const INVALID_MESSAGE = 'The email or password is not correct.';
    public const LOCKED_MESSAGE = 'Too many sign-in attempts. Please wait a while and try again.';

    private ?string $dummyHash = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly CustomerAccountRepository $customers,
        private readonly LoginAttemptRepository $attempts,
        private readonly AuditService $audit,
        private readonly PasswordPolicy $passwords,
        private readonly Config $config,
    ) {
    }

    /** @throws HttpException 401 invalid_credentials, 429 too_many_requests */
    public function authenticateStaff(string $email, string $password, string $ip): StaffUser
    {
        $email = strtolower(trim($email));
        $hash = $this->identifierHash($email);
        $this->assertNotLocked('staff', $hash, $ip);

        $row = $email === '' ? null : $this->users->findByEmail($email);
        $storedHash = ($row !== null && is_string($row['password_hash'])) ? $row['password_hash'] : null;
        $passwordOk = $this->checkPassword($password, $storedHash);

        if ($row === null || !$passwordOk || $row['status'] !== 'active') {
            $this->attempts->record('staff', $hash, $ip, false);
            $this->audit->core('auth.staff_login_failed', null, null, 'user', $row !== null ? (string) $row['id'] : null, ['identifier' => substr($hash, 0, 12), 'ip' => $ip]);

            throw $this->invalidCredentials();
        }

        $this->attempts->record('staff', $hash, $ip, true);
        $this->users->touchLogin((string) $row['id']);
        if ($storedHash !== null && $this->passwords->needsRehash($storedHash)) {
            $this->users->replacePasswordHash((string) $row['id'], $this->passwords->hash($password));
        }
        $this->audit->core('auth.staff_login', (string) $row['id'], null, 'user', (string) $row['id'], ['ip' => $ip]);

        return StaffUser::fromRow($row);
    }

    /**
     * @param OrganizationCustomerRepository|null $organizationCustomers when given, the customer is linked to that organization
     * @throws HttpException 401 invalid_credentials, 429 too_many_requests
     */
    public function authenticateCustomer(string $email, string $password, string $ip, ?OrganizationCustomerRepository $organizationCustomers = null): CustomerAccount
    {
        $email = strtolower(trim($email));
        $hash = $this->identifierHash($email);
        $this->assertNotLocked('customer', $hash, $ip);

        $row = $email === '' ? null : $this->customers->findByEmail($email);
        $storedHash = ($row !== null && is_string($row['password_hash'])) ? $row['password_hash'] : null;
        $passwordOk = $this->checkPassword($password, $storedHash);

        if ($row === null || !$passwordOk || $row['status'] !== 'active') {
            $this->attempts->record('customer', $hash, $ip, false);
            $this->audit->core('auth.customer_login_failed', null, null, 'customer', $row !== null ? (string) $row['id'] : null, ['identifier' => substr($hash, 0, 12), 'ip' => $ip]);

            throw $this->invalidCredentials();
        }

        $this->attempts->record('customer', $hash, $ip, true);
        if ($storedHash !== null && $this->passwords->needsRehash($storedHash)) {
            $this->customers->setPassword((string) $row['id'], $this->passwords->hash($password));
        }
        $this->audit->core('auth.customer_login', null, null, 'customer', (string) $row['id'], ['ip' => $ip]);

        $account = CustomerAccount::fromRow($row);
        $organizationCustomers?->ensureLinked($account->id, $account->fullName, $account->email, $account->phone);

        return $account;
    }

    /** @throws HttpException 422 with field messages, 409 when the email already has an account */
    public function registerCustomer(
        string $fullName,
        string $email,
        string $password,
        ?string $phone = null,
        ?OrganizationCustomerRepository $organizationCustomers = null,
    ): CustomerAccount {
        $errors = [];
        $cleanName = Validation::personName($fullName);
        $cleanEmail = Validation::email($email);
        $cleanPhone = Validation::phone($phone);

        if ($cleanName === null) {
            $errors['full_name'] = 'Enter your full name (2 to 120 characters).';
        }
        if ($cleanEmail === null) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($cleanPhone === null) {
            $errors['phone'] = 'Enter a valid phone number, or leave it empty.';
        }
        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        $this->passwords->assertValid($password, $cleanEmail);

        if ($this->customers->findByEmail($cleanEmail) !== null) {
            throw HttpException::conflict(
                'An account with this email already exists. Sign in, or ask the shop for a password reset link.',
                ['email' => 'This email already has an account.'],
            );
        }

        $id = $this->customers->create($cleanName, $cleanEmail, $cleanPhone, $this->passwords->hash($password));
        $this->audit->core('customer.registered', null, null, 'customer', $id, []);

        $account = new CustomerAccount($id, $cleanName, $cleanEmail, $cleanPhone === '' ? null : $cleanPhone, 'active');
        $organizationCustomers?->ensureLinked($account->id, $account->fullName, $account->email, $account->phone);

        return $account;
    }

    /**
     * Refuses a customer who is locked out, with the same 429 and message as sign-in. Used by actions that ask for the
     * current password (changing it), so they cannot be used to guess it without limit.
     *
     * @throws HttpException 429 too_many_requests
     */
    public function assertCustomerNotLocked(string $email, string $ip): void
    {
        $this->assertNotLocked('customer', $this->identifierHash(strtolower(trim($email))), $ip);
    }

    /** Records a password check by a signed-in customer in the same log, scope and identifier that sign-in uses. */
    public function recordCustomerPasswordCheck(string $email, string $ip, bool $succeeded): void
    {
        $this->attempts->record('customer', $this->identifierHash(strtolower(trim($email))), $ip, $succeeded);
    }

    private function invalidCredentials(): HttpException
    {
        return new HttpException(401, 'invalid_credentials', self::INVALID_MESSAGE);
    }

    /**
     * Always runs one password check, against a throwaway hash when there is no real one,
     * so an unknown email takes about as long as a wrong password.
     */
    private function checkPassword(string $password, ?string $storedHash): bool
    {
        if ($storedHash === null) {
            $this->dummyHash ??= $this->passwords->hash(bin2hex(random_bytes(12)));
            $this->passwords->verify($password, $this->dummyHash);

            return false;
        }

        return $this->passwords->verify($password, $storedHash);
    }

    private function identifierHash(string $email): string
    {
        return hash_hmac('sha256', $email, $this->config->string('APP_KEY', 'gfh-login-identifier'));
    }

    private function assertNotLocked(string $scope, string $identifierHash, string $ip): void
    {
        $maxAttempts = max(1, $this->config->int('LOGIN_MAX_ATTEMPTS', 5));
        $windowSeconds = max(1, $this->config->int('LOGIN_LOCKOUT_MINUTES', 15)) * 60;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $since = $now->modify('-' . $windowSeconds . ' seconds');

        [$failures, $oldest] = $this->attempts->failuresForIdentifier($scope, $identifierHash, $since);
        if ($failures >= $maxAttempts) {
            $retry = $oldest === null ? $windowSeconds : $windowSeconds - ($now->getTimestamp() - $oldest->getTimestamp());
            throw new HttpException(429, 'too_many_requests', self::LOCKED_MESSAGE, [], ['Retry-After' => (string) max(1, $retry)]);
        }

        // One address trying many different emails is also slowed down.
        if ($this->attempts->failuresForIp($ip, $since) >= $maxAttempts * 4) {
            throw new HttpException(429, 'too_many_requests', self::LOCKED_MESSAGE, [], ['Retry-After' => (string) $windowSeconds]);
        }
    }
}
