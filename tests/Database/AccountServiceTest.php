<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;

final class AccountServiceTest extends DatabaseTestCase
{
    public function testAnAdminCreatesStaffAndCashierAccounts(): void
    {
        $organization = $this->kit->organization('acct-a');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');

        $staff = $this->kit->accounts->createStaffAccount($admin, $organization, 'New.Staff@Example.test', 'Liza Ramos', 'staff', 'a-long-password-1');
        $cashier = $this->kit->accounts->createStaffAccount($admin, $organization, 'till@example.test', 'Ben Cruz', 'cashier');

        $this->assertTrue($staff['created_user']);
        $this->assertSame('active', $this->kit->users->findById($staff['user_id'])['status']);
        $this->assertSame('pending', $this->kit->users->findById($cashier['user_id'])['status'], 'no password means pending');
        $this->assertSame('staff', $this->kit->memberships->find($staff['user_id'], $organization->id)['role']);
        $this->assertSame(2, $this->countAudit('membership.added'));
        $this->assertSame(2, $this->countAudit('user.created'));
    }

    public function testAnAdminCannotCreateAdminOrManagerAccounts(): void
    {
        $organization = $this->kit->organization('acct-b');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');

        foreach (['admin', 'manager'] as $role) {
            $this->assertHttpStatus(403, fn () => $this->kit->accounts->createStaffAccount($admin, $organization, $role . '@example.test', 'Some Person', $role, 'a-long-password-1'));
        }
        $this->assertNull($this->kit->users->findByEmail('manager@example.test'));
    }

    public function testNonAdminsCannotManageTheTeam(): void
    {
        $organization = $this->kit->organization('acct-c');
        $manager = $this->kit->member($organization, 'manager@example.test', 'manager');
        $cashier = $this->kit->member($organization, 'cashier@example.test', 'cashier');

        $this->assertHttpStatus(403, fn () => $this->kit->accounts->createStaffAccount($manager, $organization, 'x@example.test', 'Some Person', 'staff'));
        $this->assertHttpStatus(403, fn () => $this->kit->accounts->deactivate($manager, $organization, (string) $cashier->id));
    }

