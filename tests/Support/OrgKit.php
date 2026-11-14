<?php

declare(strict_types=1);

namespace Gfh\Tests\Support;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Support\Uuid;

/** Builds the business services of a test organization and creates its test data (branches, products, orders...). */
final class OrgKit
{
    public readonly Hooks $hooks;
    public readonly TenantServices $services;
    public readonly string $uploadRoot;

    public function __construct(public readonly \PDO $core, public readonly \PDO $org)
    {
        $this->hooks = new Hooks();
        $this->uploadRoot = sys_get_temp_dir() . '/gfh-test-uploads-' . bin2hex(random_bytes(4));
        $this->services = new TenantServices($org, new AuditService($core), $this->hooks, $this->uploadRoot, 'test-org');
    }

    /** @param array<string, mixed> $params */
    private function insert(string $sql, array $params = []): string
    {
        $statement = $this->org->prepare($sql);
        $statement->execute($params);

        return (string) $statement->fetchColumn();
    }

    public function branch(string $code = 'B1', bool $pickup = true, bool $deliveryOrigin = false): string
    {
        return $this->insert(
            'INSERT INTO branches (code, name, is_pickup_point, is_delivery_origin) VALUES (?, ?, ?, ?) RETURNING id',
            [$code, 'Branch ' . $code, $pickup ? 'true' : 'false', $deliveryOrigin ? 'true' : 'false'],
        );
    }

    /** The default order path used by the tests. */
    public function steps(): void
    {
        $this->org->exec(
            "INSERT INTO order_progress_steps (key, label, sort_order, is_terminal, maps_to, applies_when) VALUES "
            . "('placed', 'Placed', 10, false, 'open', 'always'), "
            . "('confirmed', 'Confirmed', 20, false, 'in_progress', 'always'), "
            . "('in_production', 'In Production', 30, false, 'in_progress', 'has_made_to_order'), "
            . "('ready', 'Ready', 40, false, 'in_progress', 'always'), "
            . "('out_for_delivery', 'Out for Delivery', 50, false, 'in_progress', 'is_delivery'), "
            . "('completed', 'Completed', 60, true, 'complete', 'always') "
            . 'ON CONFLICT (key) DO NOTHING',
        );
    }

    /**
     * Creates an active product with one active variant and returns the variant id.
     *
     * @param array<string, mixed> $o sku, price, decimals (bool), step, min, track_batches (bool), reorder
     */
    public function variant(array $o = []): string
    {
        $slug = 'product-' . bin2hex(random_bytes(4));
        $productId = $this->insert(
            "INSERT INTO products (name, slug, status) VALUES (?, ?, 'active') RETURNING id",
            [$o['name'] ?? 'Test Product', $slug],
        );

        return $this->insert(
            'INSERT INTO product_variants (product_id, sku, name, unit, allow_decimal_qty, qty_step, min_qty, track_batches, retail_price, reorder_level, status) '
            . "VALUES (?, ?, 'Default', ?, ?, ?, ?, ?, ?, ?, 'active') RETURNING id",
            [
                $productId,
                $o['sku'] ?? 'SKU-' . strtoupper(bin2hex(random_bytes(3))),
                $o['unit'] ?? 'pc',
                !empty($o['decimals']) ? 'true' : 'false',
                $o['step'] ?? '1',
                $o['min'] ?? '1',
                !empty($o['track_batches']) ? 'true' : 'false',
                $o['price'] ?? '112.00',
                $o['reorder'] ?? '0',
            ],
        );
    }

    /**
     * Receives stock at a cost through the real service.
     *
     * @param array{code?: string, expires_on?: string|null, bin?: string|null}|null $batch
     */
    public function stock(string $branchId, string $variantId, string $quantity, string $cost, ?array $batch = null): void
    {
        $this->services->inventory()->receive($branchId, $variantId, $quantity, $cost, $batch);
    }

    /** @return array<string, mixed> an organization customer row */
    public function customer(string $email = 'buyer@example.test', ?string $tier = null, ?string $type = null): array
    {
        $id = $this->insert(
            'INSERT INTO customers (full_name, email, tier, customer_type) VALUES (?, ?, ?, ?) RETURNING id',
            ['Test Buyer', $email, $tier, $type],
        );

        $statement = $this->org->prepare('SELECT id, core_customer_id, full_name, email, phone, customer_type, tier, status FROM customers WHERE id = ?');
        $statement->execute([$id]);

        return (array) $statement->fetch();
    }

    /** A bare order row, for tests that only need something to attach reservations to. */
    public function rawOrder(string $branchId): string
    {
        $this->steps();
        $customer = $this->customer('raw' . bin2hex(random_bytes(3)) . '@example.test');

        return $this->insert(
            'INSERT INTO orders (order_number, customer_id, progress_key, fulfillment_method, pickup_branch_id, subtotal, vat_amount, total) '
            . "VALUES (?, ?, 'placed', 'pickup', ?, 112.00, 12.00, 112.00) RETURNING id",
            ['SO-RAW-' . bin2hex(random_bytes(3)), $customer['id'], $branchId],
        );
    }

    public function terminal(string $branchId, string $code = 'T3'): string
    {
        return $this->insert('INSERT INTO terminals (branch_id, code, name) VALUES (?, ?, ?) RETURNING id', [$branchId, $code, 'Terminal ' . $code]);
    }

    /** Opens a shift and returns its id. */
    public function shift(string $terminalId, string $opening = '1000.00'): string
    {
        $result = $this->services->pos()->openShift($terminalId, Uuid::v4(), $opening);

        return (string) $result['shift']['id'];
    }

    public function discountRule(string $key, string $percent, bool $vatExempt, bool $active): void
    {
        $this->org->prepare('INSERT INTO discount_rules (key, label, percent, vat_exempt, active) VALUES (?, ?, ?, ?, ?)')
            ->execute([$key, strtoupper($key), $percent, $vatExempt ? 'true' : 'false', $active ? 'true' : 'false']);
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->org->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    public function removeUploads(): void
    {
        if (!is_dir($this->uploadRoot)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->uploadRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->uploadRoot);
    }
}
