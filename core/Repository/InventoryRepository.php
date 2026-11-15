<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\SqlWhere;

/** All SQL for the stock ledger, batches and reservations in an organization database. */
final class InventoryRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /**
     * Locks the variants for the rest of the transaction, in id order so two sales cannot deadlock,
     * and returns them. Everything that changes stock takes this lock first.
     *
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    public function lockVariants(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        if ($ids === []) {
            return [];
        }

        $rows = $this->rows(
            'SELECT id, product_id, sku, name, unit, allow_decimal_qty, qty_step, min_qty, track_batches, avg_cost, cost_updated_at, status '
            . 'FROM product_variants WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE',
            $ids,
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['id']] = $row;
        }

        return $map;
    }

    /** @return array<string, mixed>|null */
    public function branch(string $branchId): ?array
    {
        $rows = $this->rows('SELECT id, code, name, status FROM branches WHERE id = ?', [$branchId]);

        return $rows[0] ?? null;
    }

    /** On hand for a variant across every branch, as a decimal string. */
    public function onHandTotal(string $variantId): string
    {
        return (string) $this->scalar('SELECT COALESCE(SUM(quantity_delta), 0) FROM stock_movements WHERE variant_id = ?', [$variantId]);
    }

    public function onHandAt(string $branchId, string $variantId): string
    {
        return (string) $this->scalar(
            'SELECT COALESCE(SUM(quantity_delta), 0) FROM stock_movements WHERE branch_id = ? AND variant_id = ?',
            [$branchId, $variantId],
        );
    }

    /** Held by active, unexpired reservations; reservations of $excludeOrderId are not counted. */
    public function reservedAt(string $branchId, string $variantId, ?string $excludeOrderId = null): string
    {
        return (string) $this->scalar(
            "SELECT COALESCE(SUM(quantity), 0) FROM stock_reservations WHERE branch_id = ? AND variant_id = ? "
            . "AND status = 'active' AND expires_at > now() AND (CAST(? AS uuid) IS NULL OR order_id <> CAST(? AS uuid))",
            [$branchId, $variantId, $excludeOrderId, $excludeOrderId],
        );
    }

    /**
     * Batches with stock left at a branch, oldest received first.
     *
     * @return list<array<string, mixed>>
     */
    public function availableBatches(string $branchId, string $variantId): array
    {
        return $this->rows(
            'SELECT sb.id, sb.batch_code, sb.bin, sb.expires_on, sb.received_on, SUM(m.quantity_delta) AS on_hand '
            . 'FROM stock_batches sb JOIN stock_movements m ON m.batch_id = sb.id '
            . 'WHERE sb.branch_id = ? AND sb.variant_id = ? GROUP BY sb.id HAVING SUM(m.quantity_delta) > 0 '
            . 'ORDER BY sb.received_on, sb.created_at, sb.id',
            [$branchId, $variantId],
        );
    }

    /** @return array<string, mixed>|null */
    public function findBatch(string $variantId, string $branchId, string $code): ?array
    {
        $rows = $this->rows(
            'SELECT id, batch_code, expires_on, bin FROM stock_batches WHERE variant_id = ? AND branch_id = ? AND batch_code = ?',
            [$variantId, $branchId, $code],
        );

        return $rows[0] ?? null;
    }

    public function insertBatch(string $variantId, string $branchId, string $code, ?string $bin, ?string $expiresOn, string $qty, string $unitCost, ?string $poLineId): string
    {
        return (string) $this->scalar(
            'INSERT INTO stock_batches (variant_id, branch_id, batch_code, bin, expires_on, qty_received, unit_cost, purchase_order_line_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$variantId, $branchId, $code, $bin, $expiresOn, $qty, $unitCost, $poLineId],
        );
    }

    public function addToBatch(string $batchId, string $qty): void
    {
        $this->pdo->prepare('UPDATE stock_batches SET qty_received = qty_received + CAST(? AS numeric) WHERE id = ?')->execute([$qty, $batchId]);
    }

    /** @param array<string, mixed> $m */
    public function insertMovement(array $m): string
    {
        return (string) $this->scalar(
            'INSERT INTO stock_movements (branch_id, variant_id, batch_id, movement_type, quantity_delta, unit_cost, reference_type, reference_id, reason, created_by, client_ref) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $m['branch_id'], $m['variant_id'], $m['batch_id'] ?? null, $m['movement_type'], $m['quantity_delta'], $m['unit_cost'] ?? null,
                $m['reference_type'] ?? null, $m['reference_id'] ?? null, $m['reason'] ?? null, $m['created_by'] ?? null, $m['client_ref'] ?? null,
            ],
        );
    }

    public function setAverageCost(string $variantId, string $avgCost): void
    {
        $this->pdo->prepare('UPDATE product_variants SET avg_cost = ?, cost_updated_at = now() WHERE id = ?')->execute([$avgCost, $variantId]);
    }

    // ------------------------------------------------------------------ reservations

    public function addReservation(string $orderId, string $variantId, string $branchId, string $quantity, \DateTimeImmutable $expiresAt): void
    {
        $this->pdo->prepare(
            'INSERT INTO stock_reservations (order_id, variant_id, branch_id, quantity, expires_at) VALUES (?, ?, ?, ?, CAST(? AS timestamptz))',
        )->execute([$orderId, $variantId, $branchId, $quantity, $expiresAt->format('Y-m-d H:i:sP')]);
    }

    /** Moves an order's active reservations to a new status; returns how many changed. */
    public function moveReservations(string $orderId, string $toStatus): int
    {
        $statement = $this->pdo->prepare("UPDATE stock_reservations SET status = ? WHERE order_id = ? AND status = 'active'");
        $statement->execute([$toStatus, $orderId]);

        return $statement->rowCount();
    }

    /** Marks reservations that ran out as released. They stop holding stock the moment they expire, even before this runs. */
    public function releaseExpired(): int
    {
        $statement = $this->pdo->prepare("UPDATE stock_reservations SET status = 'released' WHERE status = 'active' AND expires_at <= now()");
        $statement->execute();

        return $statement->rowCount();
    }

    // ------------------------------------------------------------------ reading

    /**
     * Stock by branch and variant (rows that have any movement).
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function levels(ListQuery $query): array
    {
        $where = new SqlWhere();
        if (($branch = $query->filter('branch_id')) !== null && $branch !== '') {
            $where->add('l.branch_id = ?', $branch);
        }
        if (($variant = $query->filter('variant_id')) !== null && $variant !== '') {
            $where->add('l.variant_id = ?', $variant);
        }
        if (($pattern = $query->likePattern()) !== null) {
            $where->add('p.name ILIKE ? OR v.sku ILIKE ? OR v.name ILIKE ?', $pattern, $pattern, $pattern);
        }
        if (filter_var($query->filter('low_stock'), FILTER_VALIDATE_BOOLEAN)) {
            $where->add('v.reorder_level > 0 AND l.on_hand <= v.reorder_level');
        }

        $from = 'FROM (SELECT branch_id, variant_id, SUM(quantity_delta) AS on_hand FROM stock_movements GROUP BY branch_id, variant_id) l '
            . 'JOIN branches b ON b.id = l.branch_id JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id '
            . 'LEFT JOIN (SELECT branch_id, variant_id, SUM(quantity) AS reserved FROM stock_reservations '
            . "WHERE status = 'active' AND expires_at > now() GROUP BY branch_id, variant_id) r "
            . 'ON r.branch_id = l.branch_id AND r.variant_id = l.variant_id ';

        $total = (int) $this->scalar('SELECT COUNT(*) ' . $from . $where->sql(), $where->params());
        $rows = $this->rows(
            'SELECT l.branch_id, b.name AS branch_name, l.variant_id, v.sku, v.name AS variant_name, p.name AS product_name, v.unit, v.reorder_level, '
            . 'l.on_hand, COALESCE(r.reserved, 0) AS reserved, l.on_hand - COALESCE(r.reserved, 0) AS available '
            . $from . $where->sql() . ' ORDER BY ' . $query->orderBy . ' LIMIT ' . $query->perPage . ' OFFSET ' . $query->offset(),
            $where->params(),
        );

        return [$rows, $total];
    }

    /**
     * The movement ledger.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function ledger(ListQuery $query): array
    {
        $where = new SqlWhere();
        foreach (['branch_id' => 'm.branch_id', 'variant_id' => 'm.variant_id', 'type' => 'm.movement_type', 'batch_id' => 'm.batch_id'] as $key => $column) {
            $value = $query->filter($key);
            if (is_string($value) && $value !== '') {
                $where->add($column . ' = ?', $value);
            }
        }
        if (is_string($from = $query->filter('from')) && $from !== '') {
            $where->add("(m.occurred_at AT TIME ZONE 'Asia/Manila')::date >= CAST(? AS date)", $from);
        }
        if (is_string($to = $query->filter('to')) && $to !== '') {
            $where->add("(m.occurred_at AT TIME ZONE 'Asia/Manila')::date <= CAST(? AS date)", $to);
        }
        if (($pattern = $query->likePattern()) !== null) {
            $where->add('p.name ILIKE ? OR v.sku ILIKE ? OR m.reason ILIKE ?', $pattern, $pattern, $pattern);
        }

        $from = 'FROM stock_movements m JOIN branches b ON b.id = m.branch_id JOIN product_variants v ON v.id = m.variant_id '
            . 'JOIN products p ON p.id = v.product_id LEFT JOIN stock_batches sb ON sb.id = m.batch_id ';

        $total = (int) $this->scalar('SELECT COUNT(*) ' . $from . $where->sql(), $where->params());
        $rows = $this->rows(
            'SELECT m.id, m.occurred_at, m.branch_id, b.name AS branch_name, m.variant_id, v.sku, p.name AS product_name, m.batch_id, sb.batch_code, '
            . 'm.movement_type, m.quantity_delta, m.unit_cost, m.reference_type, m.reference_id, m.reason, m.created_by '
            . $from . $where->sql() . ' ORDER BY ' . $query->orderBy . ' LIMIT ' . $query->perPage . ' OFFSET ' . $query->offset(),
            $where->params(),
        );

        return [$rows, $total];
    }

    /** @return list<array<string, mixed>> */
    public function branches(bool $activeOnly = false): array
    {
        $sql = 'SELECT id, code, name, kind, status FROM branches';
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }

        return $this->rows($sql . ' ORDER BY name, code');
    }

    /** @return list<array<string, mixed>> */
    public function variantsForSelect(): array
    {
        return $this->rows(
            "SELECT v.id, v.sku, v.name AS variant_name, p.name AS product_name, v.unit, v.allow_decimal_qty, v.qty_step, v.min_qty "
            . "FROM product_variants v JOIN products p ON p.id = v.product_id "
            . "WHERE v.status = 'active' AND p.status <> 'discontinued' ORDER BY p.name, v.sku",
        );
    }

    /** @return list<array<string, mixed>> */
    public function reorderSuppliers(): array
    {
        return $this->rows("SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name");
    }

    /**
     * Active variants whose available stock (on hand minus active reservations) is at or below reorder_level.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function reorderSuggestions(ListQuery $query): array
    {
        $where = new SqlWhere();
        if (is_string($supplier = $query->filter('supplier_id')) && $supplier !== '') {
            $where->add('supplier_id = ?', $supplier);
        }
        $status = $query->filter('status');
        if ($status === 'out_of_stock') {
            $where->add('on_hand <= 0');
        } elseif ($status === 'low_stock') {
            $where->add('on_hand > 0');
        } elseif ($status === 'needs_po') {
            $where->add('suggested_qty > 0');
        }
        if (($pattern = $query->likePattern()) !== null) {
            $where->add("product_name ILIKE ? OR sku ILIKE ? OR variant_name ILIKE ? OR COALESCE(supplier_name, '') ILIKE ?", $pattern, $pattern, $pattern, $pattern);
        }

        $cte = 'WITH on_hand AS ('
            . '  SELECT variant_id, SUM(quantity_delta) AS qty FROM stock_movements GROUP BY variant_id'
            . '), reserved AS ('
            . "  SELECT variant_id, SUM(quantity) AS qty FROM stock_reservations WHERE status = 'active' AND expires_at > now() GROUP BY variant_id"
            . '), on_order AS ('
            . '  SELECT pol.variant_id, SUM(GREATEST(0, pol.qty_ordered - pol.qty_received - pol.qty_rejected)) AS qty '
            . '  FROM purchase_order_lines pol JOIN purchase_orders po ON po.id = pol.purchase_order_id '
            . "  WHERE po.status IN ('sent', 'partially_received') GROUP BY pol.variant_id"
            . '), base AS ('
            . '  SELECT v.id AS variant_id, v.sku, v.name AS variant_name, p.id AS product_id, p.name AS product_name, '
            . '         v.unit, v.min_qty, v.reorder_level, v.avg_cost, p.supplier_id, s.name AS supplier_name, s.lead_time_days, '
            . '         COALESCE(oh.qty, 0) AS on_hand, COALESCE(r.qty, 0) AS reserved, '
            . '         COALESCE(oh.qty, 0) - COALESCE(r.qty, 0) AS available, '
            . '         COALESCE(oo.qty, 0) AS on_order, '
            . '         CASE WHEN (COALESCE(oh.qty, 0) - COALESCE(r.qty, 0) + COALESCE(oo.qty, 0)) <= v.reorder_level '
            . '              THEN GREATEST(v.min_qty, v.reorder_level - (COALESCE(oh.qty, 0) - COALESCE(r.qty, 0) + COALESCE(oo.qty, 0))) '
            . '              ELSE 0.000 END AS suggested_qty '
            . '  FROM product_variants v '
            . '  JOIN products p ON p.id = v.product_id '
            . '  LEFT JOIN suppliers s ON s.id = p.supplier_id '
            . '  LEFT JOIN on_hand oh ON oh.variant_id = v.id '
            . '  LEFT JOIN reserved r ON r.variant_id = v.id '
            . '  LEFT JOIN on_order oo ON oo.variant_id = v.id '
            . "  WHERE v.status = 'active' AND p.status = 'active' AND v.reorder_level > 0 "
            . '    AND (COALESCE(oh.qty, 0) - COALESCE(r.qty, 0)) <= v.reorder_level'
            . ') ';

        $total = (int) $this->scalar($cte . 'SELECT COUNT(*) FROM base ' . $where->sql(), $where->params());
        $rows = $this->rows(
            $cte . 'SELECT * FROM base ' . $where->sql() . ' ORDER BY ' . $query->orderBy . ', product_name ASC, sku ASC LIMIT ' . $query->perPage . ' OFFSET ' . $query->offset(),
            $where->params(),
        );

        return [$rows, $total];
    }
}
