<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Repository\OrganizationRepository;
use Gfh\Core\Tenancy\TenantResolver;
use Gfh\Core\Database\Database;
use Gfh\Tests\Support\DatabaseTestCase;

final class PermissionTest extends DatabaseTestCase
{
    public function testCashierCannotEditProductsOrPrices(): void
    {
        $organization = $this->kit->organization('perm-a');
        $cashier = $this->kit->member($organization, 'cashier@example.test', 'cashier');

        $this->assertFalse($this->kit->permissions->can($cashier, $organization, 'products', 'edit'));
        $this->assertFalse($this->kit->permissions->can($cashier, $organization, 'products', 'delete'));
        $this->assertFalse($this->kit->permissions->can($cashier, $organization, 'products', 'view'));
        $this->assertTrue($this->kit->permissions->can($cashier, $organization, 'pos', 'edit'));
        $this->assertHttpStatus(403, fn () => $this->kit->permissions->assert($cashier, $organization, 'products', 'edit'));
    }

    public function testDefaultsFollowTheRole(): void
    {
        $organization = $this->kit->organization('perm-b');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $manager = $this->kit->member($organization, 'manager@example.test', 'manager');
        $staff = $this->kit->member($organization, 'staff@example.test', 'staff');

        $this->assertTrue($this->kit->permissions->can($admin, $organization, 'products', 'delete'));
        $this->assertTrue($this->kit->permissions->can($manager, $organization, 'products', 'edit'));
        $this->assertFalse($this->kit->permissions->can($manager, $organization, 'products', 'delete'));
        $this->assertFalse($this->kit->permissions->can($staff, $organization, 'products', 'edit'));
        $this->assertTrue($this->kit->permissions->can($staff, $organization, 'inventory', 'edit'));
        $this->assertFalse($this->kit->permissions->can($staff, $organization, 'reports_admin', 'view'));
    }

    public function testAnOrganizationOverrideReplacesTheDefault(): void
    {
        $organization = $this->kit->organization('perm-c');
        $staff = $this->kit->member($organization, 'staff@example.test', 'staff');

        $this->core->prepare(
            'INSERT INTO organization_role_permissions (organization_id, role, module, can_view, can_edit, can_delete) '
            . "VALUES (:org, 'staff', 'products', true, true, false)",
        )->execute(['org' => $organization->id]);

        // A fresh service, because the first one caches what it has read.
        $fresh = new \Gfh\Core\Service\PermissionService(
            $this->kit->memberships,
            new \Gfh\Core\Repository\PermissionRepository($this->core),
        );

        $this->assertTrue($fresh->can($staff, $organization, 'products', 'edit'));
    }

    public function testNoMembershipMeansRefusal(): void
    {
        $organization = $this->kit->organization('perm-d');
        $other = $this->kit->organization('perm-e');
        $outsider = $this->kit->member($other, 'outsider@example.test', 'admin');

        $this->assertFalse($this->kit->permissions->can($outsider, $organization, 'dashboard', 'view'));
        $this->assertFalse($this->kit->permissions->can(null, $organization, 'dashboard', 'view'));

        $resolver = new TenantResolver($this->kit->organizations, $this->kit->permissions, new Database($this->kit->config));
        $tenant = new \Gfh\Core\Tenancy\Tenant($organization, new Database($this->kit->config));

        $this->assertHttpStatus(403, fn () => $resolver->authorizeStaff($tenant, $outsider));
    }

    public function testAMemberGetsTheirRoleOnTheTenant(): void
    {
        $organization = $this->kit->organization('perm-f');
        $manager = $this->kit->member($organization, 'manager@example.test', 'manager');

        $resolver = new TenantResolver($this->kit->organizations, $this->kit->permissions, new Database($this->kit->config));
        $tenant = $resolver->authorizeStaff(new \Gfh\Core\Tenancy\Tenant($organization, new Database($this->kit->config)), $manager);

        $this->assertSame('manager', $tenant->role());
    }

    public function testInactiveMembershipOrAccountIsRefused(): void
    {
        $organization = $this->kit->organization('perm-g');
        $suspended = $this->kit->member($organization, 'off@example.test', 'admin', 'inactive');
        $this->assertFalse($this->kit->permissions->can($suspended, $organization, 'dashboard', 'view'));

        $inactiveUser = $this->kit->staff('gone@example.test', status: 'inactive');
        $this->kit->join($organization, $inactiveUser, 'admin');
        $this->assertFalse($this->kit->permissions->can($inactiveUser, $organization, 'dashboard', 'view'));
    }

    public function testSuperAdminHasNoWriteAccessToOperations(): void
    {
        $organization = $this->kit->organization('perm-h');
        $superAdmin = $this->kit->staff('boss@example.test', orgRole: 'super_admin');

        // Without a membership: not even a view.
        $this->assertFalse($this->kit->permissions->can($superAdmin, $organization, 'products', 'view'));

        // Even with an Admin membership, a Super Admin never writes to operations.
        $this->kit->join($organization, $superAdmin, 'admin');
        foreach (['products', 'inventory', 'sales', 'procurement', 'reports_admin', 'pos', 'dashboard'] as $module) {
            $this->assertFalse($this->kit->permissions->can($superAdmin, $organization, $module, 'edit'), $module . ' edit');
            $this->assertFalse($this->kit->permissions->can($superAdmin, $organization, $module, 'delete'), $module . ' delete');
        }
        $this->assertTrue($this->kit->permissions->can($superAdmin, $organization, 'products', 'view'));
    }

    public function testGroupViewerIsReadOnlyEverywhere(): void
    {
        $organization = $this->kit->organization('perm-i');
        $viewer = $this->kit->staff('viewer@example.test', orgRole: 'group_viewer');
        $this->kit->join($organization, $viewer, 'manager');

        $this->assertFalse($this->kit->permissions->can($viewer, $organization, 'products', 'edit'));
        $this->assertTrue($this->kit->permissions->can($viewer, $organization, 'products', 'view'));
        $this->assertTrue($this->kit->permissions->can($viewer, null, 'overview', 'view'));
        $this->assertFalse($this->kit->permissions->can($viewer, null, 'users_access', 'edit'));
    }

    public function testGroupPagesFollowTheGroupRole(): void
    {
        $superAdmin = $this->kit->staff('boss@example.test', orgRole: 'super_admin');
        $viewer = $this->kit->staff('viewer@example.test', orgRole: 'group_viewer');
        $plain = $this->kit->staff('plain@example.test');

        $this->assertTrue($this->kit->permissions->can($superAdmin, null, 'users_access', 'edit'));
        $this->assertFalse($this->kit->permissions->can($superAdmin, null, 'audit_log', 'edit'));
        $this->assertFalse($this->kit->permissions->can($viewer, null, 'users_access', 'edit'));
        $this->assertFalse($this->kit->permissions->can($plain, null, 'overview', 'view'));
        $this->assertFalse($this->kit->permissions->can($superAdmin, null, 'products', 'view'), 'an operations module is not a group page');
        $this->assertFalse($this->kit->permissions->can($superAdmin, null, 'users_access', 'launch'), 'an unknown action is refused');
    }
}
