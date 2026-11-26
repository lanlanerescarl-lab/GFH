<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\SqlWhere;

/** All SQL for terminals, shifts and POS sales in an organization database. */
final class PosRepository
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

    /** @return array<string, mixed>|null */
    public function terminal(string $id): ?array
    {
        return $this->rows(
            'SELECT t.id, t.branch_id, t.code, t.name, t.status, b.name AS branch_name, b.address AS branch_address, b.city AS branch_city, b.province AS branch_province '
            . 'FROM terminals t JOIN branches b ON b.id = t.branch_id WHERE t.id = ?',
            [$id],
        )[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function openShift(string $terminalId): ?array
    {
        return $this->rows(
            "SELECT id, terminal_id, cashier_user_id, opened_at, opening_cash, status FROM pos_shifts WHERE terminal_id = ? AND status = 'open'",
            [$terminalId],
        )[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function shift(string $id, bool $lock = false): ?array
    {
        return $this->rows(
            'SELECT id, terminal_id, cashier_user_id, opened_at, opening_cash, closed_at, counted_cash, expected_cash, variance, status FROM pos_shifts WHERE id = ?'
            . ($lock ? ' FOR UPDATE' : ''),
            [$id],
        )[0] ?? null;
    }

    public function insertShift(string $terminalId, string $cashierId, string $openingCash): string
    {
        return (string) $this->scalar(
            'INSERT INTO pos_shifts (terminal_id, cashier_user_id, opening_cash) VALUES (?, ?, ?) RETURNING id',
            [$terminalId, $cashierId, $openingCash],
        );
    }

    public function closeShift(string $id, string $counted, string $expected, string $variance): void
    {
        $this->pdo->prepare(
            "UPDATE pos_shifts SET status = 'closed', closed_at = now(), counted_cash = ?, expected_cash = ?, variance = ? WHERE id = ?",
        )->execute([$counted, $expected, $variance, $id]);
    }

    /** Cash taken on sales of the shift (card and e-wallet payments are not in the drawer). */
    public function cashTakenOnShift(string $shiftId): string
    {
        return (string) $this->scalar(
            "SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN pos_sales s ON s.id = p.pos_sale_id "
            . "WHERE s.shift_id = ? AND p.method = 'cash' AND p.status = 'recorded' AND s.status <> 'voided'",
            [$shiftId],
        );
    }

    /** Next receipt counter for a terminal, so a terminal can resume after a reinstall. */
    public function nextReceiptSequence(string $terminalId): int
    {
        return (int) $this->scalar(
            "SELECT COALESCE(MAX(CAST(split_part(receipt_number, '-', 2) AS integer)), 0) + 1 FROM pos_sales WHERE terminal_id = ?",
            [$terminalId],
        );
    }

    /** @return array<string, mixed>|null */
    public function sale(string $id): ?array
    {
        return $this->rows(
            'SELECT id, receipt_number, shift_id, terminal_id, branch_id, cashier_user_id, customer_id, sold_at, synced_at, subtotal, discount_total, vat_amount, total, status, review_reason '
            . 'FROM pos_sales WHERE id = ?',
            [$id],
        )[0] ?? null;
    }

    public function saleIdByReceipt(string $receipt): ?string
    {
        $id = $this->scalar('SELECT id FROM pos_sales WHERE receipt_number = ?', [$receipt]);

        return $id === false ? null : (string) $id;
    }

    /** @param array<string, mixed> $s */
    public function insertSale(array $s): void
    {
        $this->pdo->prepare(
            'INSERT INTO pos_sales (id, receipt_number, shift_id, terminal_id, branch_id, cashier_user_id, customer_id, sold_at, subtotal, discount_total, vat_amount, total, status, review_reason) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, CAST(? AS timestamptz), ?, ?, ?, ?, ?, ?)',
        )->execute([
            $s['id'], $s['receipt_number'], $s['shift_id'], $s['terminal_id'], $s['branch_id'], $s['cashier_user_id'], $s['customer_id'],
            $s['sold_at'], $s['subtotal'], $s['discount_total'], $s['vat_amount'], $s['total'], $s['status'], $s['review_reason'],
        ]);
    }

    /** @param array<string, mixed> $l */
    public function insertSaleLine(string $saleId, array $l): void
    {
        $this->pdo->prepare(
            'INSERT INTO pos_sale_lines (pos_sale_id, variant_id, batch_id, sku_snapshot, name_snapshot, qty, unit_price, discount, unit_cost, line_total) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$saleId, $l['variant_id'], $l['batch_id'], $l['sku'], $l['name'], $l['qty'], $l['unit_price'], $l['discount'], $l['unit_cost'], $l['line_total']]);
    }

    public function insertSaleDiscount(string $saleId, string $ruleId, string $amount, ?string $idLast4): void
    {
        $this->pdo->prepare('INSERT INTO pos_sale_discounts (pos_sale_id, rule_id, amount, id_ref_last4) VALUES (?, ?, ?, ?)')
            ->execute([$saleId, $ruleId, $amount, $idLast4]);
    }

    public function insertSalePayment(string $saleId, string $method, string $amount, ?string $reference, string $recordedBy, string $paidAt): void
    {
        $this->pdo->prepare(
            'INSERT INTO payments (pos_sale_id, method, amount, reference, recorded_by, paid_at) VALUES (?, ?, ?, ?, ?, CAST(? AS timestamptz))',
        )->execute([$saleId, $method, $amount, $reference, $recordedBy, $paidAt]);
    }

    public function setSaleStatus(string $id, string $status): void
    {
        $this->pdo->prepare('UPDATE pos_sales SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    /** @return list<array<string, mixed>> */
    public function saleLines(string $saleId): array
    {
        return $this->rows(
            'SELECT id, variant_id, batch_id, sku_snapshot AS sku, name_snapshot AS name, qty, unit_price, discount, unit_cost, line_total FROM pos_sale_lines WHERE pos_sale_id = ? ORDER BY id',
            [$saleId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function salePayments(string $saleId): array
    {
        return $this->rows('SELECT method, amount, reference, status, paid_at FROM payments WHERE pos_sale_id = ? ORDER BY paid_at, id', [$saleId]);
    }

    /** @return list<array<string, mixed>> */
    public function saleDiscounts(string $saleId): array
    {
        return $this->rows(
            'SELECT d.amount, d.id_ref_last4, r.key, r.label FROM pos_sale_discounts d JOIN discount_rules r ON r.id = d.rule_id WHERE d.pos_sale_id = ?',
            [$saleId],
        );
    }

    /**
     * Variants by id with the fields a sale needs (any status: an offline sale may include something since deactivated).
     *
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    public function variants(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach ($this->rows(
            'SELECT v.id, v.sku, v.name, v.unit, v.allow_decimal_qty, v.qty_step, v.min_qty, v.track_batches, p.name AS product_name '
            . 'FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids,
        ) as $row) {
            $map[(string) $row['id']] = $row;
        }

        return $map;
    }

    public function customerExists(string $id): bool
    {
        return $this->scalar('SELECT 1 FROM customers WHERE id = ?', [$id]) !== false;
    }

    /** @return array<string, mixed>|null */
    public function discountRule(string $key): ?array
    {
        return $this->rows('SELECT id, key, label, percent, vat_exempt, active FROM discount_rules WHERE key = ?', [$key])[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function activeDiscountRules(): array
    {
        return $this->rows('SELECT key, label, percent, vat_exempt FROM discount_rules WHERE active ORDER BY label');
    }

    /**
     * Everything a terminal needs to sell offline: active variants with their stock at the terminal's branch.
     *
     * @return list<array<string, mixed>>
     */
    public function snapshotVariants(string $branchId): array
    {
        return $this->rows(
            'SELECT v.id AS variant_id, v.product_id, v.sku, v.barcode, p.name AS product_name, v.name AS variant_name, v.unit, v.retail_price, '
            . 'v.allow_decimal_qty, v.qty_step, v.min_qty, v.track_batches, '
            . '(SELECT pi.media_id FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary LIMIT 1) AS media_id, '
            . 'COALESCE((SELECT SUM(m.quantity_delta) FROM stock_movements m WHERE m.branch_id = ? AND m.variant_id = v.id), 0) AS on_hand '
            . "FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.status = 'active' AND p.status = 'active' ORDER BY p.name, v.name",
            [$branchId],
        );
    }

    /**
     * The extension-owned attributes (keys that start with ext_) of every active product that has any, by product id.
     * Only scalar values, and nothing but the ext_ keys: the other attributes belong to the organization's catalog.
     * Read only when a pos.receipt.lines callback is registered.
     *
     * @return array<string, array<string, string|int|float|bool>>
     */
    public function extensionAttributes(): array
    {
        $found = [];
        foreach ($this->rows(
            "SELECT id, attributes::text AS attributes FROM products WHERE status = 'active' AND EXISTS "
            . "(SELECT 1 FROM jsonb_object_keys(attributes) AS k WHERE k LIKE 'ext\\_%')",
        ) as $row) {
            $decoded = json_decode((string) $row['attributes'], true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $key => $value) {
                if (is_string($key) && str_starts_with($key, 'ext_') && is_scalar($value)) {
                    $found[(string) $row['id']][$key] = $value;
                }
            }
        }

        return $found;
    }

    /** @return list<array<string, mixed>> */
    public function quantityTiers(): array
    {
        return $this->rows("SELECT variant_id, label, min_qty, price FROM price_tiers WHERE basis = 'quantity' ORDER BY variant_id, min_qty");
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function listSales(ListQuery $query): array
    {
        $where = new SqlWhere();
        foreach (['status' => 's.status', 'terminal_id' => 's.terminal_id', 'shift_id' => 's.shift_id'] as $key => $column) {
            $value = $query->filter($key);
            if (is_string($value) && $value !== '') {
                $where->add($column . ' = ?', $value);
            }
        }
        if (($pattern = $query->likePattern()) !== null) {
            $where->add('s.receipt_number ILIKE ?', $pattern);
        }

        $from = 'FROM pos_sales s JOIN terminals t ON t.id = s.terminal_id JOIN branches b ON b.id = s.branch_id ';
        $total = (int) $this->scalar('SELECT COUNT(*) ' . $from . $where->sql(), $where->params());
        $rows = $this->rows(
            'SELECT s.id, s.receipt_number, s.sold_at, s.synced_at, s.total, s.status, s.review_reason, t.code AS terminal_code, b.name AS branch_name '
            . $from . $where->sql() . ' ORDER BY ' . $query->orderBy . ' LIMIT ' . $query->perPage . ' OFFSET ' . $query->offset(),
            $where->params(),
        );

        return [$rows, $total];
    }

    /** @return list<array<string, mixed>> every terminal, for the sales filter */
    public function terminals(): array
    {
        return $this->rows('SELECT t.id, t.code, t.name, t.status, b.name AS branch_name FROM terminals t JOIN branches b ON b.id = t.branch_id ORDER BY b.name, t.code');
    }

    /**
     * The terminals a cashier can pick: active ones only, with their branch name and whether a shift is open on each.
     *
     * @return list<array<string, mixed>>
     */
    public function activeTerminals(): array
    {
        return $this->rows(
            'SELECT t.id, t.code, t.name, b.name AS branch_name, '
            . "EXISTS (SELECT 1 FROM pos_shifts sh WHERE sh.terminal_id = t.id AND sh.status = 'open') AS has_open_shift "
            . "FROM terminals t JOIN branches b ON b.id = t.branch_id WHERE t.status = 'active' ORDER BY b.name, t.code",
        );
    }

    public function needsReviewCount(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM pos_sales WHERE status = 'needs_review'");
    }
}
