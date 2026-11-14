<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;

final class PasswordResetTest extends DatabaseTestCase
{
    public function testAnAdminIssuesALinkThatWorksOnce(): void
    {
        $organization = $this->kit->organization('reset-a');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $this->kit->member($organization, 'cashier@example.test', 'cashier');

        $link = $this->kit->resets->issueForStaff($admin, $organization, 'cashier@example.test');
        $this->assertStringStartsWith('http://localhost:8000/reset-a/reset-password?token=', $link['url']);
        $token = $this->kit->tokenFrom($link['url']);

        $result = $this->kit->resets->consume($token, 'my-new-password-1');
        $this->assertSame('user', $result['subject_type']);
        $this->kit->auth->authenticateStaff('cashier@example.test', 'my-new-password-1', '192.0.2.60');

        $this->assertHttpStatus(422, fn () => $this->kit->resets->consume($token, 'yet-another-password'));
    }

    public function testOnlyAHashOfTheTokenIsStored(): void
    {
        $organization = $this->kit->organization('reset-b');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $this->kit->member($organization, 'staff@example.test', 'staff');

        $token = $this->kit->tokenFrom($this->kit->resets->issueForStaff($admin, $organization, 'staff@example.test')['url']);

        $stored = (string) $this->core->query('SELECT token_hash FROM password_reset_tokens ORDER BY created_at DESC LIMIT 1')->fetchColumn();
        $this->assertSame(hash('sha256', $token), $stored);
        $this->assertNotSame($token, $stored);

        $audit = (string) $this->core->query("SELECT string_agg(detail::text, ' ') FROM audit_log")->fetchColumn();
        $this->assertStringNotContainsString($token, $audit, 'the token must never reach the audit log');
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $organization = $this->kit->organization('reset-c');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $this->kit->member($organization, 'staff@example.test', 'staff');

        $token = $this->kit->tokenFrom($this->kit->resets->issueForStaff($admin, $organization, 'staff@example.test')['url']);
        $this->core->exec("UPDATE password_reset_tokens SET expires_at = now() - interval '1 minute'");

        $this->assertHttpStatus(422, fn () => $this->kit->resets->consume($token, 'my-new-password-1'));
    }

    public function testANewLinkRetiresTheOldOne(): void
    {
        $organization = $this->kit->organization('reset-d');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $this->kit->member($organization, 'staff@example.test', 'staff');

        $first = $this->kit->tokenFrom($this->kit->resets->issueForStaff($admin, $organization, 'staff@example.test')['url']);
        $second = $this->kit->tokenFrom($this->kit->resets->issueForStaff($admin, $organization, 'staff@example.test')['url']);

        $this->assertHttpStatus(422, fn () => $this->kit->resets->consume($first, 'my-new-password-1'));
        $this->kit->resets->consume($second, 'my-new-password-1');
        $this->addToAssertionCount(1);
    }

    public function testAWeakPasswordDoesNotUseUpTheLink(): void
    {
        $organization = $this->kit->organization('reset-e');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $this->kit->member($organization, 'staff@example.test', 'staff');
        $token = $this->kit->tokenFrom($this->kit->resets->issueForStaff($admin, $organization, 'staff@example.test')['url']);

        $error = $this->assertHttpStatus(422, fn () => $this->kit->resets->consume($token, 'short'));
        $this->assertArrayHasKey('password', $error->fields());

        $this->kit->resets->consume($token, 'a-proper-password-1');
        $this->addToAssertionCount(1);
    }

    public function testAnInvitedAccountBecomesActiveWhenItSetsAPassword(): void
    {
        $organization = $this->kit->organization('reset-f');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $created = $this->kit->accounts->createStaffAccount($admin, $organization, 'invited@example.test', 'Invited Person', 'staff');
        $this->assertSame('pending', $this->kit->users->findById($created['user_id'])['status']);

        $token = $this->kit->tokenFrom($this->kit->resets->issueForStaff($admin, $organization, 'invited@example.test')['url']);
        $this->kit->resets->consume($token, 'my-first-password-1');

        $this->assertSame('active', $this->kit->users->findById($created['user_id'])['status']);
        $this->kit->auth->authenticateStaff('invited@example.test', 'my-first-password-1', '192.0.2.61');
    }

