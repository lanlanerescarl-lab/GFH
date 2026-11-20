<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Repository\DiscountRuleRepository;
use Gfh\Core\Repository\OrderStepRepository;
use Gfh\Core\Repository\SettingsRepository;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Service\DiscountRuleService;
use Gfh\Core\Service\OrderService;
use Gfh\Core\Service\OrderStepService;
use Gfh\Core\Service\OrganizationSettingsService;
use Gfh\Core\Service\SettingsService;
use Gfh\Core\Support\SettingLabels;
use Gfh\Core\Support\SettingsSections;
use Gfh\Core\Support\Services;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Organization;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;

/**
 * Batch 5.4c-2: Discount rules, Order steps and Organization settings on the Settings hub (/{org}/admin/settings).
 *
 * Every request goes through the real App + Router + core/routes.php + middleware stack, so a controller missing from
 * the route group, a write route without CSRF, or a page that skips authorize() is caught here. The service tests build
 * the services directly, so a caller that skips the controller is checked too.
 */
final class AdminBatch54c2Test extends DatabaseTestCase
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
    /** The extension folder a test wrote under orgs/, removed in tearDown. */
    private ?string $extensionFolder = null;
    private ?string $orderBranch = null;
    private string $terminalId = '';
    private string $shiftId = '';
    private string $variantId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 2));

        $this->org1 = $this->kit->organization('set-c-' . bin2hex(random_bytes(3)));
        $this->org2 = $this->kit->organization('set-d-' . bin2hex(random_bytes(3)));
        $this->admin = $this->person($this->org1, 'admin@set-c.test', 'admin', 'Alma Admin');
        $this->manager = $this->person($this->org1, 'manager@set-c.test', 'manager', 'Mara Manager');
        $this->staff = $this->person($this->org1, 'sam@set-c.test', 'staff', 'Sam Staff');
        $this->cashier = $this->person($this->org1, 'casey@set-c.test', 'cashier', 'Casey Cashier');
        $this->outsider = $this->person($this->org2, 'olive@set-d.test', 'admin', 'Olive Outsider');
        $this->superAdmin = $this->kit->staff('boss@group.test', TestKit::PASSWORD, 'super_admin', 'active', 'Bea Boss');
        $this->kit->join($this->org1, $this->superAdmin, 'admin');
        $this->adminDesk = $this->desk($this->admin);
    }

    protected function tearDown(): void
    {
        if ($this->extensionFolder !== null && is_dir($this->extensionFolder)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->extensionFolder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->extensionFolder);
            @rmdir(dirname($this->extensionFolder)); // orgs/{slug}/extensions
            @rmdir(dirname($this->extensionFolder, 2)); // orgs/{slug}
        }
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
     * @param array<string, mixed> $post
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

    /** @param array<string, mixed> $post */
    private function adminPost(string $path, array $post = []): Response
    {
        return $this->call($this->adminDesk, 'POST', $this->url($path), $post);
    }

    private function adminGet(string $path): Response
    {
        return $this->call($this->adminDesk, 'GET', $this->url($path));
    }

    private function permissions(?StaffUser $viewer = null): \Gfh\Core\Service\PermissionService
    {
        return $this->desk($viewer ?? $this->admin)['services']->permissions();
    }

    private function ruleService(?StaffUser $viewer = null): DiscountRuleService
    {
        return new DiscountRuleService($this->org, new DiscountRuleRepository($this->org), new AuditService($this->core), $this->permissions($viewer));
    }

    private function stepService(?StaffUser $viewer = null): OrderStepService
    {
        return new OrderStepService($this->org, new OrderStepRepository($this->org), new AuditService($this->core), $this->permissions($viewer));
    }

    private function settingsService(): SettingsService
    {
        return new SettingsService(new SettingsRepository($this->org), new AuditService($this->core), $this->org);
    }

    private function generalService(?StaffUser $viewer = null, ?SettingsService $settings = null): OrganizationSettingsService
    {
        return new OrganizationSettingsService($this->org, $settings ?? $this->settingsService(), $this->permissions($viewer));
    }

    /** @return array<string, string> a believable discount rule form */
    private function ruleForm(array $override = []): array
    {
        return $override + ['key' => 'promo10', 'label' => 'Promo 10', 'percent' => '10', 'vat_exempt' => ''];
    }

    /** @return array<string, string> a believable new step form (position 35 is free in the default path) */
    private function stepForm(array $override = []): array
    {
        return $override + ['key' => 'quality_check', 'label' => 'Quality check', 'sort_order' => '35', 'maps_to' => 'in_progress', 'applies_when' => 'always', 'is_terminal' => '0'];
    }

    /** The edit form of a stored step, with some fields changed. @return array<string, string> */
    private function stepEditForm(string $key, array $override = []): array
    {
        $row = $this->stepRow($key);

        return $override + [
            'label' => (string) $row['label'],
            'sort_order' => (string) $row['sort_order'],
            'maps_to' => (string) $row['maps_to'],
            'applies_when' => (string) $row['applies_when'],
            'is_terminal' => $row['is_terminal'] ? '1' : '0',
        ];
    }

    /** @return array<string, mixed> */
    private function ruleRow(string $id): array
    {
        $statement = $this->org->prepare('SELECT * FROM discount_rules WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        $this->assertIsArray($row, 'the discount rule exists');

        return $row;
    }

    private function ruleId(string $key): string
    {
        $statement = $this->org->prepare('SELECT id FROM discount_rules WHERE key = ?');
        $statement->execute([$key]);

        return (string) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function stepRow(string $key): array
    {
        $statement = $this->org->prepare('SELECT * FROM order_progress_steps WHERE key = ?');
        $statement->execute([$key]);
        $row = $statement->fetch();
        $this->assertIsArray($row, "the step $key exists");

        return $row;
    }

    private function stepExists(string $key): bool
    {
        $statement = $this->org->prepare('SELECT COUNT(*) FROM order_progress_steps WHERE key = ?');
        $statement->execute([$key]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function state(): string
    {
        return json_encode([
            $this->org->query('SELECT id, key, label, percent, vat_exempt, active FROM discount_rules ORDER BY id')->fetchAll(),
            $this->org->query('SELECT key, label, sort_order, is_terminal, maps_to, applies_when FROM order_progress_steps ORDER BY key')->fetchAll(),
            $this->org->query('SELECT key, value FROM settings ORDER BY key')->fetchAll(),
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

    /** @return array<string, mixed> the decoded detail of the only audit entry of that action */
    private function onlyAuditDetail(string $action): array
    {
        $rows = $this->audit($action);
        $this->assertCount(1, $rows, "one $action audit entry");

        return json_decode((string) $rows[0]['detail'], true, flags: JSON_THROW_ON_ERROR);
    }

    /** An order of this organization on a step, optionally cancelled. */
    private function order(string $progress = 'placed', bool $cancelled = false): string
    {
        $this->shop->steps();
        $customer = $this->shop->customer('buyer' . bin2hex(random_bytes(4)) . '@example.test');
        $this->orderBranch ??= $this->shop->branch('OB-1', true, false);
        $statement = $this->org->prepare(
            'INSERT INTO orders (order_number, customer_id, progress_key, fulfillment_method, pickup_branch_id, subtotal, vat_amount, total, cancelled_at) '
            . 'VALUES (?, ?, ?, ?, ?, 112.00, 12.00, 112.00, ' . ($cancelled ? 'now()' : 'NULL') . ') RETURNING id',
        );
        $statement->execute(['SO-T-' . bin2hex(random_bytes(4)), $customer['id'], $progress, 'pickup', $this->orderBranch]);

        return (string) $statement->fetchColumn();
    }

    private function history(string $orderId, string $step): void
    {
        $this->org->prepare('INSERT INTO order_status_history (order_id, progress_key, changed_at) VALUES (?, ?, clock_timestamp())')->execute([$orderId, $step]);
    }

    /** Replaces the default path with the given steps: [key, sort, terminal, maps_to, applies_when]. */
    private function onlySteps(array $steps): void
    {
        $this->org->exec('DELETE FROM order_progress_steps');
        $insert = $this->org->prepare('INSERT INTO order_progress_steps (key, label, sort_order, is_terminal, maps_to, applies_when) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($steps as [$key, $sort, $terminal, $mapsTo, $appliesWhen]) {
            $insert->execute([$key, ucfirst($key), $sort, $terminal ? 'true' : 'false', $mapsTo, $appliesWhen]);
        }
    }

    /** Writes an extension with one setting of each type for this organization (loaded on the first request). */
    private function installTipsExtension(): void
    {
        $folder = dirname(__DIR__, 2) . '/orgs/' . $this->org1->slug . '/extensions/acme-tips';
        mkdir($folder, 0777, true);
        $this->extensionFolder = $folder;
        file_put_contents($folder . '/extension.json', (string) json_encode([
            'key' => 'acme-tips',
            'name' => 'Acme Tips',
            'version' => '1.0.0',
            'entry' => 'register.php',
            'settings' => [
                'acme_tips.max_tip' => ['default' => 10, 'min' => 0, 'max' => 50, 'label' => 'Maximum tip (%)'],
                'acme_tips.show_tips' => ['default' => true, 'label' => 'Show tip buttons'],
                'acme_tips.greeting' => ['default' => 'Thank you!', 'label' => 'Receipt greeting'],
            ],
        ]));
        file_put_contents($folder . '/register.php', '<?php return function ($x) {};');
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> every new Settings route with a believable payload */
    private function everyRequest(string $ruleId, string $stepKey): array
    {
        return [
            ['GET', $this->url('/discounts'), []],
            ['GET', $this->url('/discounts/new'), []],
            ['GET', $this->url('/discounts/' . $ruleId), []],
            ['GET', $this->url('/order-steps'), []],
            ['GET', $this->url('/order-steps/new'), []],
            ['GET', $this->url('/order-steps/' . $stepKey), []],
            ['GET', $this->url('/general'), []],
            ['POST', $this->url('/discounts'), $this->ruleForm(['key' => 'zz_new'])],
            ['POST', $this->url('/discounts/' . $ruleId), ['label' => 'Renamed', 'percent' => '15', 'vat_exempt' => '']],
            ['POST', $this->url('/discounts/' . $ruleId . '/deactivate'), []],
            ['POST', $this->url('/discounts/' . $ruleId . '/reactivate'), []],
            ['POST', $this->url('/order-steps'), $this->stepForm(['key' => 'zz_step'])],
            ['POST', $this->url('/order-steps/' . $stepKey), ['label' => 'Renamed', 'sort_order' => '45', 'maps_to' => 'open', 'applies_when' => 'always', 'is_terminal' => '0']],
            ['POST', $this->url('/order-steps/' . $stepKey . '/delete'), []],
            ['POST', $this->url('/general'), ['settings' => ['deposit_percent' => '25']]],
        ];
    }

    // ------------------------------------------------------------------ permissions

    public function testManagerStaffCashierSuperAdminAndOutsiderAreRefusedOnEveryNewPageAndPost(): void
    {
        $this->shop->discountRule('senior', '20.00', true, true);
        $this->shop->steps();
        $ruleId = $this->ruleId('senior');
        $before = $this->state();

        foreach (['manager' => $this->manager, 'staff' => $this->staff, 'cashier' => $this->cashier, 'super admin' => $this->superAdmin, 'other organization' => $this->outsider] as $who => $actor) {
            $desk = $this->desk($actor);
            foreach ($this->everyRequest($ruleId, 'ready') as [$method, $path, $post]) {
                $response = $this->call($desk, $method, $path, $post);
                $this->assertSame(403, $response->status(), "$who: $method $path must be refused");
                $this->assertStringNotContainsString('SENIOR', $response->body(), "$who: $method $path must not leak discount rules");
                $this->assertStringNotContainsString('Out for Delivery', $response->body(), "$who: $method $path must not leak order steps");
            }
        }

        $this->assertSame($before, $this->state(), 'a refused request changes nothing and writes no audit entry');
    }

    public function testSignedOutVisitorsAreRedirectedToSignInAndSignedOutPostsChangeNothing(): void
    {
        $this->shop->discountRule('senior', '20.00', true, true);
        $this->shop->steps();
        $ruleId = $this->ruleId('senior');
        $before = $this->state();
        $desk = $this->desk(null);

        foreach ($this->everyRequest($ruleId, 'ready') as [$method, $path, $post]) {
            $response = $this->call($desk, $method, $path, $post);
            $this->assertSame(302, $response->status(), "$method $path");
            $this->assertStringContainsString('/admin/login', (string) $response->header('Location'), "$method $path");
        }
        foreach ($this->everyRequest($ruleId, 'ready') as [$method, $path, $post]) {
            if ($method === 'POST') {
                $this->assertSame(403, $this->call($desk, $method, $path, $post, withCsrf: false)->status(), "$method $path");
            }
        }

        $this->assertSame($before, $this->state());
    }

    public function testEveryWriteRefusesAMissingOrWrongCsrfTokenAndChangesNothing(): void
    {
        $this->shop->discountRule('senior', '20.00', true, true);
        $this->shop->steps();
        $ruleId = $this->ruleId('senior');
        $before = $this->state();

        foreach ($this->everyRequest($ruleId, 'ready') as [$method, $path, $post]) {
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
        $this->shop->discountRule('senior', '20.00', true, true);
        $this->shop->steps();
        $ruleId = $this->ruleId('senior');
        $before = $this->state();

        foreach ([$this->manager, $this->staff, $this->cashier, $this->superAdmin, $this->outsider] as $actor) {
            $rules = $this->ruleService($actor);
            $steps = $this->stepService($actor);
            $general = $this->generalService($actor);
            $org = $this->org1;

            $this->assertHttpStatus(403, fn () => $rules->list($actor, $org));
            $this->assertHttpStatus(403, fn () => $rules->get($actor, $org, $ruleId));
            $this->assertHttpStatus(403, fn () => $rules->create($actor, $org, $this->ruleForm(['key' => 'zz_new'])));
            $this->assertHttpStatus(403, fn () => $rules->update($actor, $org, $ruleId, ['label' => 'X', 'percent' => '5', 'vat_exempt' => '']));
            $this->assertHttpStatus(403, fn () => $rules->deactivate($actor, $org, $ruleId));
            $this->assertHttpStatus(403, fn () => $rules->reactivate($actor, $org, $ruleId));

            $this->assertHttpStatus(403, fn () => $steps->list($actor, $org));
            $this->assertHttpStatus(403, fn () => $steps->get($actor, $org, 'ready'));
            $this->assertHttpStatus(403, fn () => $steps->openOrderCount($actor, $org));
            $this->assertHttpStatus(403, fn () => $steps->create($actor, $org, $this->stepForm(['key' => 'zz_step'])));
            $this->assertHttpStatus(403, fn () => $steps->update($actor, $org, 'ready', $this->stepEditForm('ready', ['label' => 'X'])));
            $this->assertHttpStatus(403, fn () => $steps->delete($actor, $org, 'out_for_delivery'));

            $this->assertHttpStatus(403, fn () => $general->list($actor, $org));
            $this->assertHttpStatus(403, fn () => $general->save($actor, $org, ['deposit_percent' => '25']));
        }

        $this->assertSame($before, $this->state());
    }

    // ------------------------------------------------------------------ the hub

    public function testTheHubListsAllFiveSectionsWithTheThreeNewOnesLinked(): void
    {
        $body = $this->adminGet('')->body();

        $this->assertCount(5, SettingsSections::ALL);
        $this->assertSame(['branches', 'terminals', 'discounts', 'order-steps', 'general'], array_column(SettingsSections::ALL, 'key'));
        foreach (['Branches', 'Terminals', 'Discount rules', 'Order steps', 'Organization settings'] as $label) {
            $this->assertStringContainsString('>' . $label . '<', $body, "the hub lists $label");
        }
        foreach (['/discounts', '/order-steps', '/general'] as $path) {
            $this->assertStringContainsString('href="' . $this->url($path) . '"', $body, "the hub links to $path");
        }
    }

    // ------------------------------------------------------------------ discount rules

    public function testAnAdminCreatesADiscountRuleAndItIsAuditedAndListedWithEscapedOutput(): void
    {
        $response = $this->adminPost('/discounts', $this->ruleForm(['key' => 'promo10', 'label' => '<script>alert(1)</script> Promo', 'percent' => '10', 'vat_exempt' => '1']));
        $this->assertSame(302, $response->status());

        $id = $this->ruleId('promo10');
        $this->assertNotSame('', $id);
        $this->assertSame($this->url('/discounts/' . $id), $response->header('Location'));
        $row = $this->ruleRow($id);
        $this->assertSame('10.00', $row['percent']);
        $this->assertTrue($row['active'], 'a new rule starts active');
        $this->assertTrue($row['vat_exempt']);

        $audit = $this->audit('discount_rule.created');
        $this->assertCount(1, $audit);
        $this->assertSame($this->admin->id, $audit[0]['actor_user_id']);
        $this->assertSame($id, $audit[0]['entity_id']);
        $detail = json_decode((string) $audit[0]['detail'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('10.00', $detail['percent']);
        $this->assertSame('promo10', $detail['key']);

        $list = $this->adminGet('/discounts')->body();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Promo', $list);
        $this->assertStringNotContainsString('<script>alert(1)', $list);
        $this->assertStringContainsString('10.00%', $list);
        $this->assertStringContainsString('Exempt', $list);

        $edit = $this->adminGet('/discounts/' . $id)->body();
        $this->assertStringNotContainsString('<script>alert(1)', $edit);
    }

    public function testEveryDiscountFieldIsValidatedAndABadFormChangesNothing(): void
    {
        $this->shop->discountRule('senior', '20.00', true, true);
        $service = $this->ruleService();
        $before = $this->state();

        $bad = [
            'key' => ['', 'Senior', '1abc', 'has-dash', 'has space', str_repeat('a', 41), 'ünï'],
            'label' => ['', '   ', str_repeat('x', 81), "bad\x07label"],
            'percent' => ['', 'abc', '-1', '100.01', '101', '12.345', '1e1', '1,5', '10%'],
        ];
        foreach ($bad as $field => $values) {
            foreach ($values as $value) {
                $exception = $this->assertHttpStatus(422, fn () => $service->create($this->admin, $this->org1, $this->ruleForm([$field => $value])));
                $this->assertArrayHasKey($field, $exception->fields(), "$field = " . json_encode($value) . ' must be refused on its own field');
            }
        }

        // The same checks through the page: the message shows under the field and the form keeps what was typed.
        $response = $this->adminPost('/discounts', $this->ruleForm(['percent' => '12.345']));
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('Enter a percentage from 0 to 100', $response->body());
        $this->assertStringContainsString('value="12.345"', $response->body());

        $this->assertSame($before, $this->state());
    }

    public function testTheBoundariesOfEveryDiscountFieldAreAccepted(): void
    {
        $service = $this->ruleService();
        $cases = [
            ['a', '0', '0.00'],
            ['b', '100', '100.00'],
            ['c', '100.00', '100.00'],
            ['d', '0.01', '0.01'],
            ['e', '12.5', '12.50'],
            [str_repeat('k', 40), '5', '5.00'],
        ];
        foreach ($cases as [$key, $percent, $stored]) {
            $service->create($this->admin, $this->org1, $this->ruleForm(['key' => $key, 'label' => str_repeat('L', 80), 'percent' => $percent]));
            $this->assertSame($stored, $this->ruleRow($this->ruleId($key))['percent'], "$percent is stored as $stored");
        }
    }

    public function testADuplicateKeyIsRefusedEvenAgainstARuleThatAlreadyExists(): void
    {
        $this->shop->discountRule('senior', '20.00', true, true);
        $this->adminPost('/discounts', $this->ruleForm(['key' => 'promo10']));
        $before = $this->state();

        foreach (['promo10', 'senior'] as $key) {
            $response = $this->adminPost('/discounts', $this->ruleForm(['key' => $key, 'label' => 'Again']));
            $this->assertSame(422, $response->status());
            $this->assertStringContainsString('already used', $response->body());
            $this->assertArrayHasKey('key', $this->assertHttpStatus(422, fn () => $this->ruleService()->create($this->admin, $this->org1, $this->ruleForm(['key' => $key])))->fields());
        }

        $this->assertSame($before, $this->state());
    }

    public function testAnAdminEditsALabelPercentAndVatFlagAndEachChangeIsAudited(): void
    {
        $this->shop->discountRule('senior', '20.00', true, true);
        $id = $this->ruleId('senior');

        $response = $this->adminPost('/discounts/' . $id, ['label' => 'Senior Citizen', 'percent' => '12.5', 'vat_exempt' => '']);
        $this->assertSame(302, $response->status());

        $row = $this->ruleRow($id);
        $this->assertSame('senior', $row['key'], 'the key never changes');
        $this->assertSame('Senior Citizen', $row['label']);
        $this->assertSame('12.50', $row['percent']);
        $this->assertFalse($row['vat_exempt']);
        $this->assertTrue($row['active']);

        $detail = $this->onlyAuditDetail('discount_rule.updated');
        $this->assertSame(['label', 'percent', 'vat_exempt'], $detail['changed']);
        $this->assertEquals(['from' => '20.00', 'to' => '12.50'], $detail['percent'], 'jsonb does not keep key order');
    }

    public function testSavingTheSameValuesWritesNothingAndAuditsNothing(): void
    {
        $this->shop->discountRule('senior', '20.00', true, true);
        $id = $this->ruleId('senior');
        $before = $this->state();

        // "20" and "20.00" are the same percent.
        $response = $this->adminPost('/discounts/' . $id, ['label' => 'SENIOR', 'percent' => '20', 'vat_exempt' => '1']);

        $this->assertSame(302, $response->status());
        $this->assertSame($before, $this->state());
    }

    public function testATamperedKeyIsRefusedAndATamperedActiveFlagIsIgnored(): void
    {
        $this->shop->discountRule('senior', '20.00', true, true);
        $id = $this->ruleId('senior');
        $before = $this->state();

        $response = $this->adminPost('/discounts/' . $id, ['key' => 'hacked', 'label' => 'Hacked', 'percent' => '99', 'vat_exempt' => '1']);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('cannot be changed', $response->body());
        $this->assertSame($before, $this->state(), 'a tampered key refuses the whole save');

        $response = $this->adminPost('/discounts/' . $id, ['label' => 'Renamed', 'percent' => '20', 'vat_exempt' => '1', 'active' => '0']);
        $this->assertSame(302, $response->status());
        $row = $this->ruleRow($id);
        $this->assertSame('Renamed', $row['label']);
        $this->assertTrue($row['active'], 'the active flag changes only through deactivate and reactivate');

        $this->assertStringContainsString('disabled', $this->adminGet('/discounts/' . $id)->body(), 'the key field is read-only on the edit page');
    }

    public function testDeactivateAndReactivateFlipTheFlagAreAuditedAndRefuseARepeat(): void
    {
        $this->shop->discountRule('senior', '20.00', true, true);
        $id = $this->ruleId('senior');

        $this->assertSame(302, $this->adminPost('/discounts/' . $id . '/deactivate')->status());
        $this->assertFalse($this->ruleRow($id)['active']);
        $this->assertCount(1, $this->audit('discount_rule.deactivated'));
        $this->assertStringContainsString('Reactivate', $this->adminGet('/discounts/' . $id)->body());

        $again = $this->adminPost('/discounts/' . $id . '/deactivate');
        $this->assertSame(422, $again->status());
        $this->assertStringContainsString('already inactive', $again->body());

        $this->assertSame(302, $this->adminPost('/discounts/' . $id . '/reactivate')->status());
        $this->assertTrue($this->ruleRow($id)['active']);
        $this->assertCount(1, $this->audit('discount_rule.reactivated'));
        $this->assertStringContainsString('Deactivate', $this->adminGet('/discounts/' . $id)->body());

        $again = $this->adminPost('/discounts/' . $id . '/reactivate');
        $this->assertSame(422, $again->status());
        $this->assertStringContainsString('already active', $again->body());
        $this->assertSame('20.00', $this->ruleRow($id)['percent'], 'deactivating changes nothing else');
    }

    public function testAnUnknownOrMalformedRuleIdIsNotFoundAndThereIsNoWayToDeleteARule(): void
    {
        $this->shop->discountRule('senior', '20.00', true, true);
        $id = $this->ruleId('senior');
        $before = $this->state();

        foreach (['not-a-uuid', Uuid::v4()] as $bad) {
            $this->assertSame(404, $this->adminGet('/discounts/' . $bad)->status());
            $this->assertSame(404, $this->adminPost('/discounts/' . $bad, ['label' => 'X', 'percent' => '5'])->status());
            $this->assertSame(404, $this->adminPost('/discounts/' . $bad . '/deactivate')->status());
            $this->assertSame(404, $this->adminPost('/discounts/' . $bad . '/reactivate')->status());
        }
        $this->assertContains($this->adminPost('/discounts/' . $id . '/delete')->status(), [404, 405], 'there is no delete route');

        $this->assertSame($before, $this->state());
    }

    /** Sets up a terminal with an open shift, a variant on a shelf, and returns the POS service. */
    private function pos(): \Gfh\Core\Service\PosService
    {
        $branch = $this->shop->branch('S1');
        $this->terminalId = $this->shop->terminal($branch, 'T3');
        $this->shiftId = $this->shop->shift($this->terminalId, '1000.00');
        $this->variantId = $this->shop->variant(['sku' => 'COFFEE-250', 'price' => '112.00']);
        $this->shop->stock($branch, $this->variantId, '5', '50.00');

        return $this->shop->services->pos();
    }

    /** @return array<string, mixed> */
    private function posSale(string $receipt, string $rule, string $payment): array
    {
        return [
            'id' => Uuid::v4(),
            'receipt_number' => $receipt,
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'sold_at' => (new \DateTimeImmutable())->format('c'),
            'lines' => [['variant_id' => $this->variantId, 'quantity' => '1', 'unit_price' => '112.00']],
            'payments' => [['method' => 'cash', 'amount' => $payment]],
            'discount' => ['key' => $rule, 'id_ref_last4' => '4821'],
        ];
    }

    /** @return array<string, mixed> everything stored about a sale: the sale row and its discount rows */
    private function storedSale(string $saleId): array
    {
        $sale = $this->org->prepare('SELECT * FROM pos_sales WHERE id = ?');
        $sale->execute([$saleId]);
        $discounts = $this->org->prepare('SELECT * FROM pos_sale_discounts WHERE pos_sale_id = ? ORDER BY id');
        $discounts->execute([$saleId]);

        return ['sale' => $sale->fetch(), 'discounts' => $discounts->fetchAll()];
    }

    public function testEditingARuleChangesFutureSalesOnlyAndARecordedSaleKeepsItsDiscountAmounts(): void
    {
        $pos = $this->pos();
        $this->adminPost('/discounts', $this->ruleForm(['key' => 'senior20', 'label' => 'Senior 20', 'percent' => '20', 'vat_exempt' => '1']));
        $id = $this->ruleId('senior20');

        $first = $pos->syncSale($this->posSale('T3-000001', 'senior20', '80.00'));
        $before = $this->storedSale($first['sale_id']);
        $viewBefore = $pos->getSale($first['sale_id']);
        $this->assertSame('32.00', $viewBefore['discount_total']);
        $this->assertSame('80.00', $viewBefore['total']);
        $this->assertSame('32.00', $before['discounts'][0]['amount']);

        // The percent halves and the VAT-exempt flag is switched off.
        $this->assertSame(302, $this->adminPost('/discounts/' . $id, ['label' => 'Senior 20', 'percent' => '50', 'vat_exempt' => ''])->status());
        $this->assertSame('50.00', $this->ruleRow($id)['percent']);

        $this->assertSame($before, $this->storedSale($first['sale_id']), 'the stored sale and its discount rows are untouched');
        $viewAfter = $pos->getSale($first['sale_id']);
        foreach (['discount_total', 'vat_amount', 'total', 'subtotal'] as $column) {
            $this->assertSame($viewBefore[$column], $viewAfter[$column], "$column of a recorded sale is unchanged");
        }
        $this->assertSame($viewBefore['discounts'][0]['amount'], $viewAfter['discounts'][0]['amount'], 'the recorded discount amount is unchanged');

        // A sale made after the edit uses the new percent.
        $second = $pos->getSale($pos->syncSale($this->posSale('T3-000002', 'senior20', '56.00'))['sale_id']);
        $this->assertSame('56.00', $second['discount_total']);
        $this->assertSame('56.00', $second['total']);
    }

    public function testADeactivatedRuleIsNotOfferedToThePosAndIsOfferedAgainOnceReactivated(): void
    {
        $pos = $this->pos();
        $this->adminPost('/discounts', $this->ruleForm(['key' => 'promo10', 'label' => 'Promo 10', 'percent' => '10']));
        $id = $this->ruleId('promo10');
        $offered = static fn (array $snapshot): array => array_column($snapshot['discount_rules'], 'key');

        $this->assertSame(['promo10'], $offered($pos->snapshot($this->terminalId)), 'an active rule is offered');

        $this->adminPost('/discounts/' . $id . '/deactivate');
        $this->assertSame([], $offered($pos->snapshot($this->terminalId)), 'an inactive rule is not offered');
        $this->assertArrayHasKey('discount', $this->assertHttpStatus(422, fn () => $pos->syncSale($this->posSale('T3-000001', 'promo10', '100.80')))->fields(), 'and it cannot be applied to a sale');

        $this->adminPost('/discounts/' . $id . '/reactivate');
        $this->assertSame(['promo10'], $offered($pos->snapshot($this->terminalId)));
    }

    // ------------------------------------------------------------------ order steps

    public function testTheListShowsEveryStepInPathOrderWithItsConditionAndHowManyOrdersAreOnIt(): void
    {
        $this->order('placed');
        $this->order('placed');
        $this->order('placed', true);
        $this->order('completed');

        $steps = $this->stepService()->list($this->admin, $this->org1);
        $this->assertSame(['placed', 'confirmed', 'in_production', 'ready', 'out_for_delivery', 'completed'], array_column($steps, 'key'));
        $counts = array_column($steps, 'order_count', 'key');
        $this->assertSame(2, (int) $counts['placed'], 'a cancelled order is not counted');
        $this->assertSame(1, (int) $counts['completed']);
        $this->assertSame(0, (int) $counts['ready']);

        $body = $this->adminGet('/order-steps')->body();
        $last = -1;
        foreach (['Placed', 'Confirmed', 'In Production', 'Ready', 'Out for Delivery', 'Completed'] as $label) {
            $position = strpos($body, '>' . $label . '</a>');
            $this->assertNotFalse($position, "$label is listed");
            $this->assertGreaterThan($last, $position, "$label is in path order");
            $last = $position;
        }
        $this->assertStringContainsString('Only delivery orders', $body);
        $this->assertStringContainsString('Only orders with a made-to-order item', $body);
        $this->assertStringContainsString('2 order(s) are open', $body, 'the page says why structural changes are locked');
    }

    public function testTheLabelCanAlwaysBeEditedEvenWhileOrdersAreOpen(): void
    {
        $this->order('placed');
        $before = $this->stepRow('ready');

        $response = $this->adminPost('/order-steps/ready', $this->stepEditForm('ready', ['label' => 'Ready for pickup']));

        $this->assertSame(302, $response->status());
        $after = $this->stepRow('ready');
        $this->assertSame('Ready for pickup', $after['label']);
        foreach (['sort_order', 'maps_to', 'applies_when', 'is_terminal', 'key'] as $column) {
            $this->assertSame($before[$column], $after[$column], "$column is untouched");
        }
        $this->assertSame(['label'], $this->onlyAuditDetail('order_step.updated')['changed']);
    }

    public function testEveryStructuralChangeIsRefusedWhileAnOrderIsOpen(): void
    {
        $this->order('placed');
        $before = $this->state();
        $requests = [
            'add a step' => ['/order-steps', $this->stepForm()],
            'change the position' => ['/order-steps/ready', $this->stepEditForm('ready', ['sort_order' => '45'])],
            'change maps_to' => ['/order-steps/ready', $this->stepEditForm('ready', ['maps_to' => 'open'])],
            'change is_terminal' => ['/order-steps/ready', $this->stepEditForm('ready', ['is_terminal' => '1', 'maps_to' => 'complete'])],
            'change applies_when' => ['/order-steps/ready', $this->stepEditForm('ready', ['applies_when' => 'is_pickup'])],
            'delete a step' => ['/order-steps/out_for_delivery/delete', []],
        ];

        foreach ($requests as $what => [$path, $post]) {
            $response = $this->adminPost($path, $post);
            $this->assertSame(422, $response->status(), "$what must be refused while an order is open");
            $this->assertStringContainsString('1 order(s) are open', $response->body(), $what);
            $this->assertSame($before, $this->state(), "$what changes nothing");
        }
    }

    public function testAnOrderOnAFinishedStepOrACancelledOrderDoesNotCountAsOpen(): void
    {
        $this->order('completed');
        $this->order('ready', true);

        $this->assertSame(0, $this->stepService()->openOrderCount($this->admin, $this->org1));
        $this->order('placed');
        $this->assertSame(1, $this->stepService()->openOrderCount($this->admin, $this->org1));
    }

    public function testEveryStructuralChangeIsAllowedWhenNoOrderIsOpen(): void
    {
        $this->order('completed');
        $this->order('ready', true);

        $this->assertSame(302, $this->adminPost('/order-steps', $this->stepForm())->status());
        $this->assertSame('35', (string) $this->stepRow('quality_check')['sort_order']);

        $this->assertSame(302, $this->adminPost('/order-steps/confirmed', $this->stepEditForm('confirmed', ['sort_order' => '25']))->status());
        $this->assertSame(25, (int) $this->stepRow('confirmed')['sort_order']);

        $this->assertSame(302, $this->adminPost('/order-steps/quality_check', $this->stepEditForm('quality_check', ['maps_to' => 'open']))->status());
        $this->assertSame('open', $this->stepRow('quality_check')['maps_to']);

        $this->assertSame(302, $this->adminPost('/order-steps/quality_check', $this->stepEditForm('quality_check', ['applies_when' => 'is_pickup']))->status());
        $this->assertSame('is_pickup', $this->stepRow('quality_check')['applies_when']);

        $this->assertSame(302, $this->adminPost('/order-steps/quality_check/delete')->status());
        $this->assertFalse($this->stepExists('quality_check'));

        // is_terminal, last: it turns the finished order into an open one, so nothing else can follow it.
        $this->assertSame(302, $this->adminPost('/order-steps', $this->stepForm(['key' => 'closed', 'label' => 'Closed', 'sort_order' => '70', 'maps_to' => 'complete', 'is_terminal' => '1']))->status());
        $this->assertSame(302, $this->adminPost('/order-steps/completed', $this->stepEditForm('completed', ['is_terminal' => '0']))->status());
        $this->assertFalse($this->stepRow('completed')['is_terminal']);
        $this->assertSame(1, $this->stepService()->openOrderCount($this->admin, $this->org1), 'the order on that step is now open, so the steps are locked again');
        $this->assertSame(422, $this->adminPost('/order-steps/closed/delete')->status());

        foreach (['order_step.created' => 2, 'order_step.updated' => 4, 'order_step.deleted' => 1] as $action => $count) {
            $this->assertCount($count, $this->audit($action), $action);
        }
        $created = $this->audit('order_step.created')[0];
        $this->assertSame($this->admin->id, $created['actor_user_id']);
    }

    public function testAStepThatAnOrderOrAHistoryEntryReferencesCannotBeDeletedEvenWithNoOpenOrder(): void
    {
        $cancelledAtReady = $this->order('ready', true);
        $finished = $this->order('completed');
        $this->history($finished, 'confirmed');
        $this->assertSame(0, $this->stepService()->openOrderCount($this->admin, $this->org1));
        $before = $this->state();

        $response = $this->adminPost('/order-steps/ready/delete');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('1 order(s)', $response->body(), 'an order points at it');
        $this->assertTrue($this->stepExists('ready'));

        $response = $this->adminPost('/order-steps/confirmed/delete');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('0 order(s) and 1 history entry', $response->body(), 'only a history row points at it');
        $this->assertTrue($this->stepExists('confirmed'));

        $this->assertSame($before, $this->state());
        $this->assertNotSame('', $cancelledAtReady);

        // A step nobody points at can still go.
        $this->assertSame(302, $this->adminPost('/order-steps/out_for_delivery/delete')->status());
        $this->assertFalse($this->stepExists('out_for_delivery'));
    }

    public function testAChangeThatWouldLeaveNoStepThatCompletesAnOrderIsRefusedAndRolledBack(): void
    {
        $this->shop->steps();
        $before = $this->state();
        $changes = [
            'stop the only completing step being the last step' => ['/order-steps/completed', $this->stepEditForm('completed', ['is_terminal' => '0'])],
            'stop the only last step completing' => ['/order-steps/completed', $this->stepEditForm('completed', ['maps_to' => 'in_progress'])],
            'delete the only completing step' => ['/order-steps/completed/delete', []],
        ];

        foreach ($changes as $what => [$path, $post]) {
            $response = $this->adminPost($path, $post);
            $this->assertSame(422, $response->status(), $what);
            $this->assertStringContainsString('map to Complete', $response->body(), $what);
            $this->assertSame($before, $this->state(), "$what is rolled back and not audited");
        }
    }

    public function testAChangeThatWouldLeaveNoStepThatAppliesToEveryOrderIsRefusedAndRolledBack(): void
    {
        $this->onlySteps([['start', 10, false, 'open', 'always'], ['finish', 20, true, 'complete', 'always']]);

        $this->assertSame(302, $this->adminPost('/order-steps/finish', $this->stepEditForm('finish', ['applies_when' => 'is_pickup']))->status(), 'start still applies always');
        $before = $this->state();

        $response = $this->adminPost('/order-steps/start', $this->stepEditForm('start', ['applies_when' => 'is_delivery']));

        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('apply always', $response->body());
        $this->assertSame($before, $this->state());
        $this->assertSame('always', $this->stepRow('start')['applies_when']);
    }

    public function testALastStepMustMapToCompleteBecauseStockIsOnlySoldOnACompleteStep(): void
    {
        $this->shop->steps();
        $before = $this->state();

        $response = $this->adminPost('/order-steps/ready', $this->stepEditForm('ready', ['is_terminal' => '1']));

        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('must map to Complete', $response->body());
        $this->assertSame($before, $this->state());
    }

    public function testNoOrderMayStartOnAFinishedStep(): void
    {
        $this->shop->steps();
        $before = $this->state();

        $added = $this->adminPost('/order-steps', $this->stepForm(['key' => 'early_done', 'sort_order' => '5', 'maps_to' => 'complete', 'is_terminal' => '1']));
        $this->assertSame(422, $added->status());
        $this->assertStringContainsString('finished before they begin', $added->body());

        $moved = $this->adminPost('/order-steps/completed', $this->stepEditForm('completed', ['sort_order' => '5']));
        $this->assertSame(422, $moved->status());
        $this->assertStringContainsString('finished before they begin', $moved->body());

        $this->assertSame($before, $this->state());
    }

    public function testAnApplyConditionOrMapsToTheOrderFlowDoesNotKnowIsRefused(): void
    {
        $this->shop->steps();
        $before = $this->state();

        foreach (['sometimes', 'ALWAYS', '', 'always; DROP TABLE orders', 'is_deliver'] as $value) {
            $exception = $this->assertHttpStatus(422, fn () => $this->stepService()->create($this->admin, $this->org1, $this->stepForm(['applies_when' => $value])));
            $this->assertArrayHasKey('applies_when', $exception->fields(), json_encode($value));
            $exception = $this->assertHttpStatus(422, fn () => $this->stepService()->update($this->admin, $this->org1, 'ready', $this->stepEditForm('ready', ['applies_when' => $value])));
            $this->assertArrayHasKey('applies_when', $exception->fields(), json_encode($value));
        }
        foreach (['done', 'Complete', '', 'closed'] as $value) {
            $exception = $this->assertHttpStatus(422, fn () => $this->stepService()->create($this->admin, $this->org1, $this->stepForm(['maps_to' => $value])));
            $this->assertArrayHasKey('maps_to', $exception->fields(), json_encode($value));
            $exception = $this->assertHttpStatus(422, fn () => $this->stepService()->update($this->admin, $this->org1, 'ready', $this->stepEditForm('ready', ['maps_to' => $value])));
            $this->assertArrayHasKey('maps_to', $exception->fields(), json_encode($value));
        }
        $page = $this->adminPost('/order-steps', $this->stepForm(['applies_when' => 'sometimes']));
        $this->assertSame(422, $page->status());
        $this->assertStringContainsString('would never apply', $page->body());

        $this->assertSame($before, $this->state());
    }

    public function testEveryConditionTheOrderFlowRecognisesIsAcceptedAndNothingElseEverApplies(): void
    {
        $contexts = [];
        foreach (['pickup', 'delivery'] as $method) {
            foreach ([false, true] as $madeToOrder) {
                foreach ([0, 1] as $fee) {
                    $contexts[] = ['fulfillment_method' => $method, 'has_made_to_order' => $madeToOrder, 'assembly_fee' => $fee, 'installation_fee' => $fee];
                }
            }
        }

        $this->shop->steps();
        foreach (OrderService::APPLIES_WHEN as $index => $condition) {
            $applies = array_filter($contexts, static fn (array $context): bool => OrderService::stepApplies($condition, $context));
            $this->assertNotSame([], $applies, "$condition applies to some order");
            $this->assertCount(6, OrderService::APPLIES_WHEN);

            $key = 'extra_' . $index;
            $this->stepService()->create($this->admin, $this->org1, $this->stepForm(['key' => $key, 'sort_order' => (string) (11 + $index), 'applies_when' => $condition]));
            $this->assertSame($condition, $this->stepRow($key)['applies_when']);
        }
        foreach ($contexts as $context) {
            $this->assertFalse(OrderService::stepApplies('sometimes', $context), 'an unknown condition never applies');
        }
        foreach (OrderStepService::MAPS_TO as $mapsTo) {
            $this->stepService()->update($this->admin, $this->org1, 'ready', $this->stepEditForm('ready', ['maps_to' => $mapsTo]));
            $this->assertSame($mapsTo, $this->stepRow('ready')['maps_to']);
        }
    }

    public function testAStepKeyIsChosenOnCreateWithAStrictShapeAndCannotChangeAfterwards(): void
    {
        $this->shop->steps();
        $before = $this->state();

        foreach (['', 'Upper', '1x', 'a-b', 'has space', 'new', str_repeat('a', 41), 'ready'] as $key) {
            $exception = $this->assertHttpStatus(422, fn () => $this->stepService()->create($this->admin, $this->org1, $this->stepForm(['key' => $key])));
            $this->assertArrayHasKey('key', $exception->fields(), json_encode($key));
        }
        $this->assertSame($before, $this->state());

        $response = $this->adminPost('/order-steps/ready', $this->stepEditForm('ready', ['key' => 'renamed', 'label' => 'Hacked']));
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('cannot be changed', $response->body());
        $this->assertSame($before, $this->state(), 'a tampered key refuses the whole save');

        $this->assertStringContainsString('disabled', $this->adminGet('/order-steps/ready')->body(), 'the key field is read-only on the edit page');
        $this->stepService()->create($this->admin, $this->org1, $this->stepForm(['key' => str_repeat('a', 40)]));
        $this->assertTrue($this->stepExists(str_repeat('a', 40)));
    }

    public function testThePositionMustBeAWholeNumberFromOneToNineThousandNineHundredNinetyNineAndFree(): void
    {
        $this->shop->steps();
        $before = $this->state();

        foreach (['', '0', '-5', '10000', 'abc', '1.5', '20'] as $value) {
            $exception = $this->assertHttpStatus(422, fn () => $this->stepService()->create($this->admin, $this->org1, $this->stepForm(['sort_order' => $value])));
            $this->assertArrayHasKey('sort_order', $exception->fields(), json_encode($value));
        }
        $this->assertSame($before, $this->state());

        $this->stepService()->create($this->admin, $this->org1, $this->stepForm(['key' => 'lowest', 'sort_order' => '1']));
        $this->stepService()->create($this->admin, $this->org1, $this->stepForm(['key' => 'highest', 'sort_order' => '9999']));
        $this->assertSame([1, 9999], [(int) $this->stepRow('lowest')['sort_order'], (int) $this->stepRow('highest')['sort_order']]);
    }

    public function testAnUnknownOrMalformedStepKeyIsNotFound(): void
    {
        $this->shop->steps();
        $before = $this->state();

        foreach (['nope', 'Bad-Key', '9lives'] as $key) {
            $this->assertSame(404, $this->adminGet('/order-steps/' . $key)->status(), $key);
            $this->assertSame(404, $this->adminPost('/order-steps/' . $key, $this->stepForm())->status(), $key);
            $this->assertSame(404, $this->adminPost('/order-steps/' . $key . '/delete')->status(), $key);
        }

        $this->assertSame($before, $this->state());
    }

    public function testANewStepJoinsThePathOfTheOrdersItAppliesTo(): void
    {
        $this->shop->steps();
        $this->adminPost('/order-steps', $this->stepForm(['key' => 'quality_check', 'sort_order' => '35', 'applies_when' => 'is_delivery']));

        $keys = static fn (array $path): array => array_column($path, 'key');
        $delivery = ['fulfillment_method' => 'delivery', 'has_made_to_order' => false, 'assembly_fee' => 0, 'installation_fee' => 0];
        $pickup = ['fulfillment_method' => 'pickup'] + $delivery;

        $this->assertContains('quality_check', $keys($this->shop->services->orders()->progressPath($delivery)));
        $this->assertNotContains('quality_check', $keys($this->shop->services->orders()->progressPath($pickup)));
    }

    // ------------------------------------------------------------------ organization settings

    private function assertShowsLabel(string $body, string $label): void
    {
        $this->assertMatchesRegularExpression('/>\s*' . preg_quote(htmlspecialchars($label, ENT_QUOTES), '/') . '\s*</', $body, "the page shows the label $label");
    }

    /** The JSON type the settings table holds for a key: number, boolean, string, or null when nothing is stored. */
    private function storedType(string $key): ?string
    {
        $statement = $this->org->prepare('SELECT jsonb_typeof(value) FROM settings WHERE key = ?');
        $statement->execute([$key]);
        $type = $statement->fetchColumn();

        return $type === false ? null : (string) $type;
    }

    private function storedValue(string $key): ?string
    {
        $statement = $this->org->prepare('SELECT value::text FROM settings WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function testThePageListsEveryCoreSettingWithItsLabelTypeCurrentValueAndDefault(): void
    {
        $this->settingsService()->set('reservation_hours', 72, $this->admin->id);

        $body = $this->adminGet('/general')->body();

        $described = $this->settingsService()->describe();
        $this->assertSame(['deposit_percent', 'reservation_hours', 'near_expiry_days'], array_column($described, 'key'));
        foreach ($described as $setting) {
            $this->assertSame('int', $setting['type']);
            $this->assertNull($setting['source']);
            $this->assertShowsLabel($body, SettingLabels::label($setting['key']));
            $this->assertNotSame($setting['key'], SettingLabels::label($setting['key']), $setting['key'] . ' has a real label, not just its key');
            $this->assertStringContainsString('name="settings[' . $setting['key'] . ']"', $body);
        }
        $this->assertStringContainsString('name="settings[reservation_hours]"', $body);
        $this->assertStringContainsString('value="72"', $body, 'the current value');
        $this->assertStringContainsString('Default: 48', $body, 'the default');
        $this->assertStringContainsString('Allowed: 1 to 720', $body);
        $this->assertStringContainsString('type="number"', $body, 'a whole number gets a number input');
    }

    public function testExtensionSettingsAreListedWithTheirTypesLabelsRangesAndTheExtensionThatAddsThem(): void
    {
        $this->installTipsExtension();

        $body = $this->adminGet('/general')->body();

        $this->assertStringContainsString('Added by extensions', $body);
        $this->assertShowsLabel($body, 'Maximum tip (%)');
        $this->assertStringContainsString('name="settings[acme_tips.max_tip]"', $body);
        $this->assertStringContainsString('Allowed: 0 to 50', $body);
        $this->assertShowsLabel($body, 'Show tip buttons');
        $this->assertStringContainsString('role="switch"', $body, 'a yes/no setting gets a switch');
        $this->assertStringContainsString('aria-checked="true"', $body, 'it shows its default, on');
        $this->assertShowsLabel($body, 'Receipt greeting');
        $this->assertStringContainsString('value="Thank you!"', $body, 'a text setting gets a text input');
        $this->assertStringContainsString('Added by the Acme Tips extension', $body);
        $this->assertStringContainsString('Default: 10', $body);
        $this->assertStringContainsString('Default: On', $body);
    }

    public function testAValidChangeIsSavedAsAWholeNumberAndAuditedAndOnlyChangedValuesAreWritten(): void
    {
        $response = $this->adminPost('/general', ['settings' => ['deposit_percent' => '30', 'reservation_hours' => '48', 'near_expiry_days' => '7']]);

        $this->assertSame(302, $response->status());
        $this->assertSame($this->url('/general'), $response->header('Location'));
        $this->assertSame('number', $this->storedType('deposit_percent'), 'a whole number stays a number');
        $this->assertSame('30', $this->storedValue('deposit_percent'));
        $this->assertNull($this->storedType('reservation_hours'), 'a value equal to the current one is not written');

        $audit = $this->audit('settings.changed');
        $this->assertCount(1, $audit);
        $this->assertSame('deposit_percent', $audit[0]['entity_id']);
        $this->assertSame($this->admin->id, $audit[0]['actor_user_id']);
        $this->assertSame(30, json_decode((string) $audit[0]['detail'], true, flags: JSON_THROW_ON_ERROR)['value']);

        $this->assertStringContainsString('value="30"', $this->adminGet('/general')->body());
    }

    public function testAnOutOfRangeOrNonNumericValueIsRefusedInlineAndNothingIsSavedNotEvenTheValidFields(): void
    {
        $before = $this->state();
        $cases = [
            ['deposit_percent', '101', 'Enter a whole number from 0 to 100.'],
            ['reservation_hours', '0', 'Enter a whole number from 1 to 720.'],
            ['reservation_hours', '721', 'Enter a whole number from 1 to 720.'],
            ['near_expiry_days', 'abc', 'Enter a whole number from 0 to 365.'],
            ['near_expiry_days', '', 'Enter a whole number from 0 to 365.'],
            ['near_expiry_days', '-1', 'Enter a whole number from 0 to 365.'],
            ['near_expiry_days', '1.5', 'Enter a whole number from 0 to 365.'],
        ];

        foreach ($cases as [$key, $value, $message]) {
            // A valid change to another field travels in the same save and must not be kept.
            $post = ['settings' => ['deposit_percent' => '20', $key => $value]];
            $response = $this->adminPost('/general', $post);

            $this->assertSame(422, $response->status(), "$key = " . json_encode($value));
            $this->assertStringContainsString($message, $response->body(), 'the message shows on the page');
            $this->assertStringContainsString('role="alert"', $response->body());
            $this->assertSame($before, $this->state(), "$key = " . json_encode($value) . ' saves nothing');
        }
    }

    public function testAnUnknownSettingKeyIsRefusedWith422AndNothingIsSaved(): void
    {
        $before = $this->state();

        $response = $this->adminPost('/general', ['settings' => ['deposit_percent' => '20', 'not_a_setting' => '1']]);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('is not a setting', $response->body());

        $exception = $this->assertHttpStatus(422, fn () => $this->generalService()->save($this->admin, $this->org1, ['not_a_setting' => '1']));
        $this->assertArrayHasKey('not_a_setting', $exception->fields());
        $this->assertHttpStatus(422, fn () => $this->settingsService()->set('not_a_setting', 1, $this->admin->id));

        $this->assertSame($before, $this->state());
    }

    public function testABooleanAndAWholeNumberAndTextKeepTheirTypesForExtensionSettings(): void
    {
        $this->installTipsExtension();

        $response = $this->adminPost('/general', ['settings' => ['acme_tips.max_tip' => '25', 'acme_tips.show_tips' => '0', 'acme_tips.greeting' => '  Salamat!  ']]);
        $this->assertSame(302, $response->status());

        $this->assertSame('number', $this->storedType('acme_tips.max_tip'), 'a whole number is stored as a number, not as text');
        $this->assertSame('25', $this->storedValue('acme_tips.max_tip'));
        $this->assertSame('boolean', $this->storedType('acme_tips.show_tips'));
        $this->assertSame('false', $this->storedValue('acme_tips.show_tips'));
        $this->assertSame('string', $this->storedType('acme_tips.greeting'));
        $this->assertSame('"Salamat!"', $this->storedValue('acme_tips.greeting'), 'text is trimmed');
        $this->assertCount(3, $this->audit('settings.changed'));

        $body = $this->adminGet('/general')->body();
        $this->assertStringContainsString('value="25"', $body);
        $this->assertStringContainsString('aria-checked="false"', $body);
        $this->assertStringContainsString('value="Salamat!"', $body);
    }

    public function testExtensionSettingsKeepTheirOwnRangeAndTheyRefuseAnythingButOnOrOff(): void
    {
        $this->installTipsExtension();
        $before = $this->state();

        $response = $this->adminPost('/general', ['settings' => ['acme_tips.max_tip' => '51']]);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('Enter a whole number from 0 to 50.', $response->body());

        $response = $this->adminPost('/general', ['settings' => ['acme_tips.show_tips' => 'maybe']]);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('Choose on or off.', $response->body());

        $response = $this->adminPost('/general', ['settings' => ['acme_tips.greeting' => str_repeat('x', 501)]]);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('at most 500 characters', $response->body());

        $this->assertSame($before, $this->state());
    }

    public function testSettingsAreDescribedForTheServiceWithExtensionMetadata(): void
    {
        $settings = $this->settingsService();
        $settings->define('acme_tips.max_tip', 10, null, ['label' => 'Maximum tip (%)', 'type' => 'int', 'min' => 0, 'max' => 50, 'extension' => 'Acme Tips']);
        $settings->define('acme_tips.show_tips', true, null, ['label' => 'Show tips', 'type' => 'bool', 'min' => null, 'max' => null, 'extension' => 'Acme Tips']);

        $rows = array_column($settings->describe(), null, 'key');

        $this->assertSame(['int', 0, 365, 7, null], [$rows['near_expiry_days']['type'], $rows['near_expiry_days']['min'], $rows['near_expiry_days']['max'], $rows['near_expiry_days']['default'], $rows['near_expiry_days']['source']]);
        $this->assertSame(['int', 0, 50, 10, 'Acme Tips'], [$rows['acme_tips.max_tip']['type'], $rows['acme_tips.max_tip']['min'], $rows['acme_tips.max_tip']['max'], $rows['acme_tips.max_tip']['default'], $rows['acme_tips.max_tip']['source']]);
        $this->assertSame(['bool', true, null], [$rows['acme_tips.show_tips']['type'], $rows['acme_tips.show_tips']['value'], $rows['acme_tips.show_tips']['min']]);

        // A whole number sent as text is stored as a number even when the caller did not convert it.
        $settings->set('acme_tips.max_tip', '12', $this->admin->id);
        $this->assertSame('number', $this->storedType('acme_tips.max_tip'));
    }
}
