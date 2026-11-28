<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;

/**
 * Batch SA.2a end-to-end tests through the real App + Router + core/routes.php + middleware stack
 * and real PostgreSQL core database. Never opens an organization database.
 *
 * Covers: role matrix across list, detail, create forms and all POST actions; CSRF on every POST;
 * query service filters, sorting, pagination, grouped memberships and zero-membership accounts;
 * all Super Admin account/membership actions and audit entries; the last-active-Admin rule;
 * one-time reset link rules and zero leakage; HTML escaping; and zero organization DB connections.
 */
final class SuperAdminBatch2aTest extends DatabaseTestCase
{
    private const IP = '198.51.100.88';

    private App $app;
    private bool $booted = false;
    private int $orgDbFactoryCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 2));
        $this->booted = true;
        $this->orgDbFactoryCalls = 0;
    }

    protected function tearDown(): void
    {
        if ($this->booted) {
            restore_error_handler();
            restore_exception_handler();
        }
        parent::tearDown();
    }

    /** @return array{services: Services, staff: Session, customer: Session} */
    private function browser(?string $staffId = null, ?string $customerId = null): array
    {
        $staffSession = Session::inMemory('staff', $this->app->config(), $staffId === null ? [] : ['user_id' => $staffId]);
        $customerSession = Session::inMemory('customer', $this->app->config(), $customerId === null ? [] : ['user_id' => $customerId]);

        $factory = function (): \PDO {
            $this->orgDbFactoryCalls++;
            throw new \RuntimeException('Super Admin SA.2a must never open an organization database.');
        };

        $services = new Services(
            $this->app,
            false,
            $this->core,
            null,
            testSessions: ['staff' => $staffSession, 'customer' => $customerSession],
            orgConnectionFactory: $factory,
        );

        return ['services' => $services, 'staff' => $staffSession, 'customer' => $customerSession];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     */
    private function call(
        array $browser,
        string $method,
        string $path,
        array $post = [],
        array $query = [],
        bool|string $csrf = true,
    ): Response {
        if ($method === 'POST' && $csrf !== false) {
            $post['_csrf'] = $csrf === true
                ? $browser['services']->csrf($browser['staff'])->token()
                : $csrf;
        }

        return $this->app->handle(
            new Request($method, $path, query: $query, post: $post, server: ['REMOTE_ADDR' => self::IP]),
            $browser['services'],
        );
    }

    // ------------------------------------------------------------------ 1. Role matrix & Group Viewer read-only

    public function testRoleMatrixAcrossEveryUsersPageAndActionRoute(): void
    {
        $org = $this->kit->organization('sa2a-matrix-' . bin2hex(random_bytes(3)));
        $sa = $this->kit->staff('sa-m@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Super Boss');
        $gv = $this->kit->staff('gv-m@example.test', TestKit::PASSWORD, 'group_viewer', 'active', 'Group Watcher');
        $target = $this->kit->member($org, 'target-m@example.test', 'staff');
        $targetId = (string) $target->id;
        $customer = $this->kit->auth->registerCustomer('Shopper', 'cust-sa2a@example.test', TestKit::PASSWORD);

        $readPaths = [
            '/superadmin/users',
            '/superadmin/users/' . $targetId,
        ];
        $editOnlyGetPaths = [
            '/superadmin/users/new-staff',
            '/superadmin/users/new-group',
        ];
        $postRoutes = [
            ['/superadmin/users/staff', ['organization_id' => $org->id, 'full_name' => 'New Person', 'email' => 'newp@example.test', 'role' => 'staff']],
            ['/superadmin/users/group', ['full_name' => 'New Group', 'email' => 'newg@example.test', 'org_role' => 'group_viewer']],
            ['/superadmin/users/' . $targetId . '/memberships', ['organization_id' => $org->id, 'role' => 'cashier']],
            ['/superadmin/users/' . $targetId . '/memberships/' . $org->id . '/role', ['role' => 'cashier']],
            ['/superadmin/users/' . $targetId . '/memberships/' . $org->id . '/deactivate', []],
            ['/superadmin/users/' . $targetId . '/memberships/' . $org->id . '/reactivate', []],
            ['/superadmin/users/' . $targetId . '/reset-link', ['organization_id' => $org->id]],
        ];

        // Super Admin can open all GET pages (list, detail, create forms) and sees action controls.
        $saBrowser = $this->browser($sa->id);
        foreach (array_merge($readPaths, $editOnlyGetPaths) as $path) {
            $resp = $this->call($saBrowser, 'GET', $path);
            $this->assertSame(200, $resp->status(), "Super Admin GET $path");
            $this->assertSame('no-store', $resp->header('Cache-Control'));
        }
        $saListBody = $this->call($saBrowser, 'GET', '/superadmin/users')->body();
        $this->assertStringContainsString('/superadmin/users/new-staff', $saListBody);
        $this->assertStringContainsString('/superadmin/users/new-group', $saListBody);
        $saDetailBody = $this->call($saBrowser, 'GET', '/superadmin/users/' . $targetId)->body();
        $this->assertStringContainsString('Add organization membership', $saDetailBody);
        $this->assertStringContainsString('Generate password reset link', $saDetailBody);

        // Group Viewer can open list & detail (read-only: NO action forms) and gets 403 on create pages and every POST.
        $gvBrowser = $this->browser($gv->id);
        $gvList = $this->call($gvBrowser, 'GET', '/superadmin/users');
        $this->assertSame(200, $gvList->status());
        $this->assertStringContainsString('View only', $gvList->body());
        $this->assertStringNotContainsString('/superadmin/users/new-staff', $gvList->body());
        $this->assertStringNotContainsString('/superadmin/users/new-group', $gvList->body());

        $gvDetail = $this->call($gvBrowser, 'GET', '/superadmin/users/' . $targetId);
        $this->assertSame(200, $gvDetail->status());
        $this->assertStringContainsString('View only', $gvDetail->body());
        $this->assertStringContainsString('target-m@example.test', $gvDetail->body());
        $this->assertStringNotContainsString('Add organization membership', $gvDetail->body());
        $this->assertStringNotContainsString('Save role', $gvDetail->body());
        $this->assertStringNotContainsString('Deactivate', $gvDetail->body());
        $this->assertStringNotContainsString('Generate password reset link', $gvDetail->body());
        $this->assertStringNotContainsString('/memberships', $gvDetail->body());

        $auditBefore = (int) $this->core->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

        foreach ($editOnlyGetPaths as $path) {
            $this->assertSame(403, $this->call($gvBrowser, 'GET', $path)->status(), "Group Viewer GET $path must be 403");
        }
        foreach ($postRoutes as [$path, $payload]) {
            $this->assertSame(403, $this->call($gvBrowser, 'POST', $path, $payload)->status(), "Group Viewer POST $path must be 403");
        }

        // Organization roles (admin, manager, staff, cashier) get 403 on every GET and POST.
        foreach (['admin', 'manager', 'staff', 'cashier'] as $role) {
            $member = $this->kit->member($org, $role . '-refused@example.test', $role);
            $desk = $this->browser($member->id);
            foreach (array_merge($readPaths, $editOnlyGetPaths) as $path) {
                $this->assertSame(403, $this->call($desk, 'GET', $path)->status(), "$role GET $path");
            }
            foreach ($postRoutes as [$path, $payload]) {
                $this->assertSame(403, $this->call($desk, 'POST', $path, $payload)->status(), "$role POST $path");
            }
        }

        // Guest and signed-in customer are redirected to sign-in on every GET and POST (with valid CSRF).
        foreach (['guest' => $this->browser(), 'customer' => $this->browser(null, $customer->id)] as $label => $desk) {
            foreach (array_merge($readPaths, $editOnlyGetPaths) as $path) {
                $resp = $this->call($desk, 'GET', $path);
                $this->assertSame(302, $resp->status(), "$label GET $path");
                $this->assertStringStartsWith('/superadmin/sign-in', (string) $resp->header('Location'));
            }
            foreach ($postRoutes as [$path, $payload]) {
                $resp = $this->call($desk, 'POST', $path, $payload);
                $this->assertSame(302, $resp->status(), "$label POST $path");
                $this->assertStringStartsWith('/superadmin/sign-in', (string) $resp->header('Location'));
            }
        }

        // Nothing was audited or mutated by any refused request.
        $this->assertSame($auditBefore, (int) $this->core->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        $this->assertSame('staff', $this->kit->memberships->find($targetId, $org->id)['role']);
        $this->assertSame(0, $this->orgDbFactoryCalls);
    }

    // ------------------------------------------------------------------ 2. CSRF on every POST route

    public function testEveryUsersPostRefusesMissingOrWrongCsrfTokenAndChangesNothing(): void
    {
        $org = $this->kit->organization('sa2a-csrf-' . bin2hex(random_bytes(3)));
        $sa = $this->kit->staff('sa-csrf@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Super CSRF');
        $target = $this->kit->member($org, 'target-csrf@example.test', 'staff');
        $targetId = (string) $target->id;

        $browser = $this->browser($sa->id);
        $usersBefore = (int) $this->core->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $auditBefore = (int) $this->core->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

        $posts = [
            ['/superadmin/users/staff', ['organization_id' => $org->id, 'full_name' => 'No Csrf', 'email' => 'nocsrf1@example.test', 'role' => 'staff']],
            ['/superadmin/users/group', ['full_name' => 'No Csrf Group', 'email' => 'nocsrf2@example.test', 'org_role' => 'group_viewer']],
            ['/superadmin/users/' . $targetId . '/memberships', ['organization_id' => $org->id, 'role' => 'cashier']],
            ['/superadmin/users/' . $targetId . '/memberships/' . $org->id . '/role', ['role' => 'cashier']],
            ['/superadmin/users/' . $targetId . '/memberships/' . $org->id . '/deactivate', []],
            ['/superadmin/users/' . $targetId . '/memberships/' . $org->id . '/reactivate', []],
            ['/superadmin/users/' . $targetId . '/reset-link', ['organization_id' => $org->id]],
        ];

        foreach ($posts as [$path, $payload]) {
            foreach ([false, '', 'wrong-token-value'] as $badCsrf) {
                $resp = $this->call($browser, 'POST', $path, $payload, csrf: $badCsrf);
                $this->assertSame(403, $resp->status(), "POST $path with bad CSRF must be 403");
            }
        }

        $this->assertSame($usersBefore, (int) $this->core->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame($auditBefore, (int) $this->core->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        $this->assertSame('staff', $this->kit->memberships->find($targetId, $org->id)['role']);
        $this->assertSame('active', $this->kit->memberships->find($targetId, $org->id)['status']);
        $this->assertSame(0, $this->orgDbFactoryCalls);
    }

    // ------------------------------------------------------------------ 3. Query service filters, sorting, pagination, grouping

    public function testQueryServiceSupportsAllFiltersSortingPaginationGroupingAndExcludesHashes(): void
    {
        $orgA = $this->kit->organization('alpha-' . bin2hex(random_bytes(2)));
        $orgB = $this->kit->organization('beta-' . bin2hex(random_bytes(2)));

        $sa = $this->kit->staff('sa-q@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Aaron Super');
        $gv = $this->kit->staff('gv-q@example.test', TestKit::PASSWORD, 'group_viewer', 'active', 'Bella Viewer');

        // Multi-org staff: Admin in Org A, Staff in Org B.
        $multi = $this->kit->staff('multi@example.test', TestKit::PASSWORD, null, 'active', 'Carlos Multi');
        $this->kit->join($orgA, $multi, 'admin');
        $this->kit->join($orgB, $multi, 'staff');

        // Pending cashier in Org B.
        $pending = $this->kit->staff('pending@example.test', null, null, 'pending', 'Diana Pending');
        $this->kit->join($orgB, $pending, 'cashier');

        // Inactive manager in Org A (inactive account + inactive membership).
        $inactive = $this->kit->staff('inactive@example.test', TestKit::PASSWORD, null, 'inactive', 'Eduardo Inactive');
        $this->kit->memberships->create((string) $inactive->id, $orgA->id, 'manager', 'inactive');

        // Set explicit last_login_at values for sorting checks.
        $setLogin = $this->core->prepare('UPDATE users SET last_login_at = CAST(:ts AS timestamptz) WHERE id = :id');
        $setLogin->execute(['ts' => '2026-11-26T16:30:00+00:00', 'id' => $sa->id]);      // newest (27 Nov 2026, 00:30 Asia/Manila)
        $setLogin->execute(['ts' => '2026-11-25T08:00:00+00:00', 'id' => $multi->id]);   // middle
        $setLogin->execute(['ts' => '2026-11-20T01:00:00+00:00', 'id' => $gv->id]);      // oldest

        // Add 25 more active staff in Org A to reach 30 total accounts for pagination test.
        for ($i = 1; $i <= 25; $i++) {
            $u = $this->kit->staff(sprintf('zbulk%02d@example.test', $i), TestKit::PASSWORD, null, 'active', sprintf('Zeta Bulk %02d', $i));
            $this->kit->join($orgA, $u, 'staff');
        }

        $browser = $this->browser($sa->id);
        $svc = $browser['services']->superAdminUserQueries();

        // Pagination: 30 total -> 25 on page 1, 5 on page 2, sorted by name ascending by default.
        $page1 = $svc->list($sa, []);
        $this->assertSame(30, $page1['pagination']['total']);
        $this->assertSame(25, count($page1['items']));
        $this->assertSame(2, $page1['pagination']['pages']);
        $this->assertSame(1, $page1['pagination']['from']);
        $this->assertSame(25, $page1['pagination']['to']);
        $this->assertSame('Aaron Super', $page1['items'][0]['full_name']);
        $this->assertSame('27 Nov 2026, 00:30 Asia/Manila', $page1['items'][0]['last_login_at_manila']);

        // No password_hash or token in any returned row.
        foreach ($page1['items'] as $row) {
            $this->assertArrayNotHasKey('password_hash', $row);
            $this->assertArrayNotHasKey('token_hash', $row);
        }

        // Memberships grouped correctly for Carlos Multi (2 orgs) and Aaron Super (0 orgs).
        $carlosRow = array_values(array_filter($page1['items'], static fn (array $r): bool => $r['email'] === 'multi@example.test'))[0];
        $this->assertCount(2, $carlosRow['memberships']);
        $this->assertSame(['admin', 'staff'], array_column($carlosRow['memberships'], 'role'));
        $this->assertSame([], $page1['items'][0]['memberships']);

        $page2 = $svc->list($sa, ['page' => '2']);
        $this->assertSame(5, count($page2['items']));
        $this->assertSame(26, $page2['pagination']['from']);
        $this->assertSame(30, $page2['pagination']['to']);

        // Sort by name descending.
        $nameDesc = $svc->list($sa, ['sort' => '-name']);
        $this->assertSame('Zeta Bulk 25', $nameDesc['items'][0]['full_name']);

        // Sort by last_login descending (Aaron newest, then Carlos, then Bella) and ascending (Bella oldest).
        $loginDesc = $svc->list($sa, ['sort' => '-last_login']);
        $this->assertSame('Aaron Super', $loginDesc['items'][0]['full_name']);
        $this->assertSame('Carlos Multi', $loginDesc['items'][1]['full_name']);
        $this->assertSame('Bella Viewer', $loginDesc['items'][2]['full_name']);

        $loginAsc = $svc->list($sa, ['sort' => 'last_login']);
        $this->assertSame('Bella Viewer', $loginAsc['items'][0]['full_name']);
        $this->assertSame('Carlos Multi', $loginAsc['items'][1]['full_name']);
        $this->assertSame('Aaron Super', $loginAsc['items'][2]['full_name']);

        // Filter: search (name and email), plus literal % safety.
        $byName = $svc->list($sa, ['q' => 'Carlos']);
        $this->assertSame(1, $byName['pagination']['total']);
        $byEmail = $svc->list($sa, ['q' => 'pending@example.test']);
        $this->assertSame(1, $byEmail['pagination']['total']);
        $byPercent = $svc->list($sa, ['q' => '%']);
        $this->assertSame(0, $byPercent['pagination']['total']);

        // Filter: account_type.
        $this->assertSame(1, $svc->list($sa, ['account_type' => 'super_admin'])['pagination']['total']);
        $this->assertSame(1, $svc->list($sa, ['account_type' => 'group_viewer'])['pagination']['total']);
        $this->assertSame(28, $svc->list($sa, ['account_type' => 'org_staff'])['pagination']['total']);

        // Filter: organization (specific org vs none).
        $noneOrg = $svc->list($sa, ['organization' => 'none']);
        $this->assertSame(2, $noneOrg['pagination']['total']);
        $this->assertSame(['Aaron Super', 'Bella Viewer'], array_column($noneOrg['items'], 'full_name'));

        $orgBOnly = $svc->list($sa, ['organization' => $orgB->id]);
        $this->assertSame(2, $orgBOnly['pagination']['total']);

        // Filter: role, and combined organization + role (Carlos is Admin in A, Staff in B, NOT Admin in B!).
        $this->assertSame(1, $svc->list($sa, ['role' => 'admin'])['pagination']['total']);
        $this->assertSame(1, $svc->list($sa, ['role' => 'manager'])['pagination']['total']);
        $this->assertSame(1, $svc->list($sa, ['role' => 'cashier'])['pagination']['total']);
        $this->assertSame(1, $svc->list($sa, ['organization' => $orgA->id, 'role' => 'admin'])['pagination']['total']);
        $this->assertSame(0, $svc->list($sa, ['organization' => $orgB->id, 'role' => 'admin'])['pagination']['total']);
        $this->assertSame(1, $svc->list($sa, ['organization' => $orgB->id, 'role' => 'staff'])['pagination']['total']);
        $this->assertSame(0, $svc->list($sa, ['organization' => 'none', 'role' => 'staff'])['pagination']['total']);

        // Filter: account status.
        $this->assertSame(1, $svc->list($sa, ['status' => 'pending'])['pagination']['total']);
        $this->assertSame(1, $svc->list($sa, ['status' => 'inactive'])['pagination']['total']);
        $this->assertSame(28, $svc->list($sa, ['status' => 'active'])['pagination']['total']);

        // HTML list renders pagination summary, aria-sort, and empty state "No accounts match".
        $listHtml = $this->call($browser, 'GET', '/superadmin/users')->body();
        $this->assertStringContainsString('Showing 1 to 25 of 30', $listHtml);
        $this->assertStringContainsString('aria-label="Page 1"', $listHtml);
        $this->assertStringContainsString('aria-label="Page 2"', $listHtml);
        $this->assertStringContainsString('aria-sort="ascending"', $listHtml);

        $emptyHtml = $this->call($browser, 'GET', '/superadmin/users', query: ['q' => 'nonexistent-user-xyz'])->body();
        $this->assertStringContainsString('No accounts match', $emptyHtml);
        $this->assertStringContainsString('Showing 0 to 0 of 0', $emptyHtml);

        $this->assertSame(0, $this->orgDbFactoryCalls);
    }

    // ------------------------------------------------------------------ 4. Super Admin actions, errors & audit entries

    public function testSuperAdminActionsCreateAccountsManageMembershipsAuditAndPreserveErrors(): void
    {
        $org1 = $this->kit->organization('act-one-' . bin2hex(random_bytes(2)));
        $org2 = $this->kit->organization('act-two-' . bin2hex(random_bytes(2)));
        $sa = $this->kit->staff('sa-act@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Super Actor');
        $browser = $this->browser($sa->id);

        // 4a. Create staff without password -> starts pending, audited.
        $createPending = $this->call($browser, 'POST', '/superadmin/users/staff', [
            'organization_id' => $org1->id,
            'full_name' => 'Liza Pending',
            'email' => 'liza.pending@example.test',
            'role' => 'manager',
            'password' => '',
        ]);
        $this->assertSame(302, $createPending->status());
        $liza = $this->kit->users->findByEmail('liza.pending@example.test');
        $this->assertIsArray($liza);
        $this->assertSame('pending', $liza['status']);
        $this->assertNull($liza['password_hash']);
        $this->assertSame('manager', $this->kit->memberships->find((string) $liza['id'], $org1->id)['role']);
        $this->assertSame('/superadmin/users/' . $liza['id'], $createPending->header('Location'));
        $this->assertGreaterThanOrEqual(1, $this->countAudit('user.created'));
        $this->assertGreaterThanOrEqual(1, $this->countAudit('membership.added'));

        // 4b. Create staff WITH password -> starts active and can authenticate.
        $createActive = $this->call($browser, 'POST', '/superadmin/users/staff', [
            'organization_id' => $org1->id,
            'full_name' => 'Marco Active',
            'email' => 'marco.active@example.test',
            'role' => 'staff',
            'password' => 'strong-pass-1234',
        ]);
        $this->assertSame(302, $createActive->status());
        $marco = $this->kit->users->findByEmail('marco.active@example.test');
        $this->assertIsArray($marco);
        $this->assertSame('active', $marco['status']);
        $this->assertNotNull($marco['password_hash']);
        $this->kit->auth->authenticateStaff('marco.active@example.test', 'strong-pass-1234', self::IP);

        // 4c. Create staff validation errors keep input (never echoing password!) and handle duplicate & invalid role.
        $badStaff = $this->call($browser, 'POST', '/superadmin/users/staff', [
            'organization_id' => $org1->id,
            'full_name' => 'Bad Role User',
            'email' => 'badrole@example.test',
            'role' => 'director',
            'password' => 'do-not-echo-this-pw',
        ]);
        $this->assertSame(422, $badStaff->status());
        $this->assertStringContainsString('Bad Role User', $badStaff->body());
        $this->assertStringContainsString('badrole@example.test', $badStaff->body());
        $this->assertStringContainsString('Choose Admin, Manager, Staff or Cashier.', $badStaff->body());
        $this->assertStringNotContainsString('do-not-echo-this-pw', $badStaff->body());

        $dupStaff = $this->call($browser, 'POST', '/superadmin/users/staff', [
            'organization_id' => $org1->id,
            'full_name' => 'Marco Duplicate',
            'email' => 'marco.active@example.test',
            'role' => 'cashier',
        ]);
        $this->assertSame(422, $dupStaff->status());
        $this->assertStringContainsString('already on the team', $dupStaff->body());
        $this->assertStringContainsString('marco.active@example.test', $dupStaff->body());

        // 4d. Create group account (Group Viewer & Super Admin) + duplicate email (409) & invalid role (422).
        $createGroup = $this->call($browser, 'POST', '/superadmin/users/group', [
            'full_name' => 'Nadia Viewer',
            'email' => 'nadia.viewer@example.test',
            'org_role' => 'group_viewer',
            'password' => 'group-pass-9876',
        ]);
        $this->assertSame(302, $createGroup->status());
        $nadia = $this->kit->users->findByEmail('nadia.viewer@example.test');
        $this->assertIsArray($nadia);
        $this->assertSame('group_viewer', $nadia['org_role']);
        $this->assertSame('active', $nadia['status']);

        $dupGroup = $this->call($browser, 'POST', '/superadmin/users/group', [
            'full_name' => 'Nadia Again',
            'email' => 'nadia.viewer@example.test',
            'org_role' => 'super_admin',
            'password' => 'secret-never-echo',
        ]);
        $this->assertSame(409, $dupGroup->status());
        $this->assertStringContainsString('This email already has an account.', $dupGroup->body());
        $this->assertStringContainsString('Nadia Again', $dupGroup->body());
        $this->assertStringNotContainsString('secret-never-echo', $dupGroup->body());

        $badGroupRole = $this->call($browser, 'POST', '/superadmin/users/group', [
            'full_name' => 'Wrong Group Role',
            'email' => 'wronggroup@example.test',
            'org_role' => 'admin',
        ]);
        $this->assertSame(422, $badGroupRole->status());
        $this->assertStringContainsString('Choose Super Admin or Group Viewer.', $badGroupRole->body());

        // 4e. Add membership to existing account (Marco gets Org 2 membership).
        $marcoId = (string) $marco['id'];
        $addMem = $this->call($browser, 'POST', '/superadmin/users/' . $marcoId . '/memberships', [
            'organization_id' => $org2->id,
            'role' => 'cashier',
        ]);
        $this->assertSame(302, $addMem->status());
        $this->assertSame('cashier', $this->kit->memberships->find($marcoId, $org2->id)['role']);

        // Adding duplicate active membership returns 422 next to the form.
        $dupMem = $this->call($browser, 'POST', '/superadmin/users/' . $marcoId . '/memberships', [
            'organization_id' => $org2->id,
            'role' => 'manager',
        ]);
        $this->assertSame(422, $dupMem->status());
        $this->assertStringContainsString('already on the team', $dupMem->body());

        // 4f. Change role (valid + invalid).
        $changeRole = $this->call($browser, 'POST', '/superadmin/users/' . $marcoId . '/memberships/' . $org2->id . '/role', [
            'role' => 'manager',
        ]);
        $this->assertSame(302, $changeRole->status());
        $this->assertSame('manager', $this->kit->memberships->find($marcoId, $org2->id)['role']);
        $this->assertGreaterThanOrEqual(1, $this->countAudit('membership.role_changed'));

        $badChange = $this->call($browser, 'POST', '/superadmin/users/' . $marcoId . '/memberships/' . $org2->id . '/role', [
            'role' => 'boss',
        ]);
        $this->assertSame(422, $badChange->status());
        $this->assertStringContainsString('Choose Admin, Manager, Staff or Cashier.', $badChange->body());

        // 4g. Deactivate one membership of a multi-org user: membership becomes inactive, account stays active!
        $deact = $this->call($browser, 'POST', '/superadmin/users/' . $marcoId . '/memberships/' . $org2->id . '/deactivate');
        $this->assertSame(302, $deact->status());
        $this->assertSame('inactive', $this->kit->memberships->find($marcoId, $org2->id)['status']);
        $this->assertSame('active', $this->kit->memberships->find($marcoId, $org1->id)['status']);
        $this->assertSame('active', $this->kit->users->findById($marcoId)['status'], 'account stays active while another membership is active');
        $this->assertGreaterThanOrEqual(1, $this->countAudit('membership.deactivated'));

        // Detail page shows Account status Active and the deactivated membership Inactive separately.
        $detailHtml = $this->call($browser, 'GET', '/superadmin/users/' . $marcoId)->body();
        $this->assertStringContainsString('Active', $detailHtml);
        $this->assertStringContainsString('Inactive', $detailHtml);

        // 4h. Reactivate the membership.
        $react = $this->call($browser, 'POST', '/superadmin/users/' . $marcoId . '/memberships/' . $org2->id . '/reactivate');
        $this->assertSame(302, $react->status());
        $this->assertSame('active', $this->kit->memberships->find($marcoId, $org2->id)['status']);
        $this->assertGreaterThanOrEqual(1, $this->countAudit('membership.reactivated'));

        $this->assertSame(0, $this->orgDbFactoryCalls);
    }

    // ------------------------------------------------------------------ 5. Last active Admin rule

    public function testLastActiveAdminCannotBeDeactivatedOrDemotedUntilAnotherActiveAdminExists(): void
    {
        $org = $this->kit->organization('last-admin-' . bin2hex(random_bytes(2)));
        $sa = $this->kit->staff('sa-last@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Super Admin');
        $admin1 = $this->kit->member($org, 'admin1@example.test', 'admin');
        $admin1Id = (string) $admin1->id;

        // Pending admin and inactive-membership admin must NOT count as another active Admin.
        $pendingAdmin = $this->kit->staff('pending-admin@example.test', null, null, 'pending', 'Pending Admin');
        $this->kit->join($org, $pendingAdmin, 'admin');
        $inactiveAdmin = $this->kit->member($org, 'inactive-admin@example.test', 'admin', 'inactive');

        $browser = $this->browser($sa->id);
        $auditBefore = (int) $this->core->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

        // Direct AccountService checks (covers Admin app, CLI, and any caller).
        $deactErr = $this->assertHttpStatus(422, fn () => $this->kit->accounts->deactivate($sa, $org, $admin1Id));
        $this->assertStringContainsString('last active Admin', implode(' ', $deactErr->fields()));

        $roleErr = $this->assertHttpStatus(422, fn () => $this->kit->accounts->changeRole($sa, $org, $admin1Id, 'manager'));
        $this->assertStringContainsString('last active Admin', implode(' ', $roleErr->fields()));

        // HTTP Super Admin route checks render the message inline and change nothing.
        $httpDeact = $this->call($browser, 'POST', '/superadmin/users/' . $admin1Id . '/memberships/' . $org->id . '/deactivate');
        $this->assertSame(422, $httpDeact->status());
        $this->assertStringContainsString('last active Admin', $httpDeact->body());

        $httpRole = $this->call($browser, 'POST', '/superadmin/users/' . $admin1Id . '/memberships/' . $org->id . '/role', ['role' => 'staff']);
        $this->assertSame(422, $httpRole->status());
        $this->assertStringContainsString('last active Admin', $httpRole->body());

        // Nothing changed and nothing audited.
        $this->assertSame('admin', $this->kit->memberships->find($admin1Id, $org->id)['role']);
        $this->assertSame('active', $this->kit->memberships->find($admin1Id, $org->id)['status']);
        $this->assertSame('active', $this->kit->userRow($admin1)['status']);
        $this->assertSame($auditBefore, (int) $this->core->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());

        // Keeping the same role ('admin') is not refused.
        $sameRole = $this->call($browser, 'POST', '/superadmin/users/' . $admin1Id . '/memberships/' . $org->id . '/role', ['role' => 'admin']);
        $this->assertSame(302, $sameRole->status());

        // Once a second ACTIVE Admin exists (by reactivating $inactiveAdmin), moving/deactivating $admin1 succeeds!
        $this->kit->accounts->reactivate($sa, $org, (string) $inactiveAdmin->id);

        $nowDemote = $this->call($browser, 'POST', '/superadmin/users/' . $admin1Id . '/memberships/' . $org->id . '/role', ['role' => 'manager']);
        $this->assertSame(302, $nowDemote->status());
        $this->assertSame('manager', $this->kit->memberships->find($admin1Id, $org->id)['role']);

        // Now $inactiveAdmin is the last active Admin and is protected.
        $nowBlocked = $this->call($browser, 'POST', '/superadmin/users/' . $inactiveAdmin->id . '/memberships/' . $org->id . '/deactivate');
        $this->assertSame(422, $nowBlocked->status());
        $this->assertStringContainsString('last active Admin', $nowBlocked->body());

        // Promote $admin1 back to admin, then deactivating $inactiveAdmin succeeds.
        $this->kit->accounts->changeRole($sa, $org, $admin1Id, 'admin');
        $nowDeact = $this->call($browser, 'POST', '/superadmin/users/' . $inactiveAdmin->id . '/memberships/' . $org->id . '/deactivate');
        $this->assertSame(302, $nowDeact->status());
        $this->assertSame('inactive', $this->kit->memberships->find((string) $inactiveAdmin->id, $org->id)['status']);

        $this->assertSame(0, $this->orgDbFactoryCalls);
    }

    // ------------------------------------------------------------------ 6. One-time reset link rules & zero leakage

    public function testResetLinkRequiresMembershipAppearsOnceNeverStoredOrLoggedAndWorksOnce(): void
    {
        $org = $this->kit->organization('reset-org-' . bin2hex(random_bytes(2)));
        $sa = $this->kit->staff('sa-reset@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Super Reset');
        $groupOnly = $this->kit->staff('gv-only@example.test', TestKit::PASSWORD, 'group_viewer', 'active', 'Group Only');
        $pendingStaff = $this->kit->staff('invitee@example.test', null, null, 'pending', 'Invitee Staff');
        $this->kit->join($org, $pendingStaff, 'staff');

        $browser = $this->browser($sa->id);

        // Group-only account (0 memberships) cannot get a reset link here.
        $groupDetail = $this->call($browser, 'GET', '/superadmin/users/' . $groupOnly->id)->body();
        $this->assertStringNotContainsString('Generate password reset link', $groupDetail);
        $groupPost = $this->call($browser, 'POST', '/superadmin/users/' . $groupOnly->id . '/reset-link', ['organization_id' => $org->id]);
        $this->assertSame(422, $groupPost->status());
        $this->assertStringContainsString('no organization membership', $groupPost->body());

        $logPath = dirname(__DIR__, 2) . '/storage/logs/app.log';
        $logSizeBefore = is_file($logPath) ? (int) filesize($logPath) : 0;

        // Staff account with membership gets a one-time reset link shown once on POST response with no-store.
        $resp = $this->call($browser, 'POST', '/superadmin/users/' . $pendingStaff->id . '/reset-link', [
            'organization_id' => $org->id,
        ]);
        $this->assertSame(200, $resp->status());
        $this->assertSame('no-store', $resp->header('Cache-Control'));
        $body = $resp->body();
        $this->assertStringContainsString('GFH sends no email', $body);
        $this->assertStringContainsString('/' . $org->slug . '/reset-password?token=', $body);

        $matched = preg_match('#/' . preg_quote($org->slug, '#') . '/reset-password\?token=([A-Za-z0-9_-]{20,128})#', $body, $m);
        $this->assertSame(1, $matched, 'Reset link URL with token must be shown on POST response');
        $token = $m[1];

        // Later GET responses (detail, list, audit) NEVER contain the token.
        foreach (['/superadmin/users/' . $pendingStaff->id, '/superadmin/users', '/superadmin/audit'] as $laterPath) {
            $later = $this->call($browser, 'GET', $laterPath);
            $this->assertSame(200, $later->status());
            $this->assertStringNotContainsString($token, $later->body(), "$laterPath must never contain the reset token");
        }

        // Neither audit_log nor password_reset_tokens stores the plaintext token.
        $auditRows = $this->core->query('SELECT action, detail::text AS detail FROM audit_log')->fetchAll();
        $this->assertGreaterThanOrEqual(1, $this->countAudit('password_reset.issued'));
        foreach ($auditRows as $row) {
            $this->assertStringNotContainsString($token, (string) $row['detail']);
        }
        $storedHashes = $this->core->query('SELECT token_hash FROM password_reset_tokens')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($storedHashes as $storedHash) {
            $this->assertNotSame($token, (string) $storedHash);
        }

        // Application log does not contain the token.
        if (is_file($logPath)) {
            $appended = (string) file_get_contents($logPath, false, null, $logSizeBefore);
            $this->assertStringNotContainsString($token, $appended);
        }

        // The issued token actually works once to activate the pending account.
        $this->kit->resets->consume($token, 'brand-new-password-1');
        $this->assertSame('active', $this->kit->userRow($pendingStaff)['status']);
        $this->assertHttpStatus(422, fn () => $this->kit->resets->consume($token, 'another-password-2'));

        $this->assertSame(0, $this->orgDbFactoryCalls);
    }

    // ------------------------------------------------------------------ 7. Output safety, 404s, and zero org DB

    public function testOutputEscapesMarkupHidesSecretsReturns404ForUnknownIdsAndNeverOpensOrgDb(): void
    {
        $hostileOrg = $this->kit->organizations->create(
            'xss-u-' . bin2hex(random_bytes(2)),
            'Org <script>alert("org")</script>',
            'XO',
            'postgresql://secret_u:secret_p@db.internal:5432/org_x',
            'public',
            [],
            [],
        );
        $sa = $this->kit->staff('sa-xss@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Super <script>alert("sa")</script>');
        $victim = $this->kit->staff('xss+user@example.test', TestKit::PASSWORD, null, 'active', 'User <script>alert("user")</script> "Q"');
        $this->kit->join($hostileOrg, $victim, 'staff');

        $saHash = (string) $this->kit->userRow($sa)['password_hash'];
        $victimHash = (string) $this->kit->userRow($victim)['password_hash'];

        $browser = $this->browser($sa->id);

        foreach (['/superadmin/users', '/superadmin/users/' . $victim->id, '/superadmin/users/new-staff', '/superadmin/users/new-group'] as $path) {
            $resp = $this->call($browser, 'GET', $path);
            $this->assertSame(200, $resp->status(), $path);
            $body = $resp->body();

            $this->assertStringNotContainsString('<script>alert(', $body, $path);
            $this->assertStringNotContainsString($saHash, $body, $path);
            $this->assertStringNotContainsString($victimHash, $body, $path);
            $this->assertStringNotContainsString('postgresql://', $body, $path);
            $this->assertStringNotContainsString('secret_p', $body, $path);
            $this->assertStringNotContainsString('connection_encrypted', $body, $path);
        }

        $detailBody = $this->call($browser, 'GET', '/superadmin/users/' . $victim->id)->body();
        $this->assertStringContainsString('User &lt;script&gt;alert(&quot;user&quot;)&lt;/script&gt; &quot;Q&quot;', $detailBody);
        $this->assertStringContainsString('Org &lt;script&gt;alert(&quot;org&quot;)&lt;/script&gt;', $detailBody);

        // Unknown and malformed IDs return 404.
        $this->assertSame(404, $this->call($browser, 'GET', '/superadmin/users/not-a-uuid')->status());
        $this->assertSame(404, $this->call($browser, 'GET', '/superadmin/users/00000000-0000-4000-8000-000000000000')->status());

        // Verify Services never instantiated tenants or database.
        $instances = (new \ReflectionProperty(Services::class, 'instances'))->getValue($browser['services']);
        $this->assertArrayNotHasKey('tenants', $instances);
        $this->assertArrayNotHasKey('database', $instances);
        $this->assertSame(0, $this->orgDbFactoryCalls);
    }
}
