<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Auth\PasswordPolicy;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Config\Config;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\CustomerAccountRepository;
use Gfh\Core\Repository\MembershipRepository;
use Gfh\Core\Repository\PasswordResetRepository;
use Gfh\Core\Repository\PermissionRepository;
use Gfh\Core\Repository\SuperAdminUserRepository;
use Gfh\Core\Repository\UserRepository;
use Gfh\Core\Service\AccountService;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Service\PasswordResetService;
use Gfh\Core\Service\PermissionService;
use Gfh\Core\Service\SuperAdminUserQueryService;
use Gfh\Core\Service\SuperAdminUserService;
use PHPUnit\Framework\TestCase;

/**
 * Batch SA.2a unit tests (no database, no SQLite — stubs only):
 * service-level permission enforcement on SuperAdminUserQueryService and SuperAdminUserService
 * when controllers are bypassed, and safe presentation (whitelisted fields, Manila times, separate statuses).
 */
final class SuperAdminBatch2aUnitTest extends TestCase
{
    private function pdoStub(): \PDO
    {
        return (new \ReflectionClass(\PDO::class))->newInstanceWithoutConstructor();
    }

    private function services(): array
    {
        $pdo = $this->pdoStub();
        $memberships = new MembershipRepository($pdo);
        $permissions = new PermissionService($memberships, new PermissionRepository($pdo));
        $users = new UserRepository($pdo);
        $audit = new AuditService($pdo);
        $passwords = new PasswordPolicy();
        $accounts = new AccountService($pdo, $users, $memberships, $permissions, $audit, $passwords);
        $resets = new PasswordResetService(
            $pdo,
            new PasswordResetRepository($pdo),
            $users,
            new CustomerAccountRepository($pdo),
            $accounts,
            $permissions,
            $audit,
            $passwords,
            new Config([]),
        );
        $repo = new SuperAdminUserRepository($pdo);

        return [
            'query' => new SuperAdminUserQueryService($repo, $permissions),
            'write' => new SuperAdminUserService($repo, $accounts, $resets, $permissions),
        ];
    }