    public function testAnAdminCannotTouchAnotherOrganizationsMembers(): void
    {
        $mine = $this->kit->organization('acct-d');
        $theirs = $this->kit->organization('acct-e');
        $admin = $this->kit->member($mine, 'admin@example.test', 'admin');
        $outsider = $this->kit->member($theirs, 'outsider@example.test', 'staff');
        $id = (string) $outsider->id;

        $this->assertHttpStatus(403, fn () => $this->kit->accounts->updateStaffProfile($admin, $mine, $id, 'Changed Name', 'changed@example.test'));
        $this->assertHttpStatus(403, fn () => $this->kit->accounts->changeRole($admin, $mine, $id, 'cashier'));
        $this->assertHttpStatus(403, fn () => $this->kit->accounts->deactivate($admin, $mine, $id));
        $this->assertHttpStatus(403, fn () => $this->kit->accounts->reactivate($admin, $mine, $id));
        // Acting on the other organization's team directly is refused as well.
        $this->assertHttpStatus(403, fn () => $this->kit->accounts->deactivate($admin, $theirs, $id));
        $this->assertHttpStatus(403, fn () => $this->kit->accounts->createStaffAccount($admin, $theirs, 'new@example.test', 'Some Person', 'staff'));

        $row = $this->kit->userRow($outsider);
        $this->assertSame('Test Person', $row['full_name']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('active', $this->kit->memberships->find($id, $theirs->id)['status']);
    }

    public function testAnAdminCannotTouchAdminOrManagerAccountsInTheirOwnOrganization(): void
    {
        $organization = $this->kit->organization('acct-f');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $manager = $this->kit->member($organization, 'manager@example.test', 'manager');
        $otherAdmin = $this->kit->member($organization, 'admin2@example.test', 'admin');

        foreach ([$manager, $otherAdmin] as $target) {
            $id = (string) $target->id;
            $this->assertHttpStatus(403, fn () => $this->kit->accounts->updateStaffProfile($admin, $organization, $id, 'Changed Name', 'changed' . $id . '@example.test'));
            $this->assertHttpStatus(403, fn () => $this->kit->accounts->changeRole($admin, $organization, $id, 'staff'));
            $this->assertHttpStatus(403, fn () => $this->kit->accounts->deactivate($admin, $organization, $id));
            $this->assertHttpStatus(403, fn () => $this->kit->resets->issueForStaff($admin, $organization, $target->email));
        }
    }

    public function testAnAdminCannotPromoteSomeoneToAdminOrManager(): void
    {
        $organization = $this->kit->organization('acct-g');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $staff = $this->kit->member($organization, 'staff@example.test', 'staff');

        $this->assertHttpStatus(403, fn () => $this->kit->accounts->changeRole($admin, $organization, (string) $staff->id, 'manager'));
        $this->assertHttpStatus(403, fn () => $this->kit->accounts->changeRole($admin, $organization, (string) $staff->id, 'admin'));

        $this->kit->accounts->changeRole($admin, $organization, (string) $staff->id, 'cashier');
        $this->assertSame('cashier', $this->kit->memberships->find((string) $staff->id, $organization->id)['role']);
    }

    public function testAnAdminCannotChangeTheProfileOrPasswordOfSomeoneWhoBelongsToAnotherOrganization(): void
    {
        $mine = $this->kit->organization('acct-h');
        $theirs = $this->kit->organization('acct-i');
        $admin = $this->kit->member($mine, 'admin@example.test', 'admin');
        $shared = $this->kit->member($mine, 'shared@example.test', 'staff');
        $this->kit->join($theirs, $shared, 'staff');
        $id = (string) $shared->id;
        $before = $this->kit->userRow($shared);

        $this->assertHttpStatus(403, fn () => $this->kit->accounts->updateStaffProfile($admin, $mine, $id, 'Changed Name', 'changed@example.test'));
        $this->assertHttpStatus(403, fn () => $this->kit->resets->issueForStaff($admin, $mine, 'shared@example.test'));

        $after = $this->kit->userRow($shared);
        $this->assertSame($before['full_name'], $after['full_name']);
        $this->assertSame($before['email'], $after['email']);
        $this->assertSame($before['password_hash'], $after['password_hash']);

        // Their membership in the admin's own organization can still be managed.
        $this->kit->accounts->changeRole($admin, $mine, $id, 'cashier');
        $this->kit->accounts->deactivate($admin, $mine, $id);
        $this->assertSame('inactive', $this->kit->memberships->find($id, $mine->id)['status']);
        $this->assertSame('active', $this->kit->memberships->find($id, $theirs->id)['status']);
        $this->assertSame('active', $this->kit->userRow($shared)['status'], 'the account stays active: they still belong to another organization');
    }

    public function testAddingAnExistingEmailAddsOnlyAMembership(): void
    {
        $mine = $this->kit->organization('acct-j');
        $theirs = $this->kit->organization('acct-k');
        $admin = $this->kit->member($mine, 'admin@example.test', 'admin');
        $existing = $this->kit->staff('existing@example.test', name: 'Original Name');
        $this->kit->join($theirs, $existing, 'staff');
        $before = $this->kit->userRow($existing);

        $result = $this->kit->accounts->createStaffAccount($admin, $mine, 'EXISTING@example.test', 'A Different Name', 'cashier', 'a-brand-new-password');

        $this->assertFalse($result['created_user']);
        $this->assertSame((string) $existing->id, $result['user_id']);
        $after = $this->kit->userRow($existing);
        $this->assertSame($before['full_name'], $after['full_name'], 'the name must not change');
        $this->assertSame($before['password_hash'], $after['password_hash'], 'the password must not change');
        $this->assertSame($before['email'], $after['email']);
        $this->assertSame('cashier', $this->kit->memberships->find((string) $existing->id, $mine->id)['role']);
        $this->assertSame('staff', $this->kit->memberships->find((string) $existing->id, $theirs->id)['role'], 'the other membership is untouched');
    }

    public function testAnAdminCannotAddAGroupLevelAccountOrCreateOne(): void
    {
        $organization = $this->kit->organization('acct-l');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $this->kit->staff('boss@example.test', orgRole: 'super_admin');

        $this->assertHttpStatus(403, fn () => $this->kit->accounts->createStaffAccount($admin, $organization, 'boss@example.test', 'Boss', 'staff'));
        $this->assertHttpStatus(403, fn () => $this->kit->accounts->createGroupAccount($admin, 'new.boss@example.test', 'New Boss', 'super_admin'));
    }

    public function testSuperAdminManagesEveryAccount(): void
    {
        $organization = $this->kit->organization('acct-m');
        $superAdmin = $this->kit->staff('boss@example.test', orgRole: 'super_admin');

        $manager = $this->kit->accounts->createStaffAccount($superAdmin, $organization, 'manager@example.test', 'Maria Lopez', 'manager', 'a-long-password-1');
        $this->kit->accounts->updateStaffProfile($superAdmin, $organization, $manager['user_id'], 'Maria Lopez-Tan', 'manager@example.test');
        $this->kit->accounts->changeRole($superAdmin, $organization, $manager['user_id'], 'admin');
        $group = $this->kit->accounts->createGroupAccount($superAdmin, 'viewer@example.test', 'Group Viewer', 'group_viewer');

        $this->assertSame('admin', $this->kit->memberships->find($manager['user_id'], $organization->id)['role']);
        $this->assertSame('Maria Lopez-Tan', $this->kit->users->findById($manager['user_id'])['full_name']);
        $this->assertSame('group_viewer', $this->kit->users->findById($group['user_id'])['org_role']);
    }

    public function testAnAdminCannotDeactivateOrDemoteThemselves(): void
    {
        $organization = $this->kit->organization('acct-n');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');

        $this->assertHttpStatus(403, fn () => $this->kit->accounts->deactivate($admin, $organization, (string) $admin->id));
        $this->assertHttpStatus(403, fn () => $this->kit->accounts->changeRole($admin, $organization, (string) $admin->id, 'staff'));
    }

    public function testDeactivatingTheOnlyMembershipSwitchesTheAccountOffAndBlocksSignIn(): void
    {
        $organization = $this->kit->organization('acct-o');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $cashier = $this->kit->member($organization, 'cashier@example.test', 'cashier');

        $this->kit->accounts->deactivate($admin, $organization, (string) $cashier->id);

        $this->assertSame('inactive', $this->kit->userRow($cashier)['status']);
        $this->assertHttpStatus(401, fn () => $this->kit->auth->authenticateStaff('cashier@example.test', TestKit::PASSWORD, '192.0.2.50'));
        $this->assertSame(1, $this->countAudit('membership.deactivated'));
        $this->assertSame(1, $this->countAudit('user.deactivated'));

        $this->kit->accounts->reactivate($admin, $organization, (string) $cashier->id);
        $this->assertSame('active', $this->kit->userRow($cashier)['status']);
        $this->kit->auth->authenticateStaff('cashier@example.test', TestKit::PASSWORD, '192.0.2.51');
    }

    public function testInvalidFieldsGetFieldLevelErrors(): void
    {
        $organization = $this->kit->organization('acct-p');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');

        $error = $this->assertHttpStatus(422, fn () => $this->kit->accounts->createStaffAccount($admin, $organization, 'not-an-email', 'X', 'janitor'));
        $this->assertArrayHasKey('email', $error->fields());
        $this->assertArrayHasKey('role', $error->fields());

        $name = $this->assertHttpStatus(422, fn () => $this->kit->accounts->createStaffAccount($admin, $organization, 'fine@example.test', 'X', 'staff'));
        $this->assertArrayHasKey('full_name', $name->fields());
    }
}
