<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\SqlWhere;

/** All SQL for suppliers and purchase orders in an organization database. */
final class ProcurementRepository
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

    // ------------------------------------------------------------------ suppliers

    /** @return array<string, mixed>|null */
    public function supplier(string $id): ?array
    {
        return $this->rows(
            'SELECT id, name, contact_name, contact_email, contact_phone, address, payment_terms, lead_time_days, status FROM suppliers WHERE id = ?',
            [$id],
        )[0] ?? null;
    }

    public function supplierIdByName(string $name): ?string
    {
        $id = $this->scalar('SELECT id FROM suppliers WHERE lower(name) = lower(?)', [$name]);

        return $id === false ? null : (string) $id;
    }

    /** @param array<string, mixed> $s */
    public function createSupplier(array $s, ?string $createdBy): string
    {
        return (string) $this->scalar(
            'INSERT INTO suppliers (name, contact_name, contact_email, contact_phone, address, payment_terms, lead_time_days, created_by) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$s['name'], $s['contact_name'], $s['contact_email'], $s['contact_phone'], $s['address'], $s['payment_terms'], $s['lead_time_days'], $createdBy],
        );
    }

    /** @param array<string, mixed> $s */
    public function updateSupplier(string $id, array $s): void
    {
        $this->pdo->prepare(
            'UPDATE suppliers SET name = ?, contact_name = ?, contact_email = ?, contact_phone = ?, address = ?, payment_terms = ?, lead_time_days = ? WHERE id = ?',
        )->execute([$s['name'], $s['contact_name'], $s['contact_email'], $s['contact_phone'], $s['address'], $s['payment_terms'], $s['lead_time_days'], $id]);
    }

    public function setSupplierStatus(string $id, string $status): void
    {
        $this->pdo->prepare('UPDATE suppliers SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function listSuppliers(ListQuery $query): array
    {
        $where = new SqlWhere();
        if (is_string($status = $query->filter('status')) && $status !== '') {
            $where->add('s.status = ?', $status);
        }
        if (($pattern = $query->likePattern()) !== null) {
            $where->add('s.name ILIKE ? OR s.contact_name ILIKE ? OR s.contact_email ILIKE ?', $pattern, $pattern, $pattern);
        }

        $total = (int) $this->scalar('SELECT COUNT(*) FROM suppliers s ' . $where->sql(), $where->params());
        $rows = $this->rows(
            'SELECT s.id, s.name, s.contact_name, s.contact_email, s.contact_phone, s.payment_terms, s.lead_time_days, s.status, '
            . "(SELECT COUNT(*) FROM purchase_orders p WHERE p.supplier_id = s.id AND p.status IN ('draft', 'sent', 'partially_received')) AS open_orders "
            . 'FROM suppliers s ' . $where->sql() . ' ORDER BY ' . $query->orderBy . ' LIMIT ' . $query->perPage . ' OFFSET ' . $query->offset(),
            $where->params(),
        );

        return [$rows, $total];
    }

    // ------------------------------------------------------------------ purchase orders

    /** @return array<string, mixed>|null */
    public function branch(string $id): ?array
    {
        return $this->rows('SELECT id, name, status FROM branches WHERE id = ?', [$id])[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function variant(string $id): ?array
    {
        return $this->rows(
            'SELECT id, sku, name, unit, allow_decimal_qty, qty_step, min_qty, track_batches, status FROM product_variants WHERE id = ?',
            [$id],
        )[0] ?? null;
    }

    /** Next number for the year, like PO-2026-0012. The caller holds an advisory lock. */
    public function nextPoNumber(string $year): string
    {
        $next = (int) $this->scalar(
            "SELECT COALESCE(MAX(CAST(split_part(po_number, '-', 3) AS integer)), 0) + 1 FROM purchase_orders WHERE po_number LIKE ?",
            ['PO-' . $year . '-%'],
        );

        return sprintf('PO-%s-%04d', $year, $next);
    }

    public function insertPo(string $number, string $supplierId, string $branchId, ?string $expectedOn, ?string $notes, ?string $orderedBy): string
    {
        return (string) $this->scalar(
            'INSERT INTO purchase_orders (po_number, supplier_id, branch_id, expected_on, notes, ordered_by) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            [$number, $supplierId, $branchId, $expectedOn, $notes, $orderedBy],
        );
    }

    public function insertPoLine(string $poId, string $variantId, string $qty, string $unitCost): void
    {
        $this->pdo->prepare(
            'INSERT INTO purchase_order_lines (purchase_order_id, variant_id, qty_ordered, unit_cost) VALUES (?, ?, ?, ?)',
        )->execute([$poId, $variantId, $qty, $unitCost]);
    }

    /** @return array<string, mixed>|null */
    public function po(string $id, bool $lock = false): ?array
    {
        return $this->rows(
            'SELECT p.id, p.po_number, p.supplier_id, s.name AS supplier_name, p.branch_id, b.name AS branch_name, p.status, p.expected_on, p.sent_at, p.notes, p.created_at, p.updated_at '
            . 'FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id JOIN branches b ON b.id = p.branch_id WHERE p.id = ?' . ($lock ? ' FOR UPDATE OF p' : ''),
            [$id],
        )[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function poLines(string $poId): array
    {
        return $this->rows(
            'SELECT l.id, l.variant_id, v.sku, v.name AS variant_name, pr.name AS product_name, v.unit, v.track_batches, v.allow_decimal_qty, v.qty_step, v.min_qty, '
            . 'l.qty_ordered, l.qty_received, l.qty_rejected, l.unit_cost '
            . 'FROM purchase_order_lines l JOIN product_variants v ON v.id = l.variant_id JOIN products pr ON pr.id = v.product_id '
            . 'WHERE l.purchase_order_id = ? ORDER BY l.created_at, l.id',
            [$poId],
        );
    }

    public function setPoStatus(string $id, string $status): void
    {
        $this->pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    /** Sets the status to sent and records when, so the order page can show the sent date. */
    public function markPoSent(string $id): void
    {
        $this->pdo->prepare("UPDATE purchase_orders SET status = 'sent', sent_at = now() WHERE id = ?")->execute([$id]);
    }

    public function setLineReceipt(string $lineId, string $received, string $rejected): void
    {
        $this->pdo->prepare('UPDATE purchase_order_lines SET qty_received = ?, qty_rejected = ? WHERE id = ?')->execute([$received, $rejected, $lineId]);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function listPos(ListQuery $query): array
    {
        $where = new SqlWhere();
        foreach (['status' => 'p.status', 'supplier_id' => 'p.supplier_id', 'branch_id' => 'p.branch_id'] as $key => $column) {
            $value = $query->filter($key);
            if (is_string($value) && $value !== '') {
                $where->add($column . ' = ?', $value);
            }
        }
        if (($pattern = $query->likePattern()) !== null) {
            $where->add('p.po_number ILIKE ? OR s.name ILIKE ?', $pattern, $pattern);
        }

        $from = 'FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id JOIN branches b ON b.id = p.branch_id ';
        $total = (int) $this->scalar('SELECT COUNT(*) ' . $from . $where->sql(), $where->params());
        $rows = $this->rows(
            'SELECT p.id, p.po_number, p.status, p.expected_on, p.created_at, s.name AS supplier_name, b.name AS branch_name, '
            . '(SELECT COALESCE(SUM(l.qty_ordered * l.unit_cost), 0) FROM purchase_order_lines l WHERE l.purchase_order_id = p.id) AS total_cost '
            . $from . $where->sql() . ' ORDER BY ' . $query->orderBy . ' LIMIT ' . $query->perPage . ' OFFSET ' . $query->offset(),
            $where->params(),
        );

        return [$rows, $total];
    }
}