    public function testQueryServiceRefusesNonGroupInactiveOrPendingActorsEvenWhenControllersAreBypassed(): void
    {
        ['query' => $query] = $this->services();
        $uuid = '00000000-0000-4000-8000-000000000099';

        $blocked = [
            new StaffUser('00000000-0000-4000-8000-000000000010', 'Org Admin', 'org@example.test', null, 'active'),
            new StaffUser('00000000-0000-4000-8000-000000000011', 'Inactive SA', 'off@example.test', 'super_admin', 'inactive'),
            new StaffUser('00000000-0000-4000-8000-000000000012', 'Pending GV', 'pend@example.test', 'group_viewer', 'pending'),
        ];

        foreach ($blocked as $actor) {
            foreach ([
                fn () => $query->list($actor, []),
                fn () => $query->detail($actor, $uuid),
                fn () => $query->organizationOptions($actor),
            ] as $call) {
                try {
                    $call();
                    $this->fail('Expected 403 for ' . $actor->email);
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->status());
                }
            }
        }
    }

    public function testWriteServiceRefusesGroupViewerAndUnauthorizedActorsOnEveryWriteMethod(): void
    {
        ['write' => $write] = $this->services();
        $uid = '00000000-0000-4000-8000-000000000098';
        $oid = '00000000-0000-4000-8000-000000000099';

        $blocked = [
            new StaffUser('00000000-0000-4000-8000-000000000020', 'Group Viewer', 'gv@example.test', 'group_viewer', 'active'),
            new StaffUser('00000000-0000-4000-8000-000000000021', 'Org Admin', 'org@example.test', null, 'active'),
            new StaffUser('00000000-0000-4000-8000-000000000022', 'Inactive SA', 'off@example.test', 'super_admin', 'inactive'),
            new StaffUser('00000000-0000-4000-8000-000000000023', 'Pending SA', 'pend@example.test', 'super_admin', 'pending'),
        ];

        foreach ($blocked as $actor) {
            foreach ([
                fn () => $write->createStaffAccount($actor, $oid, 'new@example.test', 'New Staff', 'staff'),
                fn () => $write->createGroupAccount($actor, 'newg@example.test', 'New Group', 'group_viewer'),
                fn () => $write->addMembership($actor, $uid, $oid, 'staff'),
                fn () => $write->changeRole($actor, $uid, $oid, 'manager'),
                fn () => $write->deactivateMembership($actor, $uid, $oid),
                fn () => $write->reactivateMembership($actor, $uid, $oid),
                fn () => $write->issueResetLink($actor, $uid, $oid),
            ] as $call) {
                try {
                    $call();
                    $this->fail('Expected 403 for ' . $actor->email);
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->status());
                }
            }
        }
    }

    public function testPresentUserWhitelistsSafeFieldsFormatsManilaTimesAndKeepsStatusesSeparate(): void
    {
        $presented = SuperAdminUserQueryService::presentUser([
            'id' => '00000000-0000-4000-8000-000000000001',
            'full_name' => 'Ana Reyes',
            'email' => 'ana@example.test',
            'org_role' => null,
            'status' => 'active',
            'password_hash' => '$2y$10$SHOULDNEVERAPPEAR',
            'token_hash' => 'SHOULDNEVERAPPEAR2',
            'last_login_at' => '2026-11-26T16:30:00+00:00',
            'created_at' => '2026-11-20T00:00:00+00:00',
            'updated_at' => '2026-11-21T00:00:00+00:00',
            'memberships' => [
                [
                    'id' => '00000000-0000-4000-8000-000000000010',
                    'organization_id' => '00000000-0000-4000-8000-000000000020',
                    'organization_name' => 'North Hub',
                    'organization_slug' => 'north-hub',
                    'organization_status' => 'active',
                    'role' => 'admin',
                    'status' => 'inactive',
                    'created_at' => '2026-11-20T01:00:00+00:00',
                    'connection_encrypted' => 'postgresql://secret',
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('password_hash', $presented);
        $this->assertArrayNotHasKey('token_hash', $presented);
        $this->assertArrayNotHasKey('connection_encrypted', $presented['memberships'][0]);
        $this->assertSame('None', $presented['group_role_label']);
        $this->assertSame('Active', $presented['status_label']);
        $this->assertSame('ok', $presented['status_tone']);
        $this->assertSame('27 Nov 2026, 00:30 Asia/Manila', $presented['last_login_at_manila']);
        $this->assertSame('Inactive', $presented['memberships'][0]['status_label']);
        $this->assertSame('neutral', $presented['memberships'][0]['status_tone']);
        $this->assertSame('Admin', $presented['memberships'][0]['role_label']);

        $neverLoggedIn = SuperAdminUserQueryService::presentUser([
            'id' => '00000000-0000-4000-8000-000000000002',
            'full_name' => 'Pending User',
            'email' => 'pending@example.test',
            'org_role' => 'group_viewer',
            'status' => 'pending',
            'last_login_at' => null,
            'created_at' => null,
            'updated_at' => null,
            'memberships' => [],
        ]);
        $this->assertSame('Group Viewer', $neverLoggedIn['group_role_label']);
        $this->assertSame('Pending', $neverLoggedIn['status_label']);
        $this->assertSame('warn', $neverLoggedIn['status_tone']);
        $this->assertSame('Never', $neverLoggedIn['last_login_at_manila']);
    }

    public function testDetailRefusesMalformedUuidWith404(): void
    {
        ['query' => $query] = $this->services();
        $sa = new StaffUser('00000000-0000-4000-8000-000000000001', 'Super', 'sa@example.test', 'super_admin', 'active');

        try {
            $query->detail($sa, 'not-a-uuid');
            $this->fail('Expected 404 for malformed UUID');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->status());
        }
    }
}
