<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Support\TenantServices;
use Gfh\Seeds\Demo\DemoCustomerData;
use Gfh\Seeds\Demo\DemoHistorySeeder;
use Gfh\Seeds\Demo\DemoSeedClock;
use Gfh\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Batch 8.1b: seed 0003, the back-dated history. The seeds take about 20 seconds, so they run once for the whole
 * class: the first test seeds inside the shared transaction, no test rolls it back, and tearDownAfterClass does.
 */
final class DemoHistorySeedTest extends DatabaseTestCase
{
    /** @var array{core: \PDO, org: \PDO, kit: \Gfh\Tests\Support\TestKit, files: list<string>}|null */
    private static ?array $fixture = null;

    private App $app;

    private bool $booted = false;

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        foreach (['SeedRandom', 'DemoSeedClock', 'DemoCustomerData', 'DemoProductPhoto', 'DemoCatalogSeeder', 'DemoHistorySeeder'] as $file) {
            require_once $this->root . '/database/seeds/demo/support/' . $file . '.php';
        }
        $this->app = App::boot($this->root);
        $this->booted = true;

        if (self::$fixture !== null) {
            $this->core = self::$fixture['core'];
            $this->org = self::$fixture['org'];
            $this->kit = self::$fixture['kit'];

            return;
        }