    public function testAnAdminCannotIssueLinksOutsideTheirTeam(): void
    {
        $mine = $this->kit->organization('reset-g');
        $theirs = $this->kit->organization('reset-h');
        $admin = $this->kit->member($mine, 'admin@example.test', 'admin');
        $this->kit->member($theirs, 'outsider@example.test', 'staff');

        // An unknown email and another organization's member give the same answer.
        $unknown = $this->assertHttpStatus(422, fn () => $this->kit->resets->issueForStaff($admin, $mine, 'nobody@example.test'));
        $outsider = $this->assertHttpStatus(422, fn () => $this->kit->resets->issueForStaff($admin, $mine, 'outsider@example.test'));
        $this->assertSame($unknown->fields(), $outsider->fields());
        $this->assertSame(0, (int) $this->core->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }

    public function testOnlyAnAdminMayIssueLinks(): void
    {
        $organization = $this->kit->organization('reset-i');
        $manager = $this->kit->member($organization, 'manager@example.test', 'manager');
        $this->kit->member($organization, 'staff@example.test', 'staff');

        $this->assertHttpStatus(403, fn () => $this->kit->resets->issueForStaff($manager, $organization, 'staff@example.test'));
        $this->assertHttpStatus(403, fn () => $this->kit->resets->issueForCustomer($manager, $organization, $this->kit->orgCustomers, 'x@example.test'));
    }

    public function testSuperAdminCanIssueLinksForAnyStaffMember(): void
    {
        $organization = $this->kit->organization('reset-j');
        $other = $this->kit->organization('reset-k');
        $superAdmin = $this->kit->staff('boss@example.test', orgRole: 'super_admin');
        $shared = $this->kit->member($organization, 'shared@example.test', 'manager');
        $this->kit->join($other, $shared, 'admin');

        $link = $this->kit->resets->issueForStaff($superAdmin, $organization, 'shared@example.test');

        $this->assertStringContainsString('/reset-j/reset-password?token=', $link['url']);
        $this->assertSame(1, $this->countAudit('password_reset.issued'));
    }

    public function testCustomerLinksAreForCustomersLinkedToTheOrganization(): void
    {
        $organization = $this->kit->organization('reset-l');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');

        // This customer registered at this organization's shop, so they are linked to it.
        $linked = $this->kit->auth->registerCustomer('Linked Customer', 'linked@example.test', 'a-long-password-1', null, $this->kit->orgCustomers);
        // This one registered elsewhere and never visited this shop.
        $this->kit->auth->registerCustomer('Elsewhere Customer', 'elsewhere@example.test', 'a-long-password-1');

        $link = $this->kit->resets->issueForCustomer($admin, $organization, $this->kit->orgCustomers, 'linked@example.test');
        $result = $this->kit->resets->consume($this->kit->tokenFrom($link['url']), 'a-fresh-password-1');
        $this->assertSame('customer', $result['subject_type']);
        $this->kit->auth->authenticateCustomer('linked@example.test', 'a-fresh-password-1', '192.0.2.62');

        $unlinked = $this->assertHttpStatus(422, fn () => $this->kit->resets->issueForCustomer($admin, $organization, $this->kit->orgCustomers, 'elsewhere@example.test'));
        $unknown = $this->assertHttpStatus(422, fn () => $this->kit->resets->issueForCustomer($admin, $organization, $this->kit->orgCustomers, 'nobody@example.test'));
        $this->assertSame($unlinked->fields(), $unknown->fields());

        // Super Admin is not limited to linked customers.
        $superAdmin = $this->kit->staff('boss@example.test', orgRole: 'super_admin');
        $this->kit->resets->issueForCustomer($superAdmin, $organization, $this->kit->orgCustomers, 'elsewhere@example.test');
        $this->assertNotNull($linked->id);
    }

    public function testTheNewPasswordMustFollowThePolicy(): void
    {
        $organization = $this->kit->organization('reset-m');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $this->kit->member($organization, 'staff@example.test', 'staff');
        $token = $this->kit->tokenFrom($this->kit->resets->issueForStaff($admin, $organization, 'staff@example.test')['url']);

        $this->assertHttpStatus(422, fn () => $this->kit->resets->consume($token, 'staff@example.test'));
        $this->assertHttpStatus(422, fn () => $this->kit->resets->consume('not-a-real-token-at-all-0000', TestKit::PASSWORD));
    }
}
