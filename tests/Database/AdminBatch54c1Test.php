<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Repository\BranchRepository;
use Gfh\Core\Repository\OrderRepository;
use Gfh\Core\Repository\TerminalRepository;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Service\BranchService;
use Gfh\Core\Service\TerminalService;
use Gfh\Core\Support\SettingsSections;
use Gfh\Core\Support\Services;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Organization;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;

/**
 * Batch 5.4c-1: the Settings hub with Branches and Terminals (/{org}/admin/settings).
 *
 * Every request goes through the real App + Router + core/routes.php + middleware stack, so a controller missing from
 * the route group, a write route without CSRF, or a page that skips authorize() is caught here. The service tests build
 * the services directly, so a caller that skips the controller is checked too.
 */
final class AdminBatch54c1Test extends DatabaseTestCase
{
    private App $app;
    private Organization $org1;
    private Organization $org2;
    private StaffUser $admin;
    private StaffUser $manager;
    private StaffUser $staff;
    private StaffUser $cashier;
    private StaffUser $outsider;
    private StaffUser $superAdmin;
    /** @var array{services: Services, session: Session} */
    private array $adminDesk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 2));

        $this->org1 = $this->kit->organization('set-a-' . bin2hex(random_bytes(3)));
        $this->org2 = $this->kit->organization('set-b-' . bin2hex(random_bytes(3)));
        $this->admin = $this->person($this->org1, 'admin@set-a.test', 'admin', 'Alma Admin');
        $this->manager = $this->person($this->org1, 'manager@set-a.test', 'manager', 'Mara Manager');
        $this->staff = $this->person($this->org1, 'sam@set-a.test', 'staff', 'Sam Staff');
        $this->cashier = $this->person($this->org1, 'casey@set-a.test', 'cashier', 'Casey Cashier');
        $this->outsider = $this->person($this->org2, 'olive@set-b.test', 'admin', 'Olive Outsider');
        $this->superAdmin = $this->kit->staff('boss@group.test', TestKit::PASSWORD, 'super_admin', 'active', 'Bea Boss');
        $this->kit->join($this->org1, $this->superAdmin, 'admin');
        $this->adminDesk = $this->desk($this->admin);
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
        $user = $this->kit->staff($email, TestKit::PASSWORD, null, 'active', $name);
        $this->kit->join($organization, $user, $role);

        return $user;
    }

    /** @return array{services: Services, session: Session} */
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
     */
    private function call(array $desk, string $method, string $path, array $post = [], bool $withCsrf = true): Response
    {
        if ($method === 'POST' && $withCsrf) {
            $post['_csrf'] = $desk['services']->csrf($desk['session'])->token();
        }

        return $this->app->handle(new Request($method, $path, post: $post), $desk['services']);
    }

    private function url(string $path = ''): string
    {
        return '/' . $this->org1->slug . '/admin/settings' . $path;
    }

    /** @param array<string, string> $post */
    private function adminPost(string $path, array $post = []): Response
    {
        return $this->call($this->adminDesk, 'POST', $this->url($path), $post);
    }

    private function adminGet(string $path): Response
    {
        return $this->call($this->adminDesk, 'GET', $this->url($path));
    }

    private function branchService(?StaffUser $viewer = null): BranchService
    {
        return new BranchService($this->org, new BranchRepository($this->org), new AuditService($this->core), $this->desk($viewer ?? $this->admin)['services']->permissions());
    }

    private function terminalService(?StaffUser $viewer = null): TerminalService
    {
        return new TerminalService($this->org, new TerminalRepository($this->org), new BranchRepository($this->org), new AuditService($this->core), $this->desk($viewer ?? $this->admin)['services']->permissions());
    }

    /** @return array<string, string> a believable branch form */
    private function branchForm(array $override = []): array
    {
        return $override + [
            'code' => 'NEW-1', 'name' => 'New Branch', 'address' => '1 Main Street', 'city' => 'Bacolod', 'province' => 'Negros Occidental',
            'region' => 'Region VI', 'kind' => 'store', 'is_pickup_point' => '1', 'is_delivery_origin' => '',
        ];
    }

    /** @return array<string, mixed> */
    private function branchRow(string $id): array
    {
        $statement = $this->org->prepare('SELECT * FROM branches WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        $this->assertIsArray($row, 'the branch exists');

        return $row;
    }

    /** @return array<string, mixed> */
    private function terminalRow(string $id): array
    {
        $statement = $this->org->prepare('SELECT * FROM terminals WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        $this->assertIsArray($row, 'the terminal exists');

        return $row;
    }

    private function branchIdByCode(string $code): string
    {
        $statement = $this->org->prepare('SELECT id FROM branches WHERE code = ?');
        $statement->execute([$code]);

        return (string) $statement->fetchColumn();
    }

    private function deliveryCount(): int
    {
        return (int) $this->org->query('SELECT COUNT(*) FROM branches WHERE is_delivery_origin')->fetchColumn();
    }

    private function state(): string
    {
        return json_encode([
            $this->org->query('SELECT id, code, name, address, city, province, region, kind, is_pickup_point, is_delivery_origin, status FROM branches ORDER BY id')->fetchAll(),
            $this->org->query('SELECT id, branch_id, code, name, status, last_seen_at FROM terminals ORDER BY id')->fetchAll(),
            (int) $this->org->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(),
        ], JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function audit(string $action): array
    {
        $statement = $this->org->prepare('SELECT action, actor_user_id, entity, entity_id, detail FROM audit_log WHERE action = ?');
        $statement->execute([$action]);

        return $statement->fetchAll();
    }

    /** An order of this organization. $progress is a step key; a cancelled order is stamped cancelled. */
    private function order(?string $pickupBranch, ?string $shipFrom, string $progress = 'placed', bool $cancelled = false): string
    {
        $this->shop->steps();
        $customer = $this->shop->customer('buyer' . bin2hex(random_bytes(4)) . '@example.test');
        $statement = $this->org->prepare(
            'INSERT INTO orders (order_number, customer_id, progress_key, fulfillment_method, pickup_branch_id, ship_from_branch_id, subtotal, vat_amount, total, cancelled_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, 112.00, 12.00, 112.00, ' . ($cancelled ? 'now()' : 'NULL') . ') RETURNING id',
        );
        $statement->execute(['SO-T-' . bin2hex(random_bytes(4)), $customer['id'], $progress, $pickupBranch !== null ? 'pickup' : 'delivery', $pickupBranch, $shipFrom]);

        return (string) $statement->fetchColumn();
    }

    /** @return list<array{0: string, 1: string, 2: array<string, string>}> every Settings route with a believable payload */
    private function everyRequest(string $branchId, string $terminalId): array
    {
        $branch = $this->branchForm(['code' => 'ZZ-9']);
        $terminal = ['branch_id' => $branchId, 'code' => 'Z9', 'name' => 'Zed Nine'];

        return [
            ['GET', $this->url(), []],
            ['GET', $this->url('/branches'), []],
            ['GET', $this->url('/branches/new'), []],
            ['GET', $this->url('/branches/' . $branchId), []],
            ['GET', $this->url('/terminals'), []],
            ['GET', $this->url('/terminals/new'), []],
            ['GET', $this->url('/terminals/' . $terminalId), []],
            ['POST', $this->url('/branches'), $branch],
            ['POST', $this->url('/branches/' . $branchId), $branch],
            ['POST', $this->url('/branches/' . $branchId . '/deactivate'), []],
            ['POST', $this->url('/branches/' . $branchId . '/reactivate'), []],
            ['POST', $this->url('/terminals'), $terminal],
            ['POST', $this->url('/terminals/' . $terminalId), ['name' => 'Renamed']],
            ['POST', $this->url('/terminals/' . $terminalId . '/deactivate'), []],
            ['POST', $this->url('/terminals/' . $terminalId . '/reactivate'), []],
        ];
    }

    // ------------------------------------------------------------------ permissions

    public function testManagerStaffCashierSuperAdminAndOutsiderAreRefusedOnEveryPageAndPost(): void
    {
        $branchId = $this->shop->branch('KEEP-1', true, false);
        $terminalId = $this->shop->terminal($branchId, 'K1');
        $before = $this->state();

        foreach (['manager' => $this->manager, 'staff' => $this->staff, 'cashier' => $this->cashier, 'super admin' => $this->superAdmin, 'other organization' => $this->outsider] as $who => $actor) {
            $desk = $this->desk($actor);
            foreach ($this->everyRequest($branchId, $terminalId) as [$method, $path, $post]) {
                $response = $this->call($desk, $method, $path, $post);
                $this->assertSame(403, $response->status(), "$who: $method $path must be refused");
                $this->assertStringNotContainsString('Branch KEEP-1', $response->body(), "$who: $method $path must not leak branches");
            }
        }

        $this->assertSame($before, $this->state(), 'a refused request changes nothing and writes no audit entry');
    }

    public function testOnlyAnAdminOpensTheHubAndTheSidebarSettingsLinkOpensIt(): void
    {
        $dashboard = $this->adminGet('');
        $this->assertSame(200, $dashboard->status());

        $home = $this->call($this->adminDesk, 'GET', '/' . $this->org1->slug . '/admin');
        $this->assertSame(200, $home->status());
        $this->assertStringContainsString('href="' . $this->url() . '"', $home->body(), 'the sidebar Settings item points at the hub');

        $this->assertStringNotContainsString('href="' . $this->url() . '"', $this->call($this->desk($this->manager), 'GET', '/' . $this->org1->slug . '/admin')->body(), 'a Manager is not shown the link');
    }

    public function testSignedOutVisitorsAreRedirectedToSignInAndSignedOutPostsChangeNothing(): void
    {
        $branchId = $this->shop->branch('KEEP-1', true, false);
        $terminalId = $this->shop->terminal($branchId, 'K1');
        $before = $this->state();
        $desk = $this->desk(null);

        foreach ($this->everyRequest($branchId, $terminalId) as [$method, $path, $post]) {
            $response = $this->call($desk, $method, $path, $post);
            $this->assertSame(302, $response->status(), "$method $path");
            $this->assertStringContainsString('/admin/login', (string) $response->header('Location'), "$method $path");
        }
        foreach ($this->everyRequest($branchId, $terminalId) as [$method, $path, $post]) {
            if ($method === 'POST') {
                $this->assertSame(403, $this->call($desk, $method, $path, $post, withCsrf: false)->status(), "$method $path");
            }
        }

        $this->assertSame($before, $this->state());
    }

    public function testEveryWriteRefusesAMissingOrWrongCsrfTokenAndChangesNothing(): void
    {
        $branchId = $this->shop->branch('KEEP-1', true, false);
        $terminalId = $this->shop->terminal($branchId, 'K1');
        $before = $this->state();

        foreach ($this->everyRequest($branchId, $terminalId) as [$method, $path, $post]) {
            if ($method !== 'POST') {
                continue;
            }
            $this->assertSame(403, $this->call($this->adminDesk, $method, $path, $post, withCsrf: false)->status(), "no token: $path");
            $this->assertSame(403, $this->call($this->adminDesk, $method, $path, $post + ['_csrf' => 'not-the-token'], withCsrf: false)->status(), "wrong token: $path");
        }

        $this->assertSame($before, $this->state());
    }

    public function testTheServiceMethodsRefuseAnyoneWhoIsNotAnAdminEvenWhenTheControllerIsBypassed(): void
    {
        $branchId = $this->shop->branch('KEEP-1', true, false);
        $terminalId = $this->shop->terminal($branchId, 'K1');
        $before = $this->state();

        foreach ([$this->manager, $this->staff, $this->cashier, $this->superAdmin, $this->outsider] as $actor) {
            $branches = $this->branchService($actor);
            $terminals = $this->terminalService($actor);
            $org = $this->org1;

            $this->assertHttpStatus(403, fn () => $branches->list($actor, $org));
            $this->assertHttpStatus(403, fn () => $branches->get($actor, $org, $branchId));
            $this->assertHttpStatus(403, fn () => $branches->create($actor, $org, $this->branchForm()));
            $this->assertHttpStatus(403, fn () => $branches->update($actor, $org, $branchId, $this->branchForm()));
            $this->assertHttpStatus(403, fn () => $branches->deactivate($actor, $org, $branchId));
            $this->assertHttpStatus(403, fn () => $branches->reactivate($actor, $org, $branchId));
            $this->assertHttpStatus(403, fn () => $terminals->list($actor, $org));
            $this->assertHttpStatus(403, fn () => $terminals->get($actor, $org, $terminalId));
            $this->assertHttpStatus(403, fn () => $terminals->branchOptions($actor, $org));
            $this->assertHttpStatus(403, fn () => $terminals->create($actor, $org, ['branch_id' => $branchId, 'code' => 'Z9', 'name' => 'Zed']));
            $this->assertHttpStatus(403, fn () => $terminals->update($actor, $org, $terminalId, ['name' => 'Renamed']));
            $this->assertHttpStatus(403, fn () => $terminals->deactivate($actor, $org, $terminalId));
            $this->assertHttpStatus(403, fn () => $terminals->reactivate($actor, $org, $terminalId));
        }

        $this->assertSame($before, $this->state());
    }

    // ------------------------------------------------------------------ the hub

    public function testTheHubListsEveryRegisteredSectionWithADescriptionAndALink(): void
    {
        $response = $this->adminGet('');
        $this->assertSame(200, $response->status());
        $body = $response->body();

        $this->assertCount(5, SettingsSections::ALL, 'Branches, Terminals, Discount rules, Order steps and Organization settings are the five sections after 5.4c-2');
        foreach (SettingsSections::ALL as $section) {
            $this->assertStringContainsString('>' . $section['label'] . '<', $body);
            $this->assertStringContainsString(htmlspecialchars($section['description'], ENT_QUOTES), $body);
            $this->assertStringContainsString('href="' . $this->url(substr($section['path'], strlen('/settings'))) . '"', $body);
        }
    }

    // ------------------------------------------------------------------ branches: create, edit, list

    public function testAnAdminCreatesABranchAndItAppearsInTheListWithEscapedOutput(): void
    {
        $response = $this->adminPost('/branches', $this->branchForm(['code' => 'BCD-MAIN', 'name' => '<script>alert(1)</script> Main', 'is_delivery_origin' => '1']));
        $this->assertSame(302, $response->status());

        $id = $this->branchIdByCode('BCD-MAIN');
        $this->assertStringEndsWith('/settings/branches/' . $id, (string) $response->header('Location'));
        $row = $this->branchRow($id);
        $this->assertSame('<script>alert(1)</script> Main', $row['name']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('store', $row['kind']);
        $this->assertTrue($row['is_pickup_point']);
        $this->assertTrue($row['is_delivery_origin']);
        $this->assertSame('Region VI', $row['region']);

        $list = $this->adminGet('/branches')->body();
        $this->assertStringContainsString('BCD-MAIN', $list);
        $this->assertStringNotContainsString('<script>alert(1)', $list);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Main', $list);
        $this->assertStringContainsString('Delivery branch', $list);
        $this->assertStringContainsString('Pickup point', $list);
    }

    public function testTheListShowsNameCodeKindFlagsStatusAndTheNumberOfActiveTerminals(): void
    {
        $main = $this->shop->branch('BCD-MAIN', true, true);
        $wh = $this->shop->branch('TLS-WH', false, false);
        $this->org->prepare("UPDATE branches SET kind = 'warehouse' WHERE id = ?")->execute([$wh]);
        $this->shop->terminal($main, 'T1');
        $this->shop->terminal($main, 'T2');
        $off = $this->shop->terminal($main, 'T3');
        $this->org->prepare("UPDATE terminals SET status = 'inactive' WHERE id = ?")->execute([$off]);

        $rows = $this->branchService()->list($this->admin, $this->org1);
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[(string) $row['code']] = $row;
        }
        $this->assertSame(2, (int) $byCode['BCD-MAIN']['active_terminals'], 'inactive terminals are not counted');
        $this->assertSame(0, (int) $byCode['TLS-WH']['active_terminals']);

        $body = $this->adminGet('/branches')->body();
        foreach (['Name', 'Code', 'Kind', 'Flags', 'Status', 'Active terminals', 'Warehouse', 'Store', 'Pickup point', 'Delivery branch', 'Active'] as $text) {
            $this->assertStringContainsString($text, $body);
        }
    }

    public function testBranchCreateValidatesEveryFieldWithItsOwnMessage(): void
    {
        $before = $this->state();
        $cases = [
            'code too short' => [['code' => 'A'], 'code', 'Enter a code of 2 to 12 characters'],
            'code too long' => [['code' => 'ABCDEFGHIJKLM'], 'code', 'Enter a code of 2 to 12 characters'],
            'code lower case' => [['code' => 'bcd-main'], 'code', 'capital letters, digits or hyphens'],
            'code with a space' => [['code' => 'BCD MAIN'], 'code', 'capital letters, digits or hyphens'],
            'code with an underscore' => [['code' => 'BCD_MAIN'], 'code', 'capital letters, digits or hyphens'],
            'code empty' => [['code' => ''], 'code', 'Enter a code of 2 to 12 characters'],
            'name empty' => [['name' => '   '], 'name', 'Enter the branch name (up to 120 characters).'],
            'name too long' => [['name' => str_repeat('n', 121)], 'name', 'Enter the branch name (up to 120 characters).'],
            'address too long' => [['address' => str_repeat('a', 201)], 'address', 'Use at most 200 characters'],
            'city too long' => [['city' => str_repeat('c', 201)], 'city', 'Use at most 200 characters'],
            'province too long' => [['province' => str_repeat('p', 201)], 'province', 'Use at most 200 characters'],
            'region too long' => [['region' => str_repeat('r', 201)], 'region', 'Use at most 200 characters'],
            'kind unknown' => [['kind' => 'kiosk'], 'kind', 'Choose store or warehouse.'],
            'kind empty' => [['kind' => ''], 'kind', 'Choose store or warehouse.'],
        ];

        foreach ($cases as $label => [$override, $field, $message]) {
            $response = $this->adminPost('/branches', $this->branchForm($override));
            $this->assertSame(422, $response->status(), $label);
            $this->assertStringContainsString($message, $response->body(), $label);
            $this->assertStringContainsString('aria-invalid="true"', $response->body(), $label);
            $service = $this->branchService();
            $exception = $this->assertHttpStatus(422, fn () => $service->create($this->admin, $this->org1, $this->branchForm($override)));
            $this->assertArrayHasKey($field, $exception->fields(), $label);
        }

        $this->assertSame($before, $this->state(), 'no refused form saved anything');

        // The limits themselves are accepted: 2 and 12 character codes, a 120 character name, 200 character optional text.
        $this->assertSame(302, $this->adminPost('/branches', $this->branchForm(['code' => 'AB', 'name' => str_repeat('n', 120), 'address' => str_repeat('a', 200)]))->status());
        $this->assertSame(302, $this->adminPost('/branches', $this->branchForm(['code' => 'ABCDEFGHIJ-K', 'address' => '', 'city' => '', 'province' => '', 'region' => '']))->status());
        $this->assertNull($this->branchRow($this->branchIdByCode('ABCDEFGHIJ-K'))['address'], 'an empty optional field is stored as null');
    }

    public function testADuplicateBranchCodeIsRefusedAndNothingIsAdded(): void
    {
        $this->assertSame(302, $this->adminPost('/branches', $this->branchForm(['code' => 'DUP-1']))->status());
        $before = $this->state();

        $response = $this->adminPost('/branches', $this->branchForm(['code' => 'DUP-1', 'name' => 'Second']));
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('This code is already used by another branch.', $response->body());
        $this->assertSame($before, $this->state());
    }

    public function testAnAdminEditsABranchAndTheCodeIsShownReadOnly(): void
    {
        $id = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'EDIT-1']))['id'];

        $page = $this->adminGet('/branches/' . $id)->body();
        $this->assertMatchesRegularExpression('/<input[^>]*name="code"[^>]*value="EDIT-1"[^>]*disabled/s', $page, 'the code is a disabled input, so a browser never posts it');

        $form = $this->branchForm(['name' => 'Renamed Branch', 'city' => 'Silay', 'kind' => 'warehouse', 'is_pickup_point' => '', 'address' => '']);
        unset($form['code']);
        $this->assertSame(302, $this->adminPost('/branches/' . $id, $form)->status());

        $row = $this->branchRow($id);
        $this->assertSame('Renamed Branch', $row['name']);
        $this->assertSame('Silay', $row['city']);
        $this->assertSame('warehouse', $row['kind']);
        $this->assertFalse($row['is_pickup_point']);
        $this->assertNull($row['address']);
        $this->assertSame('EDIT-1', $row['code']);

        $this->assertStringContainsString('Renamed Branch', $this->adminGet('/branches/' . $id)->body());
    }

    public function testBranchEditShowsEveryValidationMessageAndKeepsWhatWasTyped(): void
    {
        $id = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'EDIT-1']))['id'];
        $before = $this->state();

        $form = $this->branchForm(['name' => '', 'kind' => 'kiosk', 'city' => str_repeat('c', 201)]);
        unset($form['code']);
        $response = $this->adminPost('/branches/' . $id, $form);

        $this->assertSame(422, $response->status());
        foreach (['Enter the branch name (up to 120 characters).', 'Choose store or warehouse.', 'Use at most 200 characters'] as $message) {
            $this->assertStringContainsString($message, $response->body());
        }
        $this->assertSame($before, $this->state());
    }

    public function testSavingABranchWithNoChangeWritesNoAuditEntry(): void
    {
        $id = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'SAME-1']))['id'];
        $form = $this->branchForm();
        unset($form['code']);

        $this->assertSame(302, $this->adminPost('/branches/' . $id, $form)->status());
        $this->assertCount(0, $this->audit('branch.updated'));
    }

    // ------------------------------------------------------------------ branches: tampering

    public function testATamperedCodeKindStatusOrBranchIdIsRefusedOrIgnoredAndNothingIsSaved(): void
    {
        $id = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'REAL-1']))['id'];
        $other = $this->shop->branch('OTHER-1', true, false);
        $before = $this->state();

        // A different code is refused for the whole save.
        $form = $this->branchForm(['code' => 'HACKED', 'name' => 'Changed By Tamper']);
        $response = $this->adminPost('/branches/' . $id, $form);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('The code cannot be changed after the branch is created.', $response->body());
        $this->assertSame($before, $this->state(), 'the tampered code saved nothing, not even the name');

        // A code posted as an array is a different value too.
        $this->assertSame(422, $this->call($this->adminDesk, 'POST', $this->url('/branches/' . $id), ['code' => ['REAL-1']] + $this->branchForm(['name' => 'Changed']) + ['_csrf' => $this->adminDesk['services']->csrf($this->adminDesk['session'])->token()], false)->status());
        $this->assertSame($before, $this->state());

        // An unknown kind is refused.
        $form = $this->branchForm(['kind' => 'castle']);
        unset($form['code']);
        $this->assertSame(422, $this->adminPost('/branches/' . $id, $form)->status());
        $this->assertSame($before, $this->state());

        // A posted status and a posted id are never read.
        $form = $this->branchForm(['name' => 'Honest Rename', 'status' => 'inactive', 'id' => $other]);
        unset($form['code']);
        $this->assertSame(302, $this->adminPost('/branches/' . $id, $form)->status());
        $row = $this->branchRow($id);
        $this->assertSame('active', $row['status'], 'status changes only through deactivate and reactivate');
        $this->assertSame('Honest Rename', $row['name']);
        $this->assertSame('OTHER-1', $this->branchRow($other)['code']);
        $this->assertSame('Branch OTHER-1', $this->branchRow($other)['name'], 'another branch id in the body changed nothing');

        // A status posted on create is ignored: the branch starts active.
        $this->assertSame(302, $this->adminPost('/branches', $this->branchForm(['code' => 'NEW-2', 'status' => 'inactive']))->status());
        $this->assertSame('active', $this->branchRow($this->branchIdByCode('NEW-2'))['status']);

        // A malformed id in the URL is a 404, an unknown one too, and neither changes anything.
        $this->assertSame(404, $this->adminPost('/branches/not-a-uuid', $this->branchForm())->status());
        $this->assertSame(404, $this->adminPost('/branches/' . Uuid::v4(), $this->branchForm())->status());
        $this->assertSame(404, $this->adminGet('/branches/' . Uuid::v4())->status());
    }

    // ------------------------------------------------------------------ branches: the delivery branch

    public function testMakingABranchTheDeliveryBranchMovesTheDesignationAtomically(): void
    {
        $a = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'DEL-A', 'is_delivery_origin' => '1']))['id'];
        $this->assertSame(1, $this->deliveryCount());

        $b = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'DEL-B']))['id'];
        $form = $this->branchForm(['is_delivery_origin' => '1']);
        unset($form['code']);
        $this->assertSame(302, $this->adminPost('/branches/' . $b, $form)->status());

        $this->assertTrue($this->branchRow($b)['is_delivery_origin']);
        $this->assertFalse($this->branchRow($a)['is_delivery_origin']);
        $this->assertSame(1, $this->deliveryCount(), 'there is never more than one delivery branch');

        // Creating a new branch with the box ticked moves it as well.
        $c = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'DEL-C', 'is_delivery_origin' => '1']))['id'];
        $this->assertTrue($this->branchRow($c)['is_delivery_origin']);
        $this->assertFalse($this->branchRow($b)['is_delivery_origin']);
        $this->assertSame(1, $this->deliveryCount());

        $moves = $this->audit('branch.delivery_origin_changed');
        $this->assertCount(3, $moves, 'the first designation and the two moves');
        $detail = array_map(static fn (array $row): array => json_decode((string) $row['detail'], true), $moves);
        $this->assertContainsEquals(['from_branch_id' => $a, 'to_branch_id' => $b], $detail);
        $this->assertContainsEquals(['from_branch_id' => $b, 'to_branch_id' => $c], $detail);

        // The online checkout still ships from the one active delivery branch.
        $this->assertSame($c, (new OrderRepository($this->org))->deliveryOriginId());
    }

    public function testAFailedMoveLeavesTheOldDeliveryBranchInPlace(): void
    {
        $a = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'DEL-A', 'is_delivery_origin' => '1']))['id'];
        $b = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'DEL-B']))['id'];

        // Ticking delivery together with an invalid name must not take the designation away from A.
        $form = $this->branchForm(['name' => '', 'is_delivery_origin' => '1']);
        unset($form['code']);
        $this->assertSame(422, $this->adminPost('/branches/' . $b, $form)->status());

        $this->assertTrue($this->branchRow($a)['is_delivery_origin']);
        $this->assertFalse($this->branchRow($b)['is_delivery_origin']);
        $this->assertSame(1, $this->deliveryCount());
    }

    public function testUntickingTheCurrentDeliveryBranchIsRefusedWithAClearMessage(): void
    {
        $a = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'DEL-A', 'is_delivery_origin' => '1']))['id'];
        $before = $this->state();

        $form = $this->branchForm(['name' => 'Renamed', 'is_delivery_origin' => '']);
        unset($form['code']);
        $response = $this->adminPost('/branches/' . $a, $form);

        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('This is the delivery branch. To change it, tick Delivery branch on the branch that should take over instead.', $response->body());
        $this->assertSame($before, $this->state(), 'the refused save changed nothing, not even the name');
        $this->assertSame(1, $this->deliveryCount());
    }

    public function testAnInactiveBranchCannotBecomeTheDeliveryBranchOrAPickupPoint(): void
    {
        $id = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'OFF-1', 'is_pickup_point' => '']))['id'];
        $this->assertSame(302, $this->adminPost('/branches/' . $id . '/deactivate')->status());
        $before = $this->state();

        $form = $this->branchForm(['is_delivery_origin' => '1', 'is_pickup_point' => '1']);
        unset($form['code']);
        $response = $this->adminPost('/branches/' . $id, $form);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('Reactivate this branch before making it the delivery branch.', $response->body());
        $this->assertStringContainsString('Reactivate this branch before making it a pickup point.', $response->body());
        $this->assertSame($before, $this->state());

        // An inactive branch can still be renamed, and once reactivated it may take both flags.
        $form = $this->branchForm(['name' => 'Renamed While Off', 'is_pickup_point' => '']);
        unset($form['code']);
        $this->assertSame(302, $this->adminPost('/branches/' . $id, $form)->status());
        $this->assertSame(302, $this->adminPost('/branches/' . $id . '/reactivate')->status());
        $form = $this->branchForm(['is_delivery_origin' => '1', 'is_pickup_point' => '1', 'name' => 'Renamed While Off']);
        unset($form['code']);
        $this->assertSame(302, $this->adminPost('/branches/' . $id, $form)->status());
        $this->assertTrue($this->branchRow($id)['is_delivery_origin']);
        $this->assertTrue($this->branchRow($id)['is_pickup_point']);
    }

    // ------------------------------------------------------------------ branches: deactivation

    public function testDeactivationIsRefusedForTheDeliveryBranch(): void
    {
        $id = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'DEL-A', 'is_delivery_origin' => '1']))['id'];
        $before = $this->state();

        $response = $this->adminPost('/branches/' . $id . '/deactivate');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('It is the delivery branch. Make another branch the delivery branch first.', $response->body());
        $this->assertSame('active', $this->branchRow($id)['status']);
        $this->assertSame($before, $this->state());
    }

    public function testDeactivationIsRefusedWhileTheBranchHasAnActiveTerminal(): void
    {
        $id = $this->shop->branch('TERM-1', true, false);
        $terminal = $this->shop->terminal($id, 'T1');
        $before = $this->state();

        $response = $this->adminPost('/branches/' . $id . '/deactivate');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('It still has 1 active terminal. Deactivate it first.', $response->body());
        $this->assertSame($before, $this->state());

        // An inactive terminal does not block it.
        $this->org->prepare("UPDATE terminals SET status = 'inactive' WHERE id = ?")->execute([$terminal]);
        $this->assertSame(302, $this->adminPost('/branches/' . $id . '/deactivate')->status());
    }

    public function testDeactivationIsRefusedWhileTheBranchHoldsStockOnHand(): void
    {
        $id = $this->shop->branch('STOCK-1', true, false);
        $variant = $this->shop->variant();
        $this->shop->stock($id, $variant, '5', '10.00');
        $before = $this->state();

        $response = $this->adminPost('/branches/' . $id . '/deactivate');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('It still holds stock on hand (1 product). Move it out or write it off first.', $response->body());
        $this->assertSame($before, $this->state());

        // Once the stock is written off to zero the branch can be deactivated: zero on hand is not stock on hand.
        $this->shop->services->inventory()->writeOff($id, $variant, '5', 'Counted out for the test', Uuid::v4());
        $this->assertSame(302, $this->adminPost('/branches/' . $id . '/deactivate')->status());
    }

    public function testDeactivationIsRefusedForTheBranchOfAnOpenPickupOrder(): void
    {
        $id = $this->shop->branch('PICK-1', true, false);
        $order = $this->order($id, null);
        $before = $this->state();

        $response = $this->adminPost('/branches/' . $id . '/deactivate');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('1 open order still uses it for pickup or shipping. Finish or cancel it first.', $response->body());
        $this->assertSame($before, $this->state());

        // A finished order does not block it.
        $this->org->prepare("UPDATE orders SET progress_key = 'completed' WHERE id = ?")->execute([$order]);
        $this->assertSame(302, $this->adminPost('/branches/' . $id . '/deactivate')->status());
    }

    public function testDeactivationIsRefusedForTheShipFromBranchOfAnOpenOrder(): void
    {
        $id = $this->shop->branch('SHIP-1', false, false);
        $order = $this->order(null, $id);
        $before = $this->state();

        $response = $this->adminPost('/branches/' . $id . '/deactivate');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('1 open order still uses it for pickup or shipping', $response->body());
        $this->assertSame($before, $this->state());

        // A cancelled order does not block it.
        $this->org->prepare('UPDATE orders SET cancelled_at = now() WHERE id = ?')->execute([$order]);
        $this->assertSame(302, $this->adminPost('/branches/' . $id . '/deactivate')->status());
    }

    public function testEveryReasonIsListedWhenSeveralApply(): void
    {
        $id = $this->shop->branch('BUSY-1', true, true);
        $this->shop->terminal($id, 'T1');
        $this->shop->stock($id, $this->shop->variant(), '2', '5.00');
        $this->order($id, null);

        $exception = $this->assertHttpStatus(422, fn () => $this->branchService()->deactivate($this->admin, $this->org1, $id));
        $message = $exception->fields()['action'];
        foreach (['delivery branch', 'active terminal', 'stock on hand', 'open order'] as $reason) {
            $this->assertStringContainsString($reason, $message);
        }
    }

    public function testABranchNothingUsesDeactivatesAndReactivatesAndNothingIsDeleted(): void
    {
        $id = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'FREE-1']))['id'];
        $count = (int) $this->org->query('SELECT COUNT(*) FROM branches')->fetchColumn();

        $this->assertSame(302, $this->adminPost('/branches/' . $id . '/deactivate')->status());
        $this->assertSame('inactive', $this->branchRow($id)['status']);
        $this->assertStringContainsString('Inactive', $this->adminGet('/branches')->body());

        // Deactivating again is refused with its own message and is not audited a second time.
        $again = $this->adminPost('/branches/' . $id . '/deactivate');
        $this->assertSame(422, $again->status());
        $this->assertStringContainsString('This branch is already inactive.', $again->body());

        $this->assertSame(302, $this->adminPost('/branches/' . $id . '/reactivate')->status());
        $this->assertSame('active', $this->branchRow($id)['status']);
        $again = $this->adminPost('/branches/' . $id . '/reactivate');
        $this->assertSame(422, $again->status());
        $this->assertStringContainsString('This branch is already active.', $again->body());

        $this->assertSame($count, (int) $this->org->query('SELECT COUNT(*) FROM branches')->fetchColumn(), 'nothing is deleted');
        $this->assertCount(1, $this->audit('branch.deactivated'));
        $this->assertCount(1, $this->audit('branch.reactivated'));
    }

    public function testReactivationIsAlwaysAllowedEvenIfTheBranchHasAnInactiveHistory(): void
    {
        $id = $this->shop->branch('OLD-1', true, false);
        $this->org->prepare("UPDATE branches SET status = 'inactive' WHERE id = ?")->execute([$id]);
        $this->order($id, null); // an open order that points at it, which only matters for deactivation

        $this->assertSame(302, $this->adminPost('/branches/' . $id . '/reactivate')->status());
        $this->assertSame('active', $this->branchRow($id)['status']);
    }

    public function testTheOnlinePickupListAndShipFromStillFollowTheBranchStatus(): void
    {
        $pickup = $this->shop->branch('PICK-A', true, false);
        $other = $this->shop->branch('PICK-B', true, false);
        $repository = new OrderRepository($this->org);
        $names = static fn (array $rows): array => array_map(static fn (array $row): string => (string) $row['code'], $rows);

        $this->assertEqualsCanonicalizing(['PICK-A', 'PICK-B'], $names($repository->pickupBranches()));
        $this->assertSame(302, $this->adminPost('/branches/' . $other . '/deactivate')->status());
        $this->assertSame(['PICK-A'], $names($repository->pickupBranches()), 'the pickup list shows only active pickup points');

        $form = $this->branchForm(['name' => 'Delivery Hub', 'is_pickup_point' => '', 'is_delivery_origin' => '1']);
        unset($form['code']);
        $this->assertSame(302, $this->adminPost('/branches/' . $pickup, $form)->status());
        $this->assertSame($pickup, $repository->deliveryOriginId());
        $this->assertSame([], $repository->pickupBranches());
    }

    // ------------------------------------------------------------------ terminals

    public function testAnAdminCreatesEditsAndListsTerminals(): void
    {
        $branch = $this->shop->branch('BCD-MAIN', true, false);

        $response = $this->adminPost('/terminals', ['branch_id' => $branch, 'code' => 'T3', 'name' => '<b>Front</b> Counter']);
        $this->assertSame(302, $response->status());
        $statement = $this->org->prepare("SELECT id FROM terminals WHERE code = 'T3'");
        $statement->execute();
        $id = (string) $statement->fetchColumn();
        $this->assertStringEndsWith('/settings/terminals/' . $id, (string) $response->header('Location'));

        $row = $this->terminalRow($id);
        $this->assertSame($branch, $row['branch_id']);
        $this->assertSame('active', $row['status']);
        $this->assertNull($row['last_seen_at']);

        $list = $this->adminGet('/terminals')->body();
        foreach (['T3', 'Branch BCD-MAIN', 'Active', 'Never', 'Code', 'Name', 'Branch', 'Status', 'Last seen'] as $text) {
            $this->assertStringContainsString($text, $list);
        }
        $this->assertStringContainsString('&lt;b&gt;Front&lt;/b&gt; Counter', $list);
        $this->assertStringNotContainsString('<b>Front</b>', $list);

        $page = $this->adminGet('/terminals/' . $id)->body();
        $this->assertMatchesRegularExpression('/<input[^>]*name="code"[^>]*value="T3"[^>]*disabled/s', $page, 'the code is read-only');

        $this->assertSame(302, $this->adminPost('/terminals/' . $id, ['name' => 'Front Counter'])->status());
        $this->assertSame('Front Counter', $this->terminalRow($id)['name']);
        $this->assertSame('T3', $this->terminalRow($id)['code']);
        $this->assertCount(1, $this->audit('terminal.updated'));

        // Saving the same name again changes nothing and is not audited.
        $this->assertSame(302, $this->adminPost('/terminals/' . $id, ['name' => 'Front Counter'])->status());
        $this->assertCount(1, $this->audit('terminal.updated'));
    }

    public function testTerminalCreateValidatesEveryFieldAndRefusesADuplicateCode(): void
    {
        $branch = $this->shop->branch('BCD-MAIN', true, false);
        $this->shop->terminal($branch, 'T1');
        $before = $this->state();
        $good = ['branch_id' => $branch, 'code' => 'T9', 'name' => 'Counter Nine'];
        $cases = [
            'branch missing' => [['branch_id' => ''], 'branch_id', 'Choose an active branch.'],
            'branch unknown' => [['branch_id' => Uuid::v4()], 'branch_id', 'Choose an active branch.'],
            'branch malformed' => [['branch_id' => 'nope'], 'branch_id', 'Choose an active branch.'],
            'code empty' => [['code' => ''], 'code', 'Enter a code of 1 to 6 characters'],
            'code too long' => [['code' => 'ABCDEFG'], 'code', 'Enter a code of 1 to 6 characters'],
            'code lower case' => [['code' => 't9'], 'code', 'capital letters and digits only'],
            'code with a hyphen' => [['code' => 'T-9'], 'code', 'capital letters and digits only'],
            'code duplicate' => [['code' => 'T1'], 'code', 'This code is already used by another terminal.'],
            'name empty' => [['name' => ' '], 'name', 'Enter the terminal name (up to 120 characters).'],
            'name too long' => [['name' => str_repeat('n', 121)], 'name', 'Enter the terminal name (up to 120 characters).'],
        ];

        foreach ($cases as $label => [$override, $field, $message]) {
            $response = $this->adminPost('/terminals', $override + $good);
            $this->assertSame(422, $response->status(), $label);
            $this->assertStringContainsString($message, $response->body(), $label);
            $service = $this->terminalService();
            $exception = $this->assertHttpStatus(422, fn () => $service->create($this->admin, $this->org1, $override + $good));
            $this->assertArrayHasKey($field, $exception->fields(), $label);
        }
        $this->assertSame($before, $this->state());

        $this->assertSame(302, $this->adminPost('/terminals', ['code' => 'A', 'name' => str_repeat('n', 120)] + $good)->status(), 'a 1 character code and a 120 character name are accepted');
        $this->assertSame(302, $this->adminPost('/terminals', ['code' => 'ABC123'] + $good)->status(), 'a 6 character code is accepted');
    }

    public function testOnlyActiveBranchesAreOfferedAndAnInactiveBranchIsRefused(): void
    {
        $active = $this->shop->branch('ACT-1', true, false);
        $inactive = $this->shop->branch('OFF-1', true, false);
        $this->org->prepare("UPDATE branches SET status = 'inactive' WHERE id = ?")->execute([$inactive]);

        $page = $this->adminGet('/terminals/new');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('value="' . $active . '"', $page->body());
        $this->assertStringNotContainsString('value="' . $inactive . '"', $page->body());
        $this->assertSame([$active], array_map(static fn (array $row): string => (string) $row['id'], $this->terminalService()->branchOptions($this->admin, $this->org1)));

        $before = $this->state();
        $response = $this->adminPost('/terminals', ['branch_id' => $inactive, 'code' => 'T7', 'name' => 'Off Counter']);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('Choose an active branch.', $response->body());
        $this->assertSame($before, $this->state());
    }

    public function testATerminalCannotChangeBranchOrCodeAndLastSeenIsNeverEdited(): void
    {
        $a = $this->shop->branch('BR-A', true, false);
        $b = $this->shop->branch('BR-B', true, false);
        $id = $this->shop->terminal($a, 'T1');
        $before = $this->state();

        $response = $this->adminPost('/terminals/' . $id, ['name' => 'Moved', 'branch_id' => $b]);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('A terminal cannot move to another branch.', $response->body());
        $this->assertSame($before, $this->state());

        $response = $this->adminPost('/terminals/' . $id, ['name' => 'Recoded', 'code' => 'T2']);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('The code cannot be changed after the terminal is created.', $response->body());
        $this->assertSame($before, $this->state());

        // The same values posted back are fine, and a posted status or last_seen_at is never read.
        $this->assertSame(302, $this->adminPost('/terminals/' . $id, ['name' => 'Same Place', 'branch_id' => $a, 'code' => 'T1', 'status' => 'inactive', 'last_seen_at' => '2020-01-01 00:00:00'])->status());
        $row = $this->terminalRow($id);
        $this->assertSame('Same Place', $row['name']);
        $this->assertSame('active', $row['status']);
        $this->assertNull($row['last_seen_at']);
        $this->assertSame($a, $row['branch_id']);

        // A malformed or unknown id is a 404.
        $this->assertSame(404, $this->adminPost('/terminals/not-a-uuid', ['name' => 'X'])->status());
        $this->assertSame(404, $this->adminGet('/terminals/' . Uuid::v4())->status());
    }

    public function testAnOpenPosShiftBlocksDeactivationUntilTheShiftIsClosed(): void
    {
        $branch = $this->shop->branch('BCD-MAIN', true, false);
        $id = $this->shop->terminal($branch, 'T1');
        $shift = $this->shop->shift($id);
        $before = $this->state();

        $response = $this->adminPost('/terminals/' . $id . '/deactivate');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('This terminal has an open shift.', $response->body());
        $this->assertSame($before, $this->state());

        $this->org->prepare("UPDATE pos_shifts SET status = 'closed', closed_at = now() WHERE id = ?")->execute([$shift]);
        $this->assertSame(302, $this->adminPost('/terminals/' . $id . '/deactivate')->status());
        $this->assertSame('inactive', $this->terminalRow($id)['status']);
    }

    public function testAnInactiveBranchBlocksReactivationOfItsTerminal(): void
    {
        $branch = $this->shop->branch('BCD-MAIN', true, false);
        $id = $this->shop->terminal($branch, 'T1');
        $this->assertSame(302, $this->adminPost('/terminals/' . $id . '/deactivate')->status());
        $this->assertSame(302, $this->adminPost('/branches/' . $branch . '/deactivate')->status());
        $before = $this->state();

        $response = $this->adminPost('/terminals/' . $id . '/reactivate');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('Its branch is inactive. Reactivate the branch first', $response->body());
        $this->assertSame($before, $this->state());

        $this->assertSame(302, $this->adminPost('/branches/' . $branch . '/reactivate')->status());
        $this->assertSame(302, $this->adminPost('/terminals/' . $id . '/reactivate')->status());
        $this->assertSame('active', $this->terminalRow($id)['status']);
    }

    public function testATerminalDeactivatesAndReactivatesAndNothingIsDeleted(): void
    {
        $branch = $this->shop->branch('BCD-MAIN', true, false);
        $id = $this->shop->terminal($branch, 'T1');
        $count = (int) $this->org->query('SELECT COUNT(*) FROM terminals')->fetchColumn();

        $this->assertSame(302, $this->adminPost('/terminals/' . $id . '/deactivate')->status());
        $this->assertSame(422, $this->adminPost('/terminals/' . $id . '/deactivate')->status(), 'already inactive');
        $this->assertSame(302, $this->adminPost('/terminals/' . $id . '/reactivate')->status());
        $this->assertSame(422, $this->adminPost('/terminals/' . $id . '/reactivate')->status(), 'already active');

        $this->assertSame($count, (int) $this->org->query('SELECT COUNT(*) FROM terminals')->fetchColumn());
        $this->assertCount(1, $this->audit('terminal.deactivated'));
        $this->assertCount(1, $this->audit('terminal.reactivated'));
    }

    // ------------------------------------------------------------------ audit

    public function testEveryChangeWritesAnAuditEntryWithTheActorAndNoSecret(): void
    {
        $token = $this->adminDesk['services']->csrf($this->adminDesk['session'])->token();

        $a = $this->branchService()->create($this->admin, $this->org1, $this->branchForm(['code' => 'AUD-A', 'is_delivery_origin' => '1']))['id'];
        $this->assertSame(302, $this->adminPost('/branches', $this->branchForm(['code' => 'AUD-B']))->status());
        $b = $this->branchIdByCode('AUD-B');
        $form = $this->branchForm(['name' => 'Audited', 'is_delivery_origin' => '1']);
        unset($form['code']);
        $this->assertSame(302, $this->adminPost('/branches/' . $b, $form)->status());
        $this->assertSame(302, $this->adminPost('/branches/' . $a . '/deactivate')->status());
        $this->assertSame(302, $this->adminPost('/branches/' . $a . '/reactivate')->status());
        $this->assertSame(302, $this->adminPost('/terminals', ['branch_id' => $a, 'code' => 'T5', 'name' => 'Audit Counter'])->status());
        $statement = $this->org->prepare("SELECT id FROM terminals WHERE code = 'T5'");
        $statement->execute();
        $t = (string) $statement->fetchColumn();
        $this->assertSame(302, $this->adminPost('/terminals/' . $t, ['name' => 'Audit Counter 2'])->status());
        $this->assertSame(302, $this->adminPost('/terminals/' . $t . '/deactivate')->status());
        $this->assertSame(302, $this->adminPost('/terminals/' . $t . '/reactivate')->status());

        foreach (['branch.created' => 2, 'branch.updated' => 1, 'branch.delivery_origin_changed' => 2, 'branch.deactivated' => 1, 'branch.reactivated' => 1,
            'terminal.created' => 1, 'terminal.updated' => 1, 'terminal.deactivated' => 1, 'terminal.reactivated' => 1] as $action => $expected) {
            $this->assertCount($expected, $this->audit($action), $action);
        }

        $allowedKeys = ['code', 'name', 'kind', 'is_pickup_point', 'is_delivery_origin', 'changed', 'from_branch_id', 'to_branch_id', 'branch_id'];
        $rows = $this->org->query("SELECT action, actor_user_id, detail::text AS detail FROM audit_log WHERE action LIKE 'branch.%' OR action LIKE 'terminal.%'")->fetchAll();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame($this->admin->id, $row['actor_user_id'], $row['action'] . ' records who did it');
            $this->assertSame([], array_diff(array_keys(json_decode((string) $row['detail'], true)), $allowedKeys), $row['action'] . ' holds only known, non-secret keys');
            foreach ([$token, TestKit::PASSWORD, 'password', 'secret', 'token', 'postgresql://'] as $secret) {
                $this->assertStringNotContainsString(strtolower($secret), strtolower((string) $row['detail']), $row['action']);
            }
        }
    }
}
