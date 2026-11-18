<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Controller\Admin\ReportsController;
use Gfh\Core\Database\Database;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\Request;
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\Services;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Organization;
use Gfh\Core\Tenancy\Tenant;
use Gfh\Tests\Support\DatabaseTestCase;

/**
 * Batch 5.4a: the Admin Reports page (Day, Month and Year), its permissions, the Manila day boundary, agreement with
 * the dashboard, empty and invalid periods, the reports.tabs hook, and the Customers Orders/Lifetime spent fix.
 *
 * Sales are made through PosService::syncSale on fixed days in March 2026 (always in the past), at 23:30 and 00:30
 * Manila time, so the tests do not depend on today's date.
 */
final class AdminBatch54aTest extends DatabaseTestCase
{
    private string $sessionDir = '';
    private string $variant = '';
    /** @var array<string, array{branch: string, terminal: string, shift: string}> */
    private array $desks = [];
    private int $receipt = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionDir = sys_get_temp_dir() . '/gfh-test54a-sessions-' . bin2hex(random_bytes(4));
        mkdir($this->sessionDir, 0777, true);

        $this->shop->steps();
        $this->variant = $this->shop->variant(['sku' => 'REPORT-1', 'price' => '112.00']);
        foreach (['north' => 'N1', 'south' => 'S1'] as $key => $code) {
            $branch = $this->shop->branch($code, true, false);
            $terminal = $this->shop->terminal($branch, 'T' . substr($code, 0, 1) . '1');
            $this->desks[$key] = ['branch' => $branch, 'terminal' => $terminal, 'shift' => $this->shop->shift($terminal, '1000.00')];
            $this->shop->stock($branch, $this->variant, '50', '50.00');
        }
    }

    protected function tearDown(): void
    {
        if ($this->sessionDir !== '' && is_dir($this->sessionDir)) {
            foreach (glob($this->sessionDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->sessionDir);
        }
        parent::tearDown();
    }

    private function bootServices(): Services
    {
        $app = App::boot(dirname(__DIR__, 2));
        restore_error_handler();
        restore_exception_handler();

        return new Services($app, false, $this->core);
    }

    private function makeTenant(Organization $organization, ?string $role = null): Tenant
    {
        $membership = $role === null ? null : ['role' => $role, 'status' => 'active'];

        return new Tenant($organization, new Database($this->kit->config), $membership, $this->org);
    }

    /** @param array<string, mixed> $query */
    private function staffRequest(string $path, Services $services, Tenant $tenant, ?StaffUser $staff, array $query = []): Request
    {
        $session = new Session('staff', $services->app()->config(), $this->sessionDir, false);
        $request = (new Request('GET', $path, query: $query))
            ->withAttribute('tenant', $tenant)
            ->withAttribute('session', $session);

        return $staff === null ? $request : $request->withAttribute('staff', $staff);
    }

    /** One POS sale of $quantity units (each sells for 112.00 with 12.00 VAT and costs 50.00). */
    private function sell(string $soldAt, string $desk = 'north', int $quantity = 1): void
    {
        $this->receipt++;
        $d = $this->desks[$desk];
        $code = 'T' . ($desk === 'north' ? 'N' : 'S') . '1';
        $result = $this->shop->services->pos()->syncSale([
            'id' => Uuid::v4(),
            'receipt_number' => sprintf('%s-%06d', $code, $this->receipt),
            'terminal_id' => $d['terminal'],
            'shift_id' => $d['shift'],
            'sold_at' => $soldAt,
            'lines' => [['variant_id' => $this->variant, 'quantity' => (string) $quantity, 'unit_price' => '112.00']],
            'payments' => [['method' => 'cash', 'amount' => Decimal::format(11200 * $quantity, 2)]],
        ]);
        $this->assertSame('completed', $result['status']);
    }

    private function cents(mixed $value): int
    {
        return Decimal::parse((string) $value, 2) ?? 0;
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function assertSameTotals(array $a, array $b, string $message = ''): void
    {
        foreach (['net_sales', 'gross_profit', 'cost', 'cost_complete_pct'] as $key) {
            $this->assertSame($a[$key], $b[$key], "$message $key");
        }
        $this->assertSame((int) $a['orders'], (int) $b['orders'], "$message orders");
        $this->assertSame(\Gfh\Core\Support\Format::qty((string) $a['units']), \Gfh\Core\Support\Format::qty((string) $b['units']), "$message units");
    }

    // ---------------------------------------------------------------- permissions

    public function testTheCashierIsRefusedTheReportsPageAndSoIsStaff(): void
    {
        $services = $this->bootServices();
        $org = $this->kit->organization('rep-' . bin2hex(random_bytes(3)));
        $admin = $this->kit->member($org, 'admin@example.test', 'admin');
        $manager = $this->kit->member($org, 'manager@example.test', 'manager');
        $staff = $this->kit->member($org, 'staff@example.test', 'staff');
        $cashier = $this->kit->member($org, 'cashier@example.test', 'cashier');
        $path = '/' . $org->slug . '/admin/reports';
        $controller = new ReportsController($services);

        $this->assertHttpStatus(403, fn () => $controller->index($this->staffRequest($path, $services, $this->makeTenant($org, 'cashier'), $cashier)));
        $this->assertHttpStatus(403, fn () => $controller->index($this->staffRequest($path, $services, $this->makeTenant($org, 'staff'), $staff)));
        $this->assertHttpStatus(401, fn () => $controller->index($this->staffRequest($path, $services, $this->makeTenant($org, 'admin'), null)));

        $this->assertSame(200, $controller->index($this->staffRequest($path, $services, $this->makeTenant($org, 'admin'), $admin))->status());
        $this->assertSame(200, $controller->index($this->staffRequest($path, $services, $this->makeTenant($org, 'manager'), $manager))->status());
    }

    public function testTheSidebarShowsReportsOnlyToPeopleWhoMayOpenIt(): void
    {
        $services = $this->bootServices();
        $org = $this->kit->organization('nav-' . bin2hex(random_bytes(3)));
        $manager = $this->kit->member($org, 'manager@example.test', 'manager');
        $cashier = $this->kit->member($org, 'cashier@example.test', 'cashier');
        $keysFor = static function (Tenant $tenant, StaffUser $user) use ($services, $org): array {
            return array_column($services->adminNavigation($tenant)->items($user, $org, '', '/' . $org->slug . '/admin'), 'key');
        };

        $this->assertContains('reports', $keysFor($this->makeTenant($org, 'manager'), $manager));
        $this->assertNotContains('reports', $keysFor($this->makeTenant($org, 'cashier'), $cashier));
    }

    // ---------------------------------------------------------------- the Manila day boundary

    public function testADayBelongsToManilaTimeNotUtc(): void
    {
        // In UTC all four sales fall on 14 Mar and 31 Mar. In Manila 00:30 is already the next day.
        $this->sell('2026-03-14T23:30:00+08:00');
        $this->sell('2026-03-15T00:30:00+08:00');
        $this->sell('2026-03-31T23:30:00+08:00');
        $this->sell('2026-04-01T00:30:00+08:00');
        $reports = $this->shop->services->reports();

        $this->assertSame(1, (int) $reports->periodReport('day', '2026-03-14')['totals']['orders'], '23:30 Manila belongs to 14 Mar');
        $this->assertSame(1, (int) $reports->periodReport('day', '2026-03-15')['totals']['orders'], '00:30 Manila belongs to 15 Mar');
        $this->assertSame('100.00', $reports->periodReport('day', '2026-03-15')['totals']['net_sales']);
        $this->assertSame(3, (int) $reports->periodReport('month', '2026-03')['totals']['orders'], '31 Mar 23:30 is March, 01 Apr 00:30 is April');
        $this->assertSame(1, (int) $reports->periodReport('month', '2026-04')['totals']['orders']);
        $this->assertSame(4, (int) $reports->periodReport('year', '2026')['totals']['orders']);
    }

    public function testTheDefaultDayFollowsTheManilaClock(): void
    {
        $this->sell('2026-03-14T23:30:00+08:00');
        $this->sell('2026-03-15T00:30:00+08:00');
        $reports = $this->shop->services->reports();

        // 16:30 UTC on 14 Mar is 00:30 on 15 Mar in Manila: the report for "today" is 15 Mar, not 14 Mar.
        $afterMidnight = new \DateTimeImmutable('2026-03-14T16:30:00+00:00');
        $report = $reports->periodReport('day', '', $afterMidnight);
        $this->assertSame('2026-03-15', $report['value']);
        $this->assertSame(1, (int) $report['totals']['orders']);

        // 15:30 UTC on 14 Mar is 23:30 in Manila: still 14 Mar.
        $beforeMidnight = new \DateTimeImmutable('2026-03-14T15:30:00+00:00');
        $this->assertSame('2026-03-14', $reports->periodReport('day', '', $beforeMidnight)['value']);

        // The month and the year roll over at Manila midnight too: 00:30 on 1 Apr Manila is still 31 Mar in UTC.
        $this->assertSame('2026-03', $reports->periodReport('month', '', new \DateTimeImmutable('2026-03-31T15:30:00+00:00'))['value']);
        $this->assertSame('2026-04', $reports->periodReport('month', '', new \DateTimeImmutable('2026-03-31T16:30:00+00:00'))['value']);
        $this->assertSame('2027', $reports->periodReport('year', '', new \DateTimeImmutable('2026-12-31T16:30:00+00:00'))['value']);
    }

    // ---------------------------------------------------------------- numbers: exact, consistent, and the same as the dashboard

    public function testDayMonthAndYearTotalsBreakdownsAndTheDashboardAllAgree(): void
    {
        $this->sell('2026-03-14T10:00:00+08:00', 'north', 1);
        $this->sell('2026-03-14T15:00:00+08:00', 'south', 2);
        $this->sell('2026-03-15T09:00:00+08:00', 'north', 1);
        $reports = $this->shop->services->reports();

        $day = $reports->periodReport('day', '2026-03-14');
        $this->assertSame('300.00', $day['totals']['net_sales']);
        $this->assertSame('150.00', $day['totals']['cost']);
        $this->assertSame('150.00', $day['totals']['gross_profit']);
        $this->assertSame('3', \Gfh\Core\Support\Format::qty((string) $day['totals']['units']));
        $this->assertSame(2, (int) $day['totals']['orders']);

        $byBranch = [];
        foreach ($day['breakdown']['rows'] as $row) {
            $byBranch[$row['key']] = $row;
        }
        $this->assertSame('branch', $day['breakdown']['kind']);
        $this->assertSame('100.00', $byBranch[$this->desks['north']['branch']]['net_sales']);
        $this->assertSame('200.00', $byBranch[$this->desks['south']['branch']]['net_sales']);
        $this->assertSame('100.00', $byBranch[$this->desks['south']['branch']]['gross_profit']);
        $this->assertSame($this->cents($day['totals']['net_sales']), array_sum(array_map(fn (array $r): int => $this->cents($r['net_sales']), $day['breakdown']['rows'])));
        $this->assertSame(2, array_sum(array_map(static fn (array $r): int => $r['orders'], $day['breakdown']['rows'])));

        $month = $reports->periodReport('month', '2026-03');
        $this->assertSame('400.00', $month['totals']['net_sales']);
        $this->assertSame('day', $month['breakdown']['kind']);
        $this->assertCount(31, $month['breakdown']['rows'], 'every day of March is listed');
        $days = array_column($month['breakdown']['rows'], null, 'key');
        $this->assertSame('300.00', $days['2026-03-14']['net_sales']);
        $this->assertSame('0.00', $days['2026-03-16']['net_sales']);
        $this->assertSame($this->cents($month['totals']['net_sales']), array_sum(array_map(fn (array $r): int => $this->cents($r['net_sales']), $month['breakdown']['rows'])));

        $year = $reports->periodReport('year', '2026');
        $this->assertSame('400.00', $year['totals']['net_sales']);
        $this->assertSame('month', $year['breakdown']['kind']);
        $this->assertCount(12, $year['breakdown']['rows']);
        $months = array_column($year['breakdown']['rows'], null, 'key');
        $this->assertSame('400.00', $months['2026-03']['net_sales']);
        $this->assertSame('0.00', $months['2026-04']['net_sales']);

        // The dashboard reads the same views: its Today and Month-to-date equal this page's Day and Month.
        $this->assertSameTotals($reports->dashboard(new \DateTimeImmutable('2026-03-14T12:00:00+08:00'))['periods']['today'], $day['totals'], 'Today vs Day');
        $this->assertSameTotals($reports->dashboard(new \DateTimeImmutable('2026-03-31T12:00:00+08:00'))['periods']['month_to_date'], $month['totals'], 'Month to date vs Month');
    }

    // ---------------------------------------------------------------- empty and invalid periods

    public function testAnEmptyPeriodShowsZerosNotErrors(): void
    {
        $reports = $this->shop->services->reports();

        $day = $reports->periodReport('day', '2025-01-01');
        $this->assertSame('0.00', $day['totals']['net_sales']);
        $this->assertSame('0.00', $day['totals']['gross_profit']);
        $this->assertSame('0.00', $day['totals']['cost']);
        $this->assertSame(0, (int) $day['totals']['orders']);
        $this->assertCount(2, $day['breakdown']['rows'], 'both branches are listed, with zeros');
        $this->assertSame('0.00', $day['breakdown']['rows'][0]['net_sales']);

        $this->assertCount(28, $reports->periodReport('month', '2025-02')['breakdown']['rows']);
        $this->assertCount(29, $reports->periodReport('month', '2024-02')['breakdown']['rows'], 'a leap year');
        $this->assertSame('0.00', $reports->periodReport('year', '2025')['totals']['net_sales']);
    }

    public function testInvalidDatesAreRefusedWithAFieldError(): void
    {
        $reports = $this->shop->services->reports();
        $cases = [
            ['day', 'not-a-date', 'date'],
            ['day', '2026-02-30', 'date'],
            ['day', '2026-13-01', 'date'],
            ['day', '16/11/2026', 'date'],
            ['day', '1999-12-31', 'date'],
            ['month', '2026-13', 'month'],
            ['month', 'November', 'month'],
            ['month', '2101-01', 'month'],
            ['year', '26', 'year'],
            ['year', '1999', 'year'],
            ['year', '20x6', 'year'],
        ];
        foreach ($cases as [$view, $value, $field]) {
            $error = $this->assertHttpStatus(422, fn () => $reports->periodReport($view, $value));
            $this->assertArrayHasKey($field, $error->fields(), "$view $value");
        }
    }

    // ---------------------------------------------------------------- the page

    public function testThePageShowsTheFiguresTheRuleTheChartAndTheFieldError(): void
    {
        $this->sell('2026-03-14T10:00:00+08:00', 'north', 1);
        $this->sell('2026-03-14T15:00:00+08:00', 'south', 2);
        $services = $this->bootServices();
        $org = $this->kit->organization('pg-' . bin2hex(random_bytes(3)));
        $admin = $this->kit->member($org, 'admin@example.test', 'admin');
        $tenant = $this->makeTenant($org, 'admin');
        $controller = new ReportsController($services);
        $path = '/' . $org->slug . '/admin/reports';

        $page = $controller->index($this->staffRequest($path, $services, $tenant, $admin, ['view' => 'day', 'date' => '2026-03-14']));
        $body = $page->body();
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('₱300.00', $body);
        $this->assertStringContainsString('14 Mar 2026', $body);
        $this->assertStringContainsString('whether or not it has been paid or delivered', $body, 'the page states what net sales counts');
        $this->assertStringContainsString('Asia/Manila', $body);
        $this->assertStringContainsString('data-chart="bar"', $body);
        $this->assertStringContainsString('view=month&amp;month=2026-03', $body, 'switching to Month keeps the same moment');
        $this->assertStringContainsString('date=2026-03-13', $body, 'a link to the previous day');

        $month = $controller->index($this->staffRequest($path, $services, $tenant, $admin, ['view' => 'month', 'month' => '2026-03']))->body();
        $this->assertStringContainsString('data-chart="line"', $month);
        $this->assertStringContainsString('₱300.00', $month);

        $empty = $controller->index($this->staffRequest($path, $services, $tenant, $admin, ['view' => 'year', 'year' => '2025']));
        $this->assertSame(200, $empty->status());
        $this->assertStringContainsString('₱0.00', $empty->body());
        $this->assertStringContainsString('No sales in this period', $empty->body());

        $bad = $controller->index($this->staffRequest($path, $services, $tenant, $admin, ['view' => 'day', 'date' => '2026-02-30']));
        $this->assertSame(422, $bad->status());
        $this->assertStringContainsString('aria-invalid="true"', $bad->body());
        $this->assertStringContainsString('Enter a date as YYYY-MM-DD', $bad->body());

        // Anything unknown for the view falls back to the day view; a script in the date is only ever shown escaped.
        $odd = $controller->index($this->staffRequest($path, $services, $tenant, $admin, ['view' => 'week', 'date' => '<script>alert(1)</script>']));
        $this->assertSame(422, $odd->status());
        $this->assertStringNotContainsString('<script>alert(1)</script>', $odd->body());
    }

    public function testAnExtensionTabRendersThroughTheReportsTabsHook(): void
    {
        $this->sell('2026-03-14T10:00:00+08:00');
        $services = $this->bootServices();
        $org = $this->kit->organization('hk-' . bin2hex(random_bytes(3)));
        $admin = $this->kit->member($org, 'admin@example.test', 'admin');
        $tenant = $this->makeTenant($org, 'admin');
        $path = '/' . $org->slug . '/admin/reports';

        $seen = [];
        $services->forTenant($tenant)->hooks()->add(Hooks::REPORTS_TABS, static function (array $tabs, array $context) use (&$seen): array {
            $seen = $context;
            $tabs[] = ['id' => 'margin notes', 'label' => 'Margin notes', 'contentHtml' => '<p>Extension says ' . htmlspecialchars($context['from'], ENT_QUOTES) . '</p>'];
            $tabs[] = ['id' => 'sales', 'label' => 'Impostor', 'contentHtml' => '<p>replaces the built-in tab</p>'];
            $tabs[] = ['id' => 'blank', 'label' => '', 'contentHtml' => '<p>no label, dropped</p>'];

            return $tabs;
        });

        $body = (new ReportsController($services))->index($this->staffRequest($path, $services, $tenant, $admin, ['view' => 'day', 'date' => '2026-03-14']))->body();

        $this->assertStringContainsString('role="tablist"', $body);
        $this->assertStringContainsString('>Sales</button>', $body);
        $this->assertStringContainsString('>Margin notes</button>', $body);
        $this->assertStringContainsString('id="panel-margin-notes"', $body, 'the tab id is cleaned');
        $this->assertStringContainsString('Extension says 2026-03-14', $body);
        $this->assertStringNotContainsString('Impostor', $body);
        $this->assertStringNotContainsString('no label, dropped', $body);
        $this->assertStringContainsString('₱100.00', $body, 'the built-in report is still the first tab');
        $this->assertSame('day', $seen['view']);
        $this->assertSame('2026-03-14', $seen['to']);
        $this->assertSame('100.00', $seen['totals']['net_sales']);
    }

    public function testThePageHasNoTabsWhenNoExtensionAddsOne(): void
    {
        $services = $this->bootServices();
        $org = $this->kit->organization('nt-' . bin2hex(random_bytes(3)));
        $admin = $this->kit->member($org, 'admin@example.test', 'admin');
        $tenant = $this->makeTenant($org, 'admin');

        $body = (new ReportsController($services))->index($this->staffRequest('/' . $org->slug . '/admin/reports', $services, $tenant, $admin))->body();

        $this->assertStringNotContainsString('role="tablist"', $body);
    }

    // ---------------------------------------------------------------- Customers: Orders and Lifetime spent agree

    public function testCustomersOrdersCountAndLifetimeSpentBothLeaveOutCancelledOrders(): void
    {
        $orders = $this->shop->services->orders();
        $customer = $this->shop->customer();
        $fulfillment = ['method' => 'pickup', 'branch_id' => $this->desks['north']['branch']];
        $first = $orders->createOrder($customer, [['variant_id' => $this->variant, 'quantity' => '1']], $fulfillment, []);
        $second = $orders->createOrder($customer, [['variant_id' => $this->variant, 'quantity' => '2']], $fulfillment, []);
        $orders->cancel($second['id'], 'Customer changed their mind', null);

        $list = $orders->listCustomers(ListQuery::fromRequest(new Request('GET', '/'), ['name' => 'c.full_name', 'orders' => 'order_count', 'spent' => 'lifetime_total'], 'name'));
        $row = null;
        foreach ($list['items'] as $item) {
            if ($item['id'] === $customer['id']) {
                $row = $item;
            }
        }

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['order_count'], 'the cancelled order is not counted');
        $this->assertSame($first['total'], (string) $row['lifetime_total'], 'and its total is not added');
    }
}
