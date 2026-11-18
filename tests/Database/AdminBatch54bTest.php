<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\AccountService;
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\Services;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Organization;
use Gfh\Tests\Support\DatabaseTestCase;

/**
 * Batch 5.4b: the Admin Team pages (/{org}/admin/team) and the four Reports polish fixes.
 *
 * Every Team request goes through the real App + Router + core/routes.php + middleware stack, so a controller missing
 * from the route group, a route without CSRF, or a page that skips can() is caught here and not only in a unit test.
 */
final class AdminBatch54bTest extends DatabaseTestCase
{
    private App $app;
    private Organization $org1;
    private Organization $org2;
    private StaffUser $admin;
    private StaffUser $manager;
    private StaffUser $staff;
    private StaffUser $cashier;
    /** Belongs to the other organization only. */
    private StaffUser $outsider;
    /** A group-level account that also holds a membership row in the first organization. */
    private StaffUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 2));

        $this->org1 = $this->kit->organization('team-a-' . bin2hex(random_bytes(3)));
        $this->org2 = $this->kit->organization('team-b-' . bin2hex(random_bytes(3)));
        $this->admin = $this->person($this->org1, 'admin@team-a.test', 'admin', 'Alma Admin');
        $this->manager = $this->person($this->org1, 'manager@team-a.test', 'manager', 'Mara Manager');
        $this->staff = $this->person($this->org1, 'sam@team-a.test', 'staff', 'Sam Staff');
        $this->cashier = $this->person($this->org1, 'casey@team-a.test', 'cashier', 'Casey Cashier');
        $this->outsider = $this->person($this->org2, 'olive@team-b.test', 'staff', 'Olive Outsider');
        $this->superAdmin = $this->kit->staff('boss@group.test', \Gfh\Tests\Support\TestKit::PASSWORD, 'super_admin', 'active', 'Bea Boss');
        $this->kit->join($this->org1, $this->superAdmin, 'admin');
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function person(Organization $organization, string $email, string $role, string $name): StaffUser
    {
        $user = $this->kit->staff($email, \Gfh\Tests\Support\TestKit::PASSWORD, null, 'active', $name);
        $this->kit->join($organization, $user, $role);

        return $user;
    }

    /** @return array{services: Services, session: Session} one browser: a session (signed in as $actor, or signed out) and the services using it */
    private function desk(?StaffUser $actor): array
    {
        $session = Session::inMemory('staff', $this->app->config(), $actor === null ? [] : ['user_id' => $actor->id]);

        return [
            'services' => new Services($this->app, false, $this->core, $this->org, testSessions: ['staff' => $session]),
            'session' => $session,
        ];
    }

    /**
     * @param array{services: Services, session: Session} $desk
     * @param array<string, string> $post
     * @param array<string, string> $query
     */
    private function call(array $desk, string $method, string $path, array $post = [], array $query = [], bool $withCsrf = true): Response
    {
        if ($method === 'POST' && $withCsrf) {
            $post['_csrf'] = $desk['services']->csrf($desk['session'])->token();
        }

        return $this->app->handle(new Request($method, $path, query: $query, post: $post), $desk['services']);
    }

    private function url(string $path = '', ?Organization $organization = null): string
    {
        return '/' . ($organization ?? $this->org1)->slug . '/admin/team' . $path;
    }

    /** @return array<string, mixed> */
    private function membership(StaffUser $user, ?Organization $organization = null): array
    {
        $statement = $this->core->prepare('SELECT role, status FROM memberships WHERE user_id = ? AND organization_id = ?');
        $statement->execute([$user->id, ($organization ?? $this->org1)->id]);
        $row = $statement->fetch();
        $this->assertIsArray($row, 'the membership row exists');

        return $row;
    }

    /** What the database holds for one person: account and membership, for "nothing changed" checks. */
    private function snapshot(StaffUser $user, ?Organization $organization = null): string
    {
        $row = $this->kit->userRow($user);

        return json_encode([$row['full_name'], $row['email'], $row['status'], $row['password_hash'], $this->membership($user, $organization)], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $result @return list<string> */
    private function names(array $result): array
    {
        return array_map(static fn (array $row): string => (string) $row['full_name'], $result['items']);
    }

    /**
     * @param array<string, string> $query
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string}
     */
    private function listTeam(array $query): array
    {
        $request = new Request('GET', $this->url(), query: $query);
        $listQuery = ListQuery::fromRequest($request, AccountService::TEAM_SORTS, 'name', ['role', 'status']);

        return $this->desk($this->admin)['services']->accounts()->listTeam($this->admin, $this->org1, $listQuery);
    }

    /** @return list<array{0: string, 1: string, 2: array<string, string>}> every Team route, with a believable payload for the POSTs */
    private function everyTeamRequest(string $memberId): array
    {
        $payload = ['full_name' => 'Someone Else', 'email' => 'someone@team-a.test', 'role' => 'staff'];

        return [
            ['GET', $this->url(), []],
            ['GET', $this->url('/new'), []],
            ['GET', $this->url('/' . $memberId), []],
            ['POST', $this->url(), $payload],
            ['POST', $this->url('/' . $memberId), $payload],
            ['POST', $this->url('/' . $memberId . '/deactivate'), []],
            ['POST', $this->url('/' . $memberId . '/reactivate'), []],
            ['POST', $this->url('/' . $memberId . '/reset-link'), []],
        ];
    }

    // ------------------------------------------------------------------ permissions

    public function testManagerStaffAndCashierAreRefusedOnEveryTeamPageAndPost(): void
    {
        $before = $this->snapshot($this->cashier);
        $usersBefore = (int) $this->core->query('SELECT COUNT(*) FROM users')->fetchColumn();

        foreach (['manager' => $this->manager, 'staff' => $this->staff, 'cashier' => $this->cashier] as $role => $actor) {
            $desk = $this->desk($actor);
            foreach ($this->everyTeamRequest((string) $this->cashier->id) as [$method, $path, $post]) {
                $response = $this->call($desk, $method, $path, $post);
                $this->assertSame(403, $response->status(), "$role: $method $path must be refused");
                $this->assertStringNotContainsString('Cashier', $method === 'GET' ? $response->body() : '', "$role: $method $path must not leak the team");
            }
        }

        $this->assertSame($before, $this->snapshot($this->cashier), 'a refused request changes nothing');
        $this->assertSame($usersBefore, (int) $this->core->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'a refused create makes no account');
    }

    public function testOnlyAnAdminHasReportsAdminEditSoOnlyAnAdminOpensTeam(): void
    {
        $permissions = $this->kit->permissions;
        $this->assertTrue($permissions->can($this->admin, $this->org1, 'reports_admin', 'edit'));
        foreach ([$this->manager, $this->staff, $this->cashier] as $actor) {
            $this->assertFalse($permissions->can($actor, $this->org1, 'reports_admin', 'edit'));
        }

        $response = $this->call($this->desk($this->admin), 'GET', $this->url());
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Team', $response->body());
    }

    public function testSignedOutVisitorsAreRedirectedToSignInAndSignedOutPostsChangeNothing(): void
    {
        $before = $this->snapshot($this->staff);
        $desk = $this->desk(null);

        foreach ($this->everyTeamRequest((string) $this->staff->id) as [$method, $path, $post]) {
            $response = $this->call($desk, $method, $path, $post);
            $this->assertSame(302, $response->status(), "$method $path");
            $this->assertStringContainsString('/admin/login', (string) $response->header('Location'), "$method $path");
        }

        // Without even a token the CSRF check answers first.
        foreach ($this->everyTeamRequest((string) $this->staff->id) as [$method, $path, $post]) {
            if ($method === 'POST') {
                $this->assertSame(403, $this->call($desk, $method, $path, $post, withCsrf: false)->status(), "$method $path");
            }
        }

        $this->assertSame($before, $this->snapshot($this->staff));
    }

    public function testEveryTeamWriteRefusesAMissingOrWrongCsrfTokenAndChangesNothing(): void
    {
        $desk = $this->desk($this->admin);
        $before = [$this->snapshot($this->staff), $this->snapshot($this->cashier)];
        $usersBefore = (int) $this->core->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $auditBefore = (int) $this->core->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

        foreach ($this->everyTeamRequest((string) $this->staff->id) as [$method, $path, $post]) {
            if ($method !== 'POST') {
                continue;
            }
            $this->assertSame(403, $this->call($desk, $method, $path, $post, withCsrf: false)->status(), "no token: $path");
            $this->assertSame(403, $this->call($desk, $method, $path, $post + ['_csrf' => 'not-the-token'], withCsrf: false)->status(), "wrong token: $path");
        }

        $this->assertSame($before, [$this->snapshot($this->staff), $this->snapshot($this->cashier)]);
        $this->assertSame($usersBefore, (int) $this->core->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame($auditBefore, (int) $this->core->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(), 'a refused write is not audited as a change');
    }

    // ------------------------------------------------------------------ the happy paths

    public function testAnAdminCreatesAStaffAndACashierAccountInTheirOwnOrganization(): void
    {
        $desk = $this->desk($this->admin);

        foreach (['staff' => 'Nina Newhire', 'cashier' => 'Carl Counter'] as $role => $name) {
            $email = strtolower(explode(' ', $name)[0]) . '@team-a.test';
            $response = $this->call($desk, 'POST', $this->url(), ['full_name' => $name, 'email' => $email, 'role' => $role]);
            $this->assertSame(302, $response->status(), $role);

            $user = $this->core->prepare('SELECT id, full_name, status, password_hash FROM users WHERE lower(email) = ?');
            $user->execute([$email]);
            $row = $user->fetch();
            $this->assertIsArray($row);
            $this->assertSame($name, $row['full_name']);
            $this->assertSame('pending', $row['status'], 'no password is set, the person gets a reset link');
            $this->assertNull($row['password_hash'], 'no password is ever stored here');
            $this->assertSame($this->url('/' . $row['id']), $response->header('Location'));

            $membership = $this->core->prepare('SELECT role, status, organization_id FROM memberships WHERE user_id = ?');
            $membership->execute([$row['id']]);
            $this->assertSame([['role' => $role, 'status' => 'active', 'organization_id' => $this->org1->id]], $membership->fetchAll());
        }

        $this->assertSame(2, $this->countAudit('user.created'));
    }

    public function testCreateValidatesTheFieldsAndRefusesADuplicateOnTheTeam(): void
    {
        $desk = $this->desk($this->admin);

        $badEmail = $this->call($desk, 'POST', $this->url(), ['full_name' => 'No Email', 'email' => 'not-an-email', 'role' => 'staff']);
        $this->assertSame(422, $badEmail->status());
        $this->assertStringContainsString('Enter a valid email address.', $badEmail->body());
        $this->assertStringContainsString('aria-invalid="true"', $badEmail->body());

        $noName = $this->call($desk, 'POST', $this->url(), ['full_name' => '', 'email' => 'blank@team-a.test', 'role' => 'staff']);
        $this->assertSame(422, $noName->status());
        $this->assertStringContainsString('Enter the full name', $noName->body());

        $noRole = $this->call($desk, 'POST', $this->url(), ['full_name' => 'No Role', 'email' => 'norole@team-a.test', 'role' => '']);
        $this->assertSame(422, $noRole->status());

        $duplicate = $this->call($desk, 'POST', $this->url(), ['full_name' => 'Sam Again', 'email' => 'SAM@team-a.test', 'role' => 'cashier']);
        $this->assertSame(422, $duplicate->status());
        $this->assertStringContainsString('already on the team', $duplicate->body());
        $this->assertSame('staff', $this->membership($this->staff)['role'], 'the existing member is untouched');
    }

    public function testAddingSomeoneWhoAlreadyHasAnAccountElsewhereChangesNothingAboutThatAccount(): void
    {
        $before = $this->kit->userRow($this->outsider);

        $response = $this->call($this->desk($this->admin), 'POST', $this->url(), ['full_name' => 'A Different Name', 'email' => 'olive@team-b.test', 'role' => 'cashier']);
        $this->assertSame(302, $response->status());

        $after = $this->kit->userRow($this->outsider);
        $this->assertSame($before['full_name'], $after['full_name'], 'name not changed');
        $this->assertSame($before['password_hash'], $after['password_hash'], 'password not changed');
        $this->assertSame('cashier', $this->membership($this->outsider)['role']);
        $this->assertSame('staff', $this->membership($this->outsider, $this->org2)['role'], 'their other organization is untouched');
    }

    public function testAnAdminEditsNameEmailAndSwitchesBetweenStaffAndCashier(): void
    {
        $desk = $this->desk($this->admin);
        $path = $this->url('/' . $this->staff->id);

        $form = $this->call($desk, 'GET', $path);
        $this->assertSame(200, $form->status());
        $this->assertStringContainsString('Sam Staff', $form->body());
        $this->assertStringContainsString('value="sam@team-a.test"', $form->body());

        $saved = $this->call($desk, 'POST', $path, ['full_name' => 'Samantha Staff', 'email' => 'samantha@team-a.test', 'role' => 'cashier']);
        $this->assertSame(302, $saved->status());
        $row = $this->kit->userRow($this->staff);
        $this->assertSame('Samantha Staff', $row['full_name']);
        $this->assertSame('samantha@team-a.test', $row['email']);
        $this->assertSame('cashier', $this->membership($this->staff)['role']);
        $this->assertSame(1, $this->countAudit('membership.role_changed'));
        $this->assertSame(1, $this->countAudit('user.profile_updated'));

        // A save that keeps the role does not write a role change.
        $this->call($desk, 'POST', $path, ['full_name' => 'Samantha S.', 'email' => 'samantha@team-a.test', 'role' => 'cashier']);
        $this->assertSame(1, $this->countAudit('membership.role_changed'));
    }

    public function testEditRefusesBadValuesAndAnEmailAnotherAccountUsesAndKeepsTheOldData(): void
    {
        $desk = $this->desk($this->admin);
        $path = $this->url('/' . $this->staff->id);
        $before = $this->snapshot($this->staff);

        $clash = $this->call($desk, 'POST', $path, ['full_name' => 'Sam Staff', 'email' => 'casey@team-a.test', 'role' => 'staff']);
        $this->assertSame(422, $clash->status());
        $this->assertStringContainsString('Another account already uses this email.', $clash->body());
        $this->assertStringContainsString('value="casey@team-a.test"', $clash->body(), 'the typed value stays in the form');

        $blank = $this->call($desk, 'POST', $path, ['full_name' => '', 'email' => 'sam@team-a.test', 'role' => 'staff']);
        $this->assertSame(422, $blank->status());

        $this->assertSame($before, $this->snapshot($this->staff));
    }

    public function testDeactivateAndReactivateWorkForStaffAndCashierAndNothingIsDeleted(): void
    {
        $desk = $this->desk($this->admin);
        $users = (int) $this->core->query('SELECT COUNT(*) FROM users')->fetchColumn();

        foreach ([$this->staff, $this->cashier] as $person) {
            $off = $this->call($desk, 'POST', $this->url('/' . $person->id . '/deactivate'));
            $this->assertSame(302, $off->status());
            $this->assertSame('inactive', $this->membership($person)['status']);
            $this->assertSame('inactive', $this->kit->userRow($person)['status'], 'their only organization, so the account is switched off');

            $on = $this->call($desk, 'POST', $this->url('/' . $person->id . '/reactivate'));
            $this->assertSame(302, $on->status());
            $this->assertSame('active', $this->membership($person)['status']);
            $this->assertSame('active', $this->kit->userRow($person)['status']);
        }

        $this->assertSame($users, (int) $this->core->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'no account is deleted');
        $this->assertSame(2, $this->countAudit('membership.deactivated'));
        $this->assertSame(2, $this->countAudit('membership.reactivated'));
    }

    public function testADeactivatedMemberCannotSignIn(): void
    {
        // Before: the cashier signs in through the real sign-in page.
        $signedOut = $this->desk(null);
        $first = $this->call($signedOut, 'POST', '/' . $this->org1->slug . '/admin/login', ['email' => 'casey@team-a.test', 'password' => \Gfh\Tests\Support\TestKit::PASSWORD]);
        $this->assertSame(302, $first->status(), 'sign-in works before deactivation');

        $this->call($this->desk($this->admin), 'POST', $this->url('/' . $this->cashier->id . '/deactivate'));

        $second = $this->call($this->desk(null), 'POST', '/' . $this->org1->slug . '/admin/login', ['email' => 'casey@team-a.test', 'password' => \Gfh\Tests\Support\TestKit::PASSWORD]);
        $this->assertNotSame(302, $second->status(), 'the sign-in page refuses a deactivated member');
        $this->assertSame(401, $second->status());

        $services = $this->desk(null)['services'];
        $this->assertHttpStatus(401, fn () => $services->auth()->authenticateStaff('casey@team-a.test', \Gfh\Tests\Support\TestKit::PASSWORD, '127.0.0.1'));

        // An open session of theirs stops working at once, because the account is reloaded on every request.
        $this->call($this->desk($this->admin), 'POST', $this->url('/' . $this->staff->id . '/deactivate'));
        $this->assertNotSame(200, $this->call($this->desk($this->staff), 'GET', '/' . $this->org1->slug . '/admin/inventory')->status());
    }

    public function testDeactivatingSomeoneWhoAlsoWorksElsewhereOnlyEndsAccessHere(): void
    {
        $shared = $this->person($this->org1, 'shared@team-a.test', 'staff', 'Shari Shared');
        $this->kit->join($this->org2, $shared, 'cashier');

        $this->call($this->desk($this->admin), 'POST', $this->url('/' . $shared->id . '/deactivate'));

        $this->assertSame('inactive', $this->membership($shared)['status']);
        $this->assertSame('active', $this->membership($shared, $this->org2)['status'], 'the other organization is untouched');
        $this->assertSame('active', $this->kit->userRow($shared)['status']);

        $here = $this->call($this->desk(null), 'POST', '/' . $this->org1->slug . '/admin/login', ['email' => 'shared@team-a.test', 'password' => \Gfh\Tests\Support\TestKit::PASSWORD]);
        $this->assertNotSame(302, $here->status(), 'no access to the organization they were removed from');
        $there = $this->call($this->desk(null), 'POST', '/' . $this->org2->slug . '/admin/login', ['email' => 'shared@team-a.test', 'password' => \Gfh\Tests\Support\TestKit::PASSWORD]);
        $this->assertSame(302, $there->status(), 'they can still sign in where they are active');
    }

    // ------------------------------------------------------------------ tampering

    public function testATamperedRoleIsRefusedOnCreateAndNothingIsCreated(): void
    {
        $desk = $this->desk($this->admin);
        $users = (int) $this->core->query('SELECT COUNT(*) FROM users')->fetchColumn();

        foreach (['admin' => 403, 'manager' => 403, 'super_admin' => 422, 'group_viewer' => 422, 'owner' => 422] as $role => $status) {
            $response = $this->call($desk, 'POST', $this->url(), ['full_name' => 'Sneaky Person', 'email' => 'sneaky-' . $role . '@team-a.test', 'role' => $role]);
            $this->assertSame($status, $response->status(), "create with role $role");
        }

        $this->assertSame($users, (int) $this->core->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testATamperedRoleIsRefusedOnEditAndTheWholeSaveIsRolledBack(): void
    {
        $desk = $this->desk($this->admin);
        $before = $this->snapshot($this->staff);

        foreach (['admin' => 403, 'manager' => 403, 'super_admin' => 422, 'group_viewer' => 422] as $role => $status) {
            // The name and email are valid, so only the role is wrong. Neither may be kept.
            $response = $this->call($desk, 'POST', $this->url('/' . $this->staff->id), ['full_name' => 'Renamed By Tampering', 'email' => 'renamed@team-a.test', 'role' => $role]);
            $this->assertSame($status, $response->status(), "edit with role $role");
            $this->assertSame($before, $this->snapshot($this->staff), "role $role left everything as it was");
        }
    }

    public function testAUserFromAnotherOrganizationIsOutOfReachOnEveryTeamRoute(): void
    {
        $desk = $this->desk($this->admin);
        $before = $this->snapshot($this->outsider, $this->org2);
        $id = (string) $this->outsider->id;

        $this->assertSame(404, $this->call($desk, 'GET', $this->url('/' . $id))->status(), 'the edit screen does not show them');
        foreach ([
            $this->url('/' . $id) => ['full_name' => 'Taken Over', 'email' => 'taken@team-a.test', 'role' => 'cashier'],
            $this->url('/' . $id . '/deactivate') => [],
            $this->url('/' . $id . '/reactivate') => [],
            $this->url('/' . $id . '/reset-link') => [],
        ] as $path => $post) {
            $this->assertContains($this->call($desk, 'POST', $path, $post)->status(), [403, 404, 422], "POST $path");
        }

        $this->assertSame($before, $this->snapshot($this->outsider, $this->org2));
        $this->assertSame(0, $this->countAudit('password_reset.issued'), 'no link was issued for them');

        $list = $this->call($desk, 'GET', $this->url(), query: ['per_page' => '100'])->body();
        $this->assertStringNotContainsString('Olive Outsider', $list);
        $this->assertStringNotContainsString('olive@team-b.test', $list);
    }

    public function testTheActorsOwnAccountCannotBeChangedFromTheTeamPages(): void
    {
        $desk = $this->desk($this->admin);
        $before = $this->snapshot($this->admin);
        $id = (string) $this->admin->id;

        foreach ([
            $this->url('/' . $id) => ['full_name' => 'Alma Renamed', 'email' => 'alma@team-a.test', 'role' => 'cashier'],
            $this->url('/' . $id . '/deactivate') => [],
            $this->url('/' . $id . '/reactivate') => [],
            $this->url('/' . $id . '/reset-link') => [],
        ] as $path => $post) {
            $this->assertSame(403, $this->call($desk, 'POST', $path, $post)->status(), "POST $path");
        }

        $this->assertSame($before, $this->snapshot($this->admin));
        $own = $this->call($desk, 'GET', $this->url('/' . $id));
        $this->assertSame(200, $own->status());
        $this->assertStringContainsString('read-only', $own->body());
        $this->assertStringNotContainsString('/deactivate', $own->body());
    }

    public function testAdminAndManagerTargetsCannotBeChanged(): void
    {
        $desk = $this->desk($this->admin);

        foreach ([$this->manager] as $target) {
            $before = $this->snapshot($target);
            $id = (string) $target->id;
            foreach ([
                $this->url('/' . $id) => ['full_name' => 'Demoted', 'email' => 'demoted@team-a.test', 'role' => 'staff'],
                $this->url('/' . $id . '/deactivate') => [],
                $this->url('/' . $id . '/reactivate') => [],
                $this->url('/' . $id . '/reset-link') => [],
            ] as $path => $post) {
                $this->assertSame(403, $this->call($desk, 'POST', $path, $post)->status(), "POST $path");
            }
            $this->assertSame($before, $this->snapshot($target));
        }

        // Another Admin of the same organization is just as out of reach as a Manager.
        $second = $this->person($this->org1, 'admin2@team-a.test', 'admin', 'Ada Second');
        $before = $this->snapshot($second);
        foreach ([$this->url('/' . $second->id . '/deactivate'), $this->url('/' . $second->id . '/reset-link')] as $path) {
            $this->assertSame(403, $this->call($desk, 'POST', $path)->status(), "POST $path");
        }
        $this->assertSame($before, $this->snapshot($second));
    }

    public function testAGroupLevelAccountIsNotOnTheTeamAndCannotBeReached(): void
    {
        $desk = $this->desk($this->admin);
        $before = $this->snapshot($this->superAdmin);
        $id = (string) $this->superAdmin->id;

        $this->assertSame(404, $this->call($desk, 'GET', $this->url('/' . $id))->status());
        foreach ([$this->url('/' . $id), $this->url('/' . $id . '/deactivate'), $this->url('/' . $id . '/reactivate'), $this->url('/' . $id . '/reset-link')] as $path) {
            $this->assertContains($this->call($desk, 'POST', $path, ['full_name' => 'X Y', 'email' => 'xy@team-a.test', 'role' => 'staff'])->status(), [403, 404], "POST $path");
        }
        $this->assertSame($before, $this->snapshot($this->superAdmin));
        $this->assertStringNotContainsString('boss@group.test', $this->call($desk, 'GET', $this->url())->body());
    }

    public function testAMalformedMemberIdIsNotFound(): void
    {
        $desk = $this->desk($this->admin);
        $this->assertSame(404, $this->call($desk, 'GET', $this->url('/not-a-uuid'))->status());
        $this->assertSame(404, $this->call($desk, 'POST', $this->url('/not-a-uuid/deactivate'))->status());
        $this->assertSame(404, $this->call($desk, 'GET', $this->url('/' . Uuid::v4()))->status());
    }

    // ------------------------------------------------------------------ reset links

    public function testAResetLinkIsShownOnceNeverStoredOrLoggedAndSentWithNoCacheHeaders(): void
    {
        $desk = $this->desk($this->admin);
        $response = $this->call($desk, 'POST', $this->url('/' . $this->cashier->id . '/reset-link'));

        $this->assertSame(200, $response->status());
        $this->assertSame(1, preg_match('#/reset-password\?token=([A-Za-z0-9_-]{20,128})#', $response->body(), $found), 'the link is on the page');
        $token = $found[1];

        $cache = (string) $response->header('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('no-cache', $cache);
        $this->assertSame('no-cache', $response->header('Pragma'));
        $this->assertSame('0', $response->header('Expires'));

        // Not in the audit log, in any column of any row.
        $this->assertSame(1, $this->countAudit('password_reset.issued'));
        $inAudit = $this->core->prepare('SELECT COUNT(*) FROM audit_log a WHERE a::text LIKE ?');
        $inAudit->execute(['%' . $token . '%']);
        $this->assertSame(0, (int) $inAudit->fetchColumn(), 'the token is not in the audit log');

        // Not stored in plain text: only its hash is.
        $stored = $this->core->prepare('SELECT COUNT(*) FROM password_reset_tokens t WHERE t::text LIKE ?');
        $stored->execute(['%' . $token . '%']);
        $this->assertSame(0, (int) $stored->fetchColumn(), 'the token is not stored');
        $hashed = $this->core->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE token_hash = ?');
        $hashed->execute([hash('sha256', $token)]);
        $this->assertSame(1, (int) $hashed->fetchColumn(), 'its hash is');

        // Not in the application log either.
        $log = dirname(__DIR__, 2) . '/storage/logs/app.log';
        $this->assertStringNotContainsString($token, is_file($log) ? (string) file_get_contents($log) : '');

        // No password, no hash, on the page.
        $this->assertStringNotContainsString(\Gfh\Tests\Support\TestKit::PASSWORD, $response->body());
        $this->assertStringNotContainsString('$argon', $response->body());
        $this->assertStringNotContainsString('$2y$', $response->body());

        // It is shown once: the member's own page, and the list, do not carry it.
        $this->assertStringNotContainsString($token, $this->call($desk, 'GET', $this->url('/' . $this->cashier->id))->body());
        $this->assertStringNotContainsString($token, $this->call($desk, 'GET', $this->url())->body());

        // And it works, once.
        $services = $this->desk(null)['services'];
        $this->assertSame('user', $services->resets()->consume($token, 'a-brand-new-password-1')['subject_type']);
        $this->assertHttpStatus(422, fn () => $services->resets()->consume($token, 'a-brand-new-password-2'));
        $this->assertSame('casey@team-a.test', $services->auth()->authenticateStaff('casey@team-a.test', 'a-brand-new-password-1', '127.0.0.1')->email);
    }

    public function testANewResetLinkRetiresTheOldOneAndAPendingMemberCanGetOne(): void
    {
        $desk = $this->desk($this->admin);
        $this->call($desk, 'POST', $this->url(), ['full_name' => 'Pending Pat', 'email' => 'pending@team-a.test', 'role' => 'staff']);
        $id = (string) $this->core->query("SELECT id FROM users WHERE email = 'pending@team-a.test'")->fetchColumn();

        $tokens = [];
        foreach ([1, 2] as $attempt) {
            $body = $this->call($desk, 'POST', $this->url('/' . $id . '/reset-link'))->body();
            $this->assertSame(1, preg_match('#token=([A-Za-z0-9_-]{20,128})#', $body, $found), "link $attempt");
            $tokens[] = $found[1];
        }

        $services = $this->desk(null)['services'];
        $this->assertHttpStatus(422, fn () => $services->resets()->consume($tokens[0], 'another-long-password-1'));
        $services->resets()->consume($tokens[1], 'another-long-password-1');
        $this->assertSame('active', $this->core->query("SELECT status FROM users WHERE id = '$id'")->fetchColumn(), 'setting a first password activates the invited account');
    }

    public function testTheExistingResetLinksPageStillWorksForAnAdmin(): void
    {
        $desk = $this->desk($this->admin);
        $path = '/' . $this->org1->slug . '/admin/reset-links';

        $this->assertSame(200, $this->call($desk, 'GET', $path)->status());
        $issued = $this->call($desk, 'POST', $path, ['type' => 'staff', 'email' => 'sam@team-a.test']);
        $this->assertSame(200, $issued->status());
        $this->assertStringContainsString('/reset-password?token=', $issued->body());

        $this->assertSame(403, $this->call($this->desk($this->manager), 'GET', $path)->status());
    }

    // ------------------------------------------------------------------ the list

    public function testTheListShowsOnlyThisOrganizationsTeamWithAdminAndManagerRowsReadOnly(): void
    {
        $response = $this->call($this->desk($this->admin), 'GET', $this->url());
        $body = $response->body();

        foreach (['Alma Admin', 'Mara Manager', 'Sam Staff', 'Casey Cashier'] as $name) {
            $this->assertStringContainsString($name, $body);
        }
        $this->assertStringNotContainsString('Olive Outsider', $body, 'another organization');
        $this->assertStringNotContainsString('Bea Boss', $body, 'a group-level account');

        $this->assertStringContainsString($this->url('/' . $this->staff->id), $body);
        $this->assertStringContainsString($this->url('/' . $this->cashier->id), $body);
        foreach ([$this->admin, $this->manager] as $readOnly) {
            $this->assertStringNotContainsString($this->url('/' . $readOnly->id), $body, 'no link, so no action, on an Admin or Manager row');
        }
        $this->assertSame(2, substr_count($body, 'Read-only'));
        $this->assertSame(2, preg_match_all('#<a class="button button--ghost button--sm" href="[^"]*">Manage</a>#', $body), 'Staff and Cashier rows only');
    }

    public function testTheListSearchesSortsFiltersAndPaginatesWithTheSharedParameters(): void
    {
        foreach (range(1, 5) as $n) {
            $this->person($this->org1, "pat$n@team-a.test", 'staff', "Pat $n");
        }
        // Nine people on the team: Alma, Casey, Mara, Pat 1 to 5, Sam. (Olive and Bea are not on it.)

        $all = $this->listTeam(['per_page' => '100']);
        $this->assertSame(9, $all['pagination']['total']);
        $this->assertSame(['Alma Admin', 'Casey Cashier', 'Mara Manager', 'Pat 1', 'Pat 2', 'Pat 3', 'Pat 4', 'Pat 5', 'Sam Staff'], $this->names($all));

        // Pagination
        $page1 = $this->listTeam(['per_page' => '4']);
        $this->assertSame(['page' => 1, 'per_page' => 4, 'total' => 9, 'pages' => 3], $page1['pagination']);
        $this->assertSame(['Alma Admin', 'Casey Cashier', 'Mara Manager', 'Pat 1'], $this->names($page1));
        $this->assertSame(['Pat 2', 'Pat 3', 'Pat 4', 'Pat 5'], $this->names($this->listTeam(['per_page' => '4', 'page' => '2'])));
        $this->assertSame(['Sam Staff'], $this->names($this->listTeam(['per_page' => '4', 'page' => '3'])));
        $this->assertSame([], $this->names($this->listTeam(['per_page' => '4', 'page' => '4'])));

        // Sort
        $this->assertSame('Sam Staff', $this->names($this->listTeam(['sort' => '-name']))[0]);
        $this->assertSame('Alma Admin', $this->names($this->listTeam(['sort' => 'email']))[0]);
        $byRole = array_column($this->listTeam(['sort' => 'role', 'per_page' => '100'])['items'], 'role');
        $sorted = $byRole;
        sort($sorted);
        $this->assertSame($sorted, $byRole);
        $this->assertSame('staff', array_column($this->listTeam(['sort' => '-role'])['items'], 'role')[0]);

        // Filters
        $this->assertSame(['Casey Cashier'], $this->names($this->listTeam(['role' => 'cashier'])));
        $this->assertSame(['Alma Admin'], $this->names($this->listTeam(['role' => 'admin'])));
        $this->assertSame(['Mara Manager'], $this->names($this->listTeam(['role' => 'manager'])));
        $this->assertCount(6, $this->listTeam(['role' => 'staff', 'per_page' => '100'])['items']);
        $this->assertSame(9, $this->listTeam(['status' => 'active'])['pagination']['total']);

        $this->call($this->desk($this->admin), 'POST', $this->url('/' . $this->staff->id . '/deactivate'));
        $this->assertSame(['Sam Staff'], $this->names($this->listTeam(['status' => 'inactive'])));
        $this->assertSame(8, $this->listTeam(['status' => 'active'])['pagination']['total']);
        $this->assertSame(['Sam Staff'], $this->names($this->listTeam(['status' => 'inactive', 'role' => 'staff'])));
        $this->assertSame([], $this->names($this->listTeam(['status' => 'inactive', 'role' => 'cashier'])));

        $this->call($this->desk($this->admin), 'POST', $this->url(), ['full_name' => 'Pending Pia', 'email' => 'pia@team-a.test', 'role' => 'cashier']);
        $this->assertSame(['Pending Pia'], $this->names($this->listTeam(['status' => 'pending'])));

        // Search: name or email, case-insensitive, and % and _ mean themselves.
        $this->assertSame(['Casey Cashier'], $this->names($this->listTeam(['q' => 'CASEY'])));
        $this->assertSame(['Pat 3'], $this->names($this->listTeam(['q' => 'pat3@'])));
        $this->assertSame(['Pat 3'], $this->names($this->listTeam(['q' => 'pat 3'])));
        $this->assertSame([], $this->names($this->listTeam(['q' => '%'])));
        $this->assertSame([], $this->names($this->listTeam(['q' => 'olive'])), 'search never reaches another organization');
    }

    public function testTheListPageRefusesAnUnknownFilterOrSortWithAFieldError(): void
    {
        $desk = $this->desk($this->admin);

        $role = $this->call($desk, 'GET', $this->url(), query: ['role' => 'wizard']);
        $this->assertSame(422, $role->status());
        $this->assertStringContainsString('Choose Admin, Manager, Staff or Cashier.', $role->body());

        $status = $this->call($desk, 'GET', $this->url(), query: ['status' => 'maybe']);
        $this->assertSame(422, $status->status());
        $this->assertStringContainsString('Choose active, inactive or pending.', $status->body());

        $this->assertSame(422, $this->call($desk, 'GET', $this->url(), query: ['sort' => 'password_hash'])->status());
        $this->assertSame(422, $this->call($desk, 'GET', $this->url(), query: ['page' => '0'])->status());
    }

    public function testTheListServiceMethodsRefuseAnyoneWhoIsNotAnAdmin(): void
    {
        $query = ListQuery::fromRequest(new Request('GET', $this->url()), AccountService::TEAM_SORTS, 'name', ['role', 'status']);
        foreach ([$this->manager, $this->staff, $this->cashier] as $actor) {
            $accounts = $this->desk($actor)['services']->accounts();
            $this->assertHttpStatus(403, fn () => $accounts->listTeam($actor, $this->org1, $query));
            $this->assertHttpStatus(403, fn () => $accounts->teamMember($actor, $this->org1, (string) $this->staff->id));
            $this->assertHttpStatus(403, fn () => $accounts->updateTeamMember($actor, $this->org1, (string) $this->staff->id, 'X Y', 'xy@team-a.test', 'cashier'));
        }

        // An Admin of the other organization has no standing here.
        $otherAdmin = $this->person($this->org2, 'admin@team-b.test', 'admin', 'Other Admin');
        $accounts = $this->desk($otherAdmin)['services']->accounts();
        $this->assertHttpStatus(403, fn () => $accounts->listTeam($otherAdmin, $this->org1, $query));
        $this->assertHttpStatus(403, fn () => $accounts->teamMember($otherAdmin, $this->org1, (string) $this->staff->id));
    }

    // ------------------------------------------------------------------ polish c and d (Reports)

    public function testAnEmptyPeriodsCostBadgeSaysNoCostRecordedNotADash(): void
    {
        $desk = $this->desk($this->admin);
        $empty = $this->call($desk, 'GET', '/' . $this->org1->slug . '/admin/reports', query: ['view' => 'year', 'year' => '2025'])->body();

        $this->assertStringContainsString('No cost recorded', $empty);
        $this->assertStringNotContainsString('Cost known for', $empty);
        $this->assertStringNotContainsString('Cost known for —', $empty);

        // With sales the badge still reports how much of the cost is known.
        $this->shop->steps();
        $variant = $this->shop->variant(['sku' => 'COST-1', 'price' => '112.00']);
        $branch = $this->shop->branch('N1', true, false);
        $terminal = $this->shop->terminal($branch, 'TN1');
        $shift = $this->shop->shift($terminal, '1000.00');
        $this->shop->stock($branch, $variant, '10', '50.00');
        $sale = $this->shop->services->pos()->syncSale([
            'id' => Uuid::v4(),
            'receipt_number' => 'TN1-000001',
            'terminal_id' => $terminal,
            'shift_id' => $shift,
            'sold_at' => '2026-03-14T10:00:00+08:00',
            'lines' => [['variant_id' => $variant, 'quantity' => '1', 'unit_price' => '112.00']],
            'payments' => [['method' => 'cash', 'amount' => Decimal::format(11200, 2)]],
        ]);
        $this->assertSame('completed', $sale['status']);

        $busy = $this->call($desk, 'GET', '/' . $this->org1->slug . '/admin/reports', query: ['view' => 'day', 'date' => '2026-03-14'])->body();
        $this->assertStringContainsString('Cost known for 100.00% of sales', $busy);
        $this->assertStringNotContainsString('No cost recorded', $busy);
    }

    public function testADateThatDoesNotExistGetsItsOwnMessageAndTheExampleComesFromTodayInManila(): void
    {
        $reports = $this->shop->services->reports();
        $manila = new \DateTimeZone('Asia/Manila');
        $now = new \DateTimeImmutable('2031-07-04 10:00:00', $manila);
        $message = static function (callable $call, string $field): string {
            try {
                $call();
            } catch (HttpException $exception) {
                return (string) ($exception->fields()[$field] ?? '');
            }

            return '';
        };

        // Right shape, no such day or month.
        $impossibleDay = $message(fn () => $reports->periodReport('day', '2026-02-30', $now), 'date');
        $this->assertStringContainsString('does not exist', $impossibleDay);
        $this->assertStringNotContainsString('YYYY-MM-DD', $impossibleDay, 'the format was right, so the message is not about the format');
        $this->assertStringContainsString('2031-07-04', $impossibleDay);
        $this->assertStringContainsString('does not exist', $message(fn () => $reports->periodReport('day', '2026-13-01', $now), 'date'));
        $this->assertStringContainsString('does not exist', $message(fn () => $reports->periodReport('month', '2026-13', $now), 'month'));
        $this->assertStringContainsString('does not exist', $message(fn () => $reports->periodReport('day', '2026-04-31', $now), 'date'));

        // Wrong shape: the format message, with an example built from today (not the old fixed 2026-11-16).
        $badDay = $message(fn () => $reports->periodReport('day', '16/11/2026', $now), 'date');
        $this->assertStringContainsString('YYYY-MM-DD', $badDay);
        $this->assertStringContainsString('for example 2031-07-04', $badDay);
        $this->assertStringNotContainsString('2026-11-16', $badDay);
        $this->assertStringContainsString('for example 2031-07', $message(fn () => $reports->periodReport('month', 'July 2031', $now), 'month'));
        $this->assertStringContainsString('for example 2031', $message(fn () => $reports->periodReport('year', 'next year', $now), 'year'));

        // The example follows Manila, not UTC: 17:00 UTC on 3 July is already 4 July in Manila.
        $utcEvening = new \DateTimeImmutable('2031-07-03 17:00:00', new \DateTimeZone('UTC'));
        $this->assertStringContainsString('for example 2031-07-04', $message(fn () => $reports->periodReport('day', 'x', $utcEvening), 'date'));

        // Valid values are unaffected.
        $this->assertSame('2026-02-28', $reports->periodReport('day', '2026-02-28', $now)['value']);

        // On the page.
        $page = $this->call($this->desk($this->admin), 'GET', '/' . $this->org1->slug . '/admin/reports', query: ['view' => 'day', 'date' => '2026-02-30']);
        $this->assertSame(422, $page->status());
        $this->assertStringContainsString('That date does not exist in the calendar.', $page->body());
        $this->assertStringNotContainsString('Enter a date as YYYY-MM-DD', $page->body());
    }
}
