<?php

declare(strict_types=1);

use Gfh\Core\App;
use Gfh\Seeds\Demo\DemoHistorySeeder;

require_once __DIR__ . '/support/SeedRandom.php';
require_once __DIR__ . '/support/DemoSeedClock.php';
require_once __DIR__ . '/support/DemoCustomerData.php';
require_once __DIR__ . '/support/DemoHistorySeeder.php';

/**
 * Batch 8.1b: about four months of back-dated orders, POS sales and restocking for the demo organization, so the
 * reports and the dashboard have data. It builds on 0001 and 0002, only runs for the slug "demo" and only as the
 * migrator role, and is repeatable (it does nothing when its anchor customer exists). It stores no password or secret.
 *
 * It writes stock history past the append-only guard on stock_movements: a temporary trigger, created and dropped in
 * this seed's own transaction, dates each new movement. See docs/reset-demo.md.
 */
return static function (\PDO $organization, \PDO $core, App $app, ?string $slug = null): void {
    $summary = (new DemoHistorySeeder($organization, $core, $app->path('storage/uploads')))->run($slug);
    if ($summary !== []) {
        echo sprintf("    history: %d online orders, %d POS sales on %d shifts, %d purchase orders\n", $summary['orders'], $summary['pos_sales'], $summary['shifts'], $summary['purchase_orders']);
    }
};
