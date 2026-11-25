<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Console\SeedCommand;
use Gfh\Core\Database\SeedRunner;
use Gfh\Core\Http\Request;
use Gfh\Core\Support\Services;
use Gfh\Seeds\Demo\DemoCatalogSeeder;
use Gfh\Seeds\Demo\DemoProductPhoto;
use Gfh\Tests\Support\DatabaseTestCase;

/** Batch 8.1a: seed 0002 (24 products, photos, opening stock), the slug argument and the --demo guard. */
final class DemoCatalogSeedTest extends DatabaseTestCase
{
    private App $app;

    private bool $booted = false;

    private string $root;

    /** @var list<string> files that existed before the test */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/database/seeds/demo/support/DemoProductPhoto.php';
        require_once $this->root . '/database/seeds/demo/support/DemoCatalogSeeder.php';

        $this->files = glob($this->root . '/storage/uploads/demo/products/*') ?: [];
        $this->app = App::boot($this->root);
        $this->booted = true;
        $this->kit->organization('demo');
        $this->runSeeds();
    }

    protected function tearDown(): void
    {
        // Photos are files, so the rolled-back transaction does not remove them: delete the ones this test added.
        foreach (glob($this->root . '/storage/uploads/demo/products/*') ?: [] as $file) {
            if (!in_array($file, $this->files, true)) {
                @unlink($file);
            }
        }
        if ($this->booted) {
            restore_error_handler();
            restore_exception_handler();
        }
        parent::tearDown();
    }

    private function runSeeds(): void
    {
        foreach (['0001_development.php', '0002_demo_catalog.php'] as $file) {
            $seed = require $this->root . '/database/seeds/demo/' . $file;
            $seed($this->org, $this->core, $this->app, 'demo');
        }
    }

    private function rows(string $sql): int
    {
        return (int) $this->org->query($sql)->fetchColumn();
    }

    public function testCatalogHasTwentyFourProductsAcrossThreeLines(): void
    {
        $this->assertSame(24, $this->rows('SELECT count(*) FROM products'));
        $roots = $this->org->query("SELECT name FROM categories WHERE parent_id IS NULL AND name IN ('Beverages', 'Pantry & Supplies', 'Home Appliances', 'Furniture & Decor')")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(4, $roots);
        $this->assertGreaterThanOrEqual(5, $this->rows('SELECT count(*) FROM categories WHERE parent_id IS NOT NULL AND parent_id IN (SELECT id FROM categories WHERE name IN (\'Home Appliances\', \'Furniture & Decor\'))'));
        $this->assertSame(0, $this->rows("SELECT count(*) FROM brands WHERE name ILIKE 'gfh%'"));
    }

    public function testDecimalVariantsTiersAndBatchesExist(): void
    {
        $this->assertGreaterThanOrEqual(1, $this->rows("SELECT count(*) FROM product_variants WHERE allow_decimal_qty AND qty_step = 0.1 AND min_qty = 0.2"));
        $this->assertGreaterThanOrEqual(2, $this->rows("SELECT count(*) FROM product_variants WHERE allow_decimal_qty"));
        $this->assertGreaterThanOrEqual(4, $this->rows("SELECT count(*) FROM price_tiers WHERE basis = 'quantity'"));
        $this->assertGreaterThan(0, $this->rows("SELECT count(*) FROM stock_batches b JOIN product_variants v ON v.id = b.variant_id WHERE v.sku LIKE 'SP-%' AND b.expires_on > CURRENT_DATE"));
    }

    public function testEveryProductHasAPrimaryPhotoThatTheMediaRouteServes(): void
    {
        $this->assertSame(0, $this->rows('SELECT count(*) FROM products p WHERE NOT EXISTS (SELECT 1 FROM product_images i WHERE i.product_id = p.id AND i.is_primary)'));
        $this->assertGreaterThanOrEqual(30, $this->rows('SELECT count(*) FROM product_images'));

        $services = new Services($this->app, false, $this->core, $this->org);
        $ids = $this->org->query('SELECT media_id FROM product_images WHERE is_primary ORDER BY media_id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(24, $ids);
        foreach ($ids as $index => $id) {
            $response = $this->app->handle(new Request('GET', '/media/demo/' . $id), $services);
            $this->assertSame(200, $response->status());
            $this->assertSame('image/jpeg', $response->header('Content-Type'));
            $this->assertNotFalse(getimagesizefromstring($response->body()), 'Served bytes are an image.');
            $this->assertLessThanOrEqual(30 * 1024, strlen($response->body()));
            if ($index === 0) {
                $thumb = $this->app->handle(new Request('GET', '/media/demo/' . $id, query: ['thumb' => '1']), $services);
                $this->assertSame(200, $thumb->status());
                $this->assertLessThan(strlen($response->body()), strlen($thumb->body()));
            }
        }
    }

    public function testPhotosAreDeterministicAndDistinct(): void
    {
        $a = DemoProductPhoto::render('Air Fryer 4.5L', 'ha');
        $this->assertSame($a, DemoProductPhoto::render('Air Fryer 4.5L', 'ha'));
        $this->assertNotSame($a, DemoProductPhoto::render('Air Fryer 4.5L', 'ha', 1));
        $this->assertNotSame($a, DemoProductPhoto::render('Steam Iron 1600W', 'ha'));
        $this->assertSame('AF', DemoProductPhoto::initials('Air Fryer 4.5L'));
        $this->assertSame('RL', DemoProductPhoto::initials('The Rattan of Lounge'));
    }

    public function testRunningTheSeedsAgainCreatesNothingNew(): void
    {
        $tables = ['products', 'product_variants', 'brands', 'categories', 'suppliers', 'purchase_orders', 'stock_movements', 'media', 'product_images', 'price_tiers', 'stock_batches'];
        $before = array_map(fn (string $t): int => $this->rows('SELECT count(*) FROM ' . $t), $tables);
        $files = glob($this->root . '/storage/uploads/demo/products/*') ?: [];

        $this->runSeeds();

        $this->assertSame($before, array_map(fn (string $t): int => $this->rows('SELECT count(*) FROM ' . $t), $tables));
        $this->assertSame(count($files), count(glob($this->root . '/storage/uploads/demo/products/*') ?: []), 'The second run wrote no photo files.');
    }

    public function testStockOnHandMatchesTheMovementsAndEveryReceiptTracesToAPurchaseOrder(): void
    {
        // The view and the raw ledger agree for every variant.
        $mismatch = $this->rows('SELECT count(*) FROM (SELECT v.variant_id, v.on_hand AS view_qty, COALESCE(m.q, 0) AS ledger_qty FROM variant_on_hand v LEFT JOIN (SELECT variant_id, SUM(quantity_delta) q FROM stock_movements GROUP BY variant_id) m ON m.variant_id = v.variant_id) x WHERE view_qty <> ledger_qty');
        $this->assertSame(0, $mismatch);

        // Every receipt movement belongs to a purchase order line and sums to what that line says was received.
        $this->assertSame(0, $this->rows("SELECT count(*) FROM stock_movements WHERE movement_type = 'receipt' AND (reference_type IS DISTINCT FROM 'purchase_order' OR reference_id IS NULL)"));
        $drift = $this->rows("SELECT count(*) FROM (SELECT l.variant_id, SUM(l.qty_received) AS received FROM purchase_order_lines l GROUP BY l.variant_id) po JOIN (SELECT variant_id, SUM(quantity_delta) AS moved FROM stock_movements WHERE movement_type = 'receipt' GROUP BY variant_id) mv ON mv.variant_id = po.variant_id WHERE po.received <> mv.moved");
        $this->assertSame(0, $drift);

        // Nothing is negative except the one oversell that seed 0001 records on purpose.
        $negative = $this->org->query("SELECT v.sku, b.code FROM stock_levels s JOIN product_variants v ON v.id = s.variant_id JOIN branches b ON b.id = s.branch_id GROUP BY v.sku, b.code, s.variant_id, s.branch_id HAVING SUM(s.on_hand) < 0 ORDER BY 1, 2")->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([['KN-ESP-250', 'SLY-HUB']], $negative);
    }

    public function testTheSeedRefusesAnyOtherSlug(): void
    {
        $before = $this->rows('SELECT count(*) FROM products');
        foreach (['acme', 'Demo', '', null] as $slug) {
            try {
                (new DemoCatalogSeeder($this->org, $this->core, sys_get_temp_dir()))->run($slug);
                $this->fail('The seed must refuse the slug ' . var_export($slug, true));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('"demo"', $e->getMessage());
            }
        }
        $seed = require $this->root . '/database/seeds/demo/0002_demo_catalog.php';
        $this->expectException(\RuntimeException::class);
        try {
            $seed($this->org, $this->core, $this->app, 'acme');
        } finally {
            $this->assertSame($before, $this->rows('SELECT count(*) FROM products'));
        }
    }

    public function testSeedRunnerHandsTheSlugToEverySeed(): void
    {
        $directory = sys_get_temp_dir() . '/gfh-seedrunner-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/0001_a.php', "<?php\nreturn static function (\\PDO \$o, \\PDO \$c, \\Gfh\\Core\\App \$a, ?string \$slug = null): void {\n    \$o->exec(\"INSERT INTO settings (key, value) VALUES ('seed_slug_probe', to_jsonb('\" . (\$slug ?? 'none') . \"'::text)) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value\");\n};\n");
        file_put_contents($directory . '/0002_b.php', "<?php\nreturn static function (\\PDO \$o, \\PDO \$c, \\Gfh\\Core\\App \$a): void {\n};\n");
        try {
            $count = (new SeedRunner())->run($directory, $this->org, $this->core, $this->app, null, 'acme');
            $this->assertSame(2, $count);
            $this->assertSame('"acme"', $this->org->query("SELECT value::text FROM settings WHERE key = 'seed_slug_probe'")->fetchColumn());
            (new SeedRunner())->run($directory, $this->org, $this->core, $this->app);
            $this->assertSame('"none"', $this->org->query("SELECT value::text FROM settings WHERE key = 'seed_slug_probe'")->fetchColumn());
        } finally {
            array_map('unlink', glob($directory . '/*') ?: []);
            rmdir($directory);
        }
    }

    public function testSeedCommandRefusesDemoWithAnotherOrganizationBeforeAnySeedRuns(): void
    {
        $command = new SeedCommand($this->app);
        $this->assertSame(2, $command->run(['seed.php', '--demo', '--org=acme']));
    }
}
