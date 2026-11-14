<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Service\AuthService;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;

final class AuthServiceTest extends DatabaseTestCase
{
    public function testStaffCanSignInWithTheRightPassword(): void
    {
        $this->kit->staff('maria@example.test', name: 'Maria Santos');

        $user = $this->kit->auth->authenticateStaff('Maria@Example.test', TestKit::PASSWORD, '203.0.113.5');

        $this->assertSame('Maria Santos', $user->fullName);
        $this->assertSame(1, $this->countAudit('auth.staff_login'));
    }

    public function testPasswordsAreStoredOnlyAsHashes(): void
    {
        $user = $this->kit->staff('hash@example.test');
        $row = $this->kit->userRow($user);

        $this->assertNotSame(TestKit::PASSWORD, $row['password_hash']);
        $this->assertTrue(password_verify(TestKit::PASSWORD, (string) $row['password_hash']));
    }

    public function testFailedSignInsNeverRevealWhichPartWasWrong(): void
    {
        $this->kit->staff('real@example.test');
        $this->kit->staff('inactive@example.test', status: 'inactive');
        $this->kit->staff('pending@example.test', password: null, status: 'pending');

        $attempts = [
            'wrong password' => ['real@example.test', 'not-the-password'],
            'unknown email' => ['nobody@example.test', TestKit::PASSWORD],
            'inactive account' => ['inactive@example.test', TestKit::PASSWORD],
            'no password yet' => ['pending@example.test', TestKit::PASSWORD],
            'empty email' => ['', TestKit::PASSWORD],
        ];

        $seen = [];
        foreach ($attempts as $label => [$email, $password]) {
            $exception = $this->assertHttpStatus(401, fn () => $this->kit->auth->authenticateStaff($email, $password, '203.0.113.' . (count($seen) + 10)));
            $seen[$label] = [$exception->status(), $exception->errorCode(), $exception->getMessage(), $exception->fields()];
        }

        $this->assertCount(1, array_unique(array_map('serialize', $seen)), 'every failure must look identical');
        $this->assertSame(AuthService::INVALID_MESSAGE, $seen['unknown email'][2]);
    }

    public function testRepeatedFailuresLockSignInWithOneSharedMessage(): void
    {
        $this->kit->staff('locked@example.test');

        for ($i = 0; $i < 3; $i++) {
            $this->assertHttpStatus(401, fn () => $this->kit->auth->authenticateStaff('locked@example.test', 'wrong-password-' . $i, '198.51.100.7'));
        }

        // The right password is refused while locked.
        $known = $this->assertHttpStatus(429, fn () => $this->kit->auth->authenticateStaff('locked@example.test', TestKit::PASSWORD, '198.51.100.7'));

        // An email that does not exist is locked in exactly the same way, so lockout does not leak who has an account.
        for ($i = 0; $i < 3; $i++) {
            $this->assertHttpStatus(401, fn () => $this->kit->auth->authenticateStaff('ghost@example.test', 'wrong-password-' . $i, '198.51.100.8'));
        }
        $unknown = $this->assertHttpStatus(429, fn () => $this->kit->auth->authenticateStaff('ghost@example.test', 'wrong-password', '198.51.100.8'));

        $this->assertSame($known->getMessage(), $unknown->getMessage());
        $this->assertSame($known->errorCode(), $unknown->errorCode());
        $this->assertArrayHasKey('Retry-After', $known->headers());
    }

    public function testASuccessfulSignInClearsEarlierFailures(): void
    {
        $this->kit->staff('forgetful@example.test');

        for ($i = 0; $i < 2; $i++) {
            $this->assertHttpStatus(401, fn () => $this->kit->auth->authenticateStaff('forgetful@example.test', 'wrong-' . $i, '192.0.2.9'));
        }
        $this->kit->auth->authenticateStaff('forgetful@example.test', TestKit::PASSWORD, '192.0.2.9');

        // Two more failures would have made four in total, but the count restarted at the success.
        for ($i = 0; $i < 2; $i++) {
            $this->assertHttpStatus(401, fn () => $this->kit->auth->authenticateStaff('forgetful@example.test', 'wrong-again-' . $i, '192.0.2.9'));
        }
        $this->addToAssertionCount(1);
    }

    public function testCustomersRegisterAndSignInAcrossShops(): void
    {
        $account = $this->kit->auth->registerCustomer('Juan Dela Cruz', 'Juan@Example.test', 'a-long-password-1', '0917 123 4567', $this->kit->orgCustomers);

        $this->assertSame('juan@example.test', $account->email);
        $this->assertNotNull($this->kit->orgCustomers->findByCoreId($account->id), 'the customer is linked to the organization');

        $again = $this->kit->auth->authenticateCustomer('juan@example.test', 'a-long-password-1', '192.0.2.20', $this->kit->orgCustomers);
        $this->assertSame($account->id, $again->id);
        $this->assertSame(1, $this->countAudit('auth.customer_login'));
    }

    public function testCustomerRegistrationIsValidatedOnTheServer(): void
    {
        $weak = $this->assertHttpStatus(422, fn () => $this->kit->auth->registerCustomer('Ana Reyes', 'ana@example.test', 'short'));
        $this->assertArrayHasKey('password', $weak->fields());

        $bad = $this->assertHttpStatus(422, fn () => $this->kit->auth->registerCustomer('A', 'not-an-email', 'a-long-password-1', 'abc'));
        $this->assertSame(['full_name', 'email', 'phone'], array_keys($bad->fields()));

        $this->kit->auth->registerCustomer('Ana Reyes', 'ana@example.test', 'a-long-password-1');
        $this->assertHttpStatus(409, fn () => $this->kit->auth->registerCustomer('Ana Again', 'ANA@example.test', 'another-long-password'));
    }

    public function testCustomerSignInFailuresLookAlike(): void
    {
        $this->kit->auth->registerCustomer('Ana Reyes', 'ana@example.test', 'a-long-password-1');

        $wrongPassword = $this->assertHttpStatus(401, fn () => $this->kit->auth->authenticateCustomer('ana@example.test', 'nope-nope-nope', '192.0.2.30'));
        $unknownEmail = $this->assertHttpStatus(401, fn () => $this->kit->auth->authenticateCustomer('nobody@example.test', 'a-long-password-1', '192.0.2.31'));

        $this->assertSame($wrongPassword->getMessage(), $unknownEmail->getMessage());
        $this->assertSame($wrongPassword->errorCode(), $unknownEmail->errorCode());
    }
}