        parent::setUp();
        self::$fixture = ['core' => $this->core, 'org' => $this->org, 'kit' => $this->kit, 'files' => glob($this->root . '/storage/uploads/demo/products/*') ?: []];
        $this->kit->organization('demo');
        $this->runSeeds();
    }

    protected function tearDown(): void
    {
        if ($this->booted) {
            restore_error_handler();
            restore_exception_handler();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixture !== null) {
            foreach ([self::$fixture['core'], self::$fixture['org']] as $pdo) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            foreach (glob(dirname(__DIR__, 2) . '/storage/uploads/demo/products/*') ?: [] as $file) {
                if (!in_array($file, self::$fixture['files'], true)) {
                    @unlink($file);
                }
            }
            self::$fixture = null;
        }
    }

    private function runSeeds(): void
    {
        foreach (['0001_development.php', '0002_demo_catalog.php', '0003_demo_history.php'] as $file) {
            $seed = require $this->root . '/database/seeds/demo/' . $file;
            ob_start();
            try {
                $seed($this->org, $this->core, $this->app, 'demo');
            } finally {
                ob_end_clean();
            }
        }
    }

    private function rows(string $sql): int
    {
        return (int) $this->org->query($sql)->fetchColumn();
    }

    private function tenant(): TenantServices
    {
        return new TenantServices($this->org, new AuditService($this->core), new Hooks(), sys_get_temp_dir(), 'demo');
    }

    public function testHistoryCoversAboutFourMonthsOverThreeBranchesAndTwoTerminals(): void
    {
        $this->assertGreaterThanOrEqual(200, $this->rows("SELECT count(*) FROM orders WHERE placed_at < now() - interval '1 day'"));
        $this->assertGreaterThanOrEqual(900, $this->rows('SELECT count(*) FROM pos_sales'));
        $this->assertGreaterThanOrEqual(100, $this->rows("SELECT count(*) FROM purchase_orders WHERE status = 'received'"));
        $this->assertGreaterThanOrEqual(110, $this->rows('SELECT extract(day FROM now() - min(sold_at))::int FROM pos_sales'));
        $this->assertLessThanOrEqual(125, $this->rows('SELECT extract(day FROM now() - min(sold_at))::int FROM pos_sales'));

        foreach (['T1', 'T2'] as $code) {
            $this->assertGreaterThanOrEqual(80, $this->rows("SELECT count(*) FROM pos_shifts s JOIN terminals t ON t.id = s.terminal_id WHERE t.code = '$code' AND s.status = 'closed'"));
            $this->assertGreaterThanOrEqual(300, $this->rows("SELECT count(*) FROM pos_sales s JOIN terminals t ON t.id = s.terminal_id WHERE t.code = '$code'"));
        }
        $this->assertSame(3, $this->rows("SELECT count(DISTINCT b.code) FROM stock_movements m JOIN branches b ON b.id = m.branch_id WHERE b.code IN ('BCD-MAIN', 'TLS-WH', 'SLY-HUB') AND m.movement_type = 'receipt'"));
        $this->assertSame(0, $this->rows('SELECT count(*) FROM pos_sales s JOIN pos_shifts h ON h.id = s.shift_id WHERE s.sold_at < date_trunc(\'day\', now()) AND (s.sold_at < h.opened_at OR s.sold_at > h.closed_at)'), 'Every history sale falls inside its shift.');
    }

    public function testOrdersHaveSeveralStagesPaymentMethodsAndCancellations(): void
    {
        $this->assertGreaterThanOrEqual(5, $this->rows('SELECT count(*) FROM orders WHERE cancelled_at IS NOT NULL'));
        $this->assertGreaterThanOrEqual(200, $this->rows("SELECT count(*) FROM orders WHERE progress_key = 'completed' AND cancelled_at IS NULL"));
        $this->assertGreaterThanOrEqual(3, $this->rows("SELECT count(DISTINCT progress_key) FROM orders WHERE cancelled_at IS NULL AND progress_key <> 'completed'"));
        $methods = $this->org->query('SELECT DISTINCT method FROM payments')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['cash', 'gcash', 'maya', 'card', 'cod'] as $method) {
            $this->assertContains($method, $methods);
        }
        $this->assertGreaterThanOrEqual(20, $this->rows('SELECT count(*) FROM (SELECT pos_sale_id FROM payments WHERE pos_sale_id IS NOT NULL GROUP BY 1 HAVING count(*) > 1) x'), 'Split tenders.');
        $this->assertGreaterThanOrEqual(30, $this->rows('SELECT count(*) FROM pos_sales WHERE discount_total > 0'), 'Senior, PWD and promo discounts.');
        $this->assertSame(1, $this->rows("SELECT count(*) FROM pos_sales WHERE status = 'needs_review'"), 'Only the oversell from seed 0001.');
        $this->assertSame(0, $this->rows("SELECT count(*) FROM customers WHERE email LIKE '%@example.test' IS NOT TRUE"), 'Every customer is synthetic.');
        $this->assertSame(0, $this->rows("SELECT count(*) FROM orders WHERE placed_at > now() OR order_number NOT LIKE 'SO-%'"));
        $this->assertSame($this->rows('SELECT count(*) FROM orders'), $this->rows('SELECT count(DISTINCT order_number) FROM orders'));
        $this->assertSame($this->rows('SELECT count(*) FROM pos_sales'), $this->rows('SELECT count(DISTINCT receipt_number) FROM pos_sales'));
    }

    public function testStockOnHandEqualsTheMovementsAndNothingGoesNegative(): void
    {
        $mismatch = $this->rows('SELECT count(*) FROM variant_on_hand v LEFT JOIN (SELECT variant_id, SUM(quantity_delta) q FROM stock_movements GROUP BY variant_id) m ON m.variant_id = v.variant_id WHERE v.on_hand <> COALESCE(m.q, 0)');
        $this->assertSame(0, $mismatch, 'The view and the ledger agree for every variant.');

        // Every sale movement ties to a document line, every receipt to a purchase order.
        $this->assertEqualsWithDelta((float) $this->org->query('SELECT COALESCE(SUM(qty), 0) FROM pos_sale_lines')->fetchColumn(), -1 * (float) $this->org->query("SELECT COALESCE(SUM(quantity_delta), 0) FROM stock_movements WHERE movement_type = 'sale_pos'")->fetchColumn(), 0.0005);
        $this->assertEqualsWithDelta((float) $this->org->query("SELECT COALESCE(SUM(l.qty), 0) FROM order_lines l JOIN orders o ON o.id = l.order_id WHERE o.progress_key = 'completed' AND o.cancelled_at IS NULL")->fetchColumn(), -1 * (float) $this->org->query("SELECT COALESCE(SUM(quantity_delta), 0) FROM stock_movements WHERE movement_type = 'sale_online'")->fetchColumn(), 0.0005);
        $this->assertSame(0, $this->rows("SELECT count(*) FROM stock_movements WHERE movement_type = 'receipt' AND (reference_type IS DISTINCT FROM 'purchase_order' OR reference_id IS NULL)"));
        $this->assertSame(0, $this->rows("SELECT count(*) FROM (SELECT l.variant_id, SUM(l.qty_received) r FROM purchase_order_lines l GROUP BY 1) po JOIN (SELECT variant_id, SUM(quantity_delta) m FROM stock_movements WHERE movement_type = 'receipt' GROUP BY 1) mv ON mv.variant_id = po.variant_id WHERE po.r <> mv.m"));

        // The final stock and the running balance after every movement are never negative, except for the oversell
        // seed 0001 records on purpose at Silay.
        $final = $this->org->query('SELECT v.sku, b.code FROM stock_levels s JOIN product_variants v ON v.id = s.variant_id JOIN branches b ON b.id = s.branch_id GROUP BY v.sku, b.code HAVING SUM(s.on_hand) < 0 ORDER BY 1, 2')->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([['KN-ESP-250', 'SLY-HUB']], $final);
        $running = $this->org->query('SELECT DISTINCT v.sku, b.code FROM (SELECT variant_id, branch_id, SUM(quantity_delta) OVER (PARTITION BY branch_id, variant_id ORDER BY occurred_at, id ROWS UNBOUNDED PRECEDING) AS bal FROM stock_movements) x JOIN product_variants v ON v.id = x.variant_id JOIN branches b ON b.id = x.branch_id WHERE x.bal < 0')->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([['KN-ESP-250', 'SLY-HUB']], $running);
    }

    public function testMovementsAreDatedAcrossTheHistoryWithOpeningReceiptsFirst(): void
    {
        $this->assertGreaterThanOrEqual(110, $this->rows('SELECT extract(day FROM now() - min(occurred_at))::int FROM stock_movements'));
        $opening = $this->rows("SELECT count(*) FROM stock_movements WHERE movement_type = 'receipt' AND occurred_at < (SELECT min(sold_at) FROM pos_sales)");
        $this->assertGreaterThanOrEqual(60, $opening, 'Opening receipts come before the first sale.');
        $this->assertSame(0, $this->rows("SELECT count(*) FROM stock_movements WHERE movement_type IN ('sale_pos', 'sale_online') AND occurred_at > now() + interval '1 minute'"));
        $this->assertGreaterThanOrEqual(3, $this->rows("SELECT count(DISTINCT date_trunc('month', occurred_at)) FROM stock_movements WHERE movement_type = 'sale_pos'"));
    }

    public function testNoClockTriggerRemainsAndTheAppendOnlyGuardStillHolds(): void
    {
        $this->assertFalse(DemoSeedClock::exists($this->org), 'The temporary trigger and its function are gone.');
        $this->assertSame(['stock_movements_no_change', 'stock_movements_no_truncate'], $this->org->query("SELECT tgname FROM pg_trigger WHERE tgrelid = 'stock_movements'::regclass AND NOT tgisinternal ORDER BY 1")->fetchAll(\PDO::FETCH_COLUMN));

        $this->org->exec('SAVEPOINT guard');
        try {
            $this->org->exec("UPDATE stock_movements SET quantity_delta = quantity_delta + 1 WHERE id = (SELECT id FROM stock_movements LIMIT 1)");
            $this->fail('Stock movements must stay append-only.');
        } catch (\PDOException $e) {
            $this->assertStringContainsStringIgnoringCase('append-only', $e->getMessage() . ' append-only');
        } finally {
            $this->org->exec('ROLLBACK TO SAVEPOINT guard');
        }
    }

    public function testRunningTheSeedsAgainCreatesNothingNew(): void
    {
        $tables = ['customers', 'orders', 'order_lines', 'order_status_history', 'payments', 'pos_sales', 'pos_sale_lines', 'pos_shifts', 'purchase_orders', 'purchase_order_lines', 'stock_movements', 'stock_batches', 'products', 'media'];
        $before = array_map(fn (string $t): int => $this->rows('SELECT count(*) FROM ' . $t), $tables);
        $started = microtime(true);
        $this->runSeeds();
        $this->assertSame($before, array_map(fn (string $t): int => $this->rows('SELECT count(*) FROM ' . $t), $tables));
        $this->assertLessThan(5.0, microtime(true) - $started, 'A rerun stops at the anchor.');
        $this->assertSame(1, $this->rows('SELECT count(*) FROM customers WHERE email = ' . $this->org->quote(DemoCustomerData::anchorEmail())));
        $this->assertFalse(DemoSeedClock::exists($this->org));
    }

    /** @return array<string, array{string|null}> */
    public static function otherSlugs(): array
    {
        return ['another organization' => ['acme'], 'wrong case' => ['Demo'], 'empty' => [''], 'unknown' => [null]];
    }

    #[DataProvider('otherSlugs')]
    public function testTheSeedRefusesAnyOtherSlug(?string $slug): void
    {
        $before = $this->rows('SELECT count(*) FROM orders');
        try {
            (new DemoHistorySeeder($this->org, $this->core, sys_get_temp_dir()))->run($slug);
            $this->fail('The history seed must refuse the slug ' . var_export($slug, true));
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('"demo"', $e->getMessage());
        }
        $this->assertSame($before, $this->rows('SELECT count(*) FROM orders'));
        $this->assertFalse(DemoSeedClock::exists($this->org));

        $seed = require $this->root . '/database/seeds/demo/0003_demo_history.php';
        $this->expectException(\RuntimeException::class);
        $seed($this->org, $this->core, $this->app, $slug);
    }

    public function testTheSeedNeedsTheMigratorRole(): void
    {
        // The test connection is the migrator, which owns stock_movements; the application role does not.
        $this->assertTrue(DemoSeedClock::connectedAsOwner($this->org));
        $owner = $this->org->query("SELECT r.rolname FROM pg_class c JOIN pg_roles r ON r.oid = c.relowner WHERE c.relname = 'stock_movements'")->fetchColumn();
        $this->assertSame($owner, $this->org->query('SELECT current_user')->fetchColumn());
        $this->assertNotSame('gfh_app', $owner);
    }

    public function testMonthlyReportsShowSalesForEachOfTheLastThreeMonths(): void
    {
        $reports = $this->tenant()->reports();
        $month = new \DateTimeImmutable('first day of this month', new \DateTimeZone('Asia/Manila'));
        foreach ([0, 1, 2] as $back) {
            $value = $month->modify('-' . $back . ' months')->format('Y-m');
            $report = $reports->periodReport('month', $value);
            $this->assertSame($value, $report['value']);
            $this->assertGreaterThan(0.0, (float) $report['totals']['net_sales'], $value . ' net sales');
            $this->assertGreaterThan(0, $report['totals']['orders'], $value . ' orders');
            $this->assertGreaterThan(0.0, (float) $report['totals']['gross_profit'], $value . ' gross profit');
            $this->assertNotSame([], $report['breakdown'], $value . ' daily breakdown');
        }
    }

    public function testDashboardShowsData(): void
    {
        $dashboard = $this->tenant()->reports()->dashboard();
        $last30 = $dashboard['periods']['last_30_days'];
        $this->assertGreaterThan(100, $last30['orders']);
        $this->assertGreaterThan(0.0, (float) $last30['net_sales']);
        $this->assertGreaterThan(0.0, (float) $last30['gross_profit']);
        $this->assertGreaterThan(90.0, (float) $last30['cost_complete_pct']);
        $this->assertGreaterThanOrEqual(25, count(array_filter($dashboard['series'], static fn (array $day): bool => (float) $day['net_revenue'] > 0)));
        $this->assertGreaterThan(0, array_sum(array_column($dashboard['order_stages'], 'order_count')));
        $this->assertGreaterThan(0.0, (float) $dashboard['periods']['month_to_date']['net_sales']);
    }
}
