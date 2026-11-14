<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\SqlWhere;

/** All SQL for orders, order lines, payments and progress steps in an organization database. */
final class OrderRepository
{
    private const ORDER_COLUMNS = 'o.id, o.order_number, o.customer_id, o.channel, o.progress_key, o.payment_status, o.fulfillment_method, '
        . 'o.pickup_branch_id, o.ship_from_branch_id, o.ship_to::text AS ship_to, o.subtotal, o.discount_total, o.delivery_fee, o.assembly_fee, '
        . 'o.installation_fee, o.vat_amount, o.total, o.deposit_due, o.promised_date, o.cancelled_at, o.cancellation_reason, o.refund_review, '
        . 'o.placed_at, o.updated_at';

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

    /** @return list<array<string, mixed>> */
    public function steps(): array
    {
        return $this->rows('SELECT key, label, sort_order, is_terminal, maps_to, applies_when FROM order_progress_steps ORDER BY sort_order, key');
    }

    /** @return array<string, mixed>|null */
    public function branch(string $id): ?array
    {
        return $this->rows('SELECT id, code, name, status, is_pickup_point, is_delivery_origin FROM branches WHERE id = ?', [$id])[0] ?? null;
    }

    public function deliveryOriginId(): ?string
    {
        $id = $this->scalar("SELECT id FROM branches WHERE is_delivery_origin AND status = 'active'");

        return $id === false ? null : (string) $id;
    }

    /** @return list<array<string, mixed>> */
    public function pickupBranches(): array
    {
        return $this->rows("SELECT id, code, name, address, city, province, region FROM branches WHERE is_pickup_point AND status = 'active' ORDER BY name");
    }

    /** @return array<string, mixed>|null */
    public function customerByCoreId(string $coreCustomerId): ?array
    {
        return $this->rows(
            'SELECT id, core_customer_id, full_name, email, phone, customer_type, tier, status FROM customers WHERE core_customer_id = ?',
            [$coreCustomerId],
        )[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function customerById(string $id): ?array
    {
        return $this->rows('SELECT id, core_customer_id, full_name, email, phone, customer_type, tier, status FROM customers WHERE id = ?', [$id])[0] ?? null;
    }

    /** Next number for the day, like SO-20261112-0007. The caller holds an advisory lock while calling this. */
    public function nextOrderNumber(string $dateCompact): string
    {
        $next = (int) $this->scalar(
            "SELECT COALESCE(MAX(CAST(split_part(order_number, '-', 3) AS integer)), 0) + 1 FROM orders WHERE order_number LIKE ?",
            ['SO-' . $dateCompact . '-%'],
        );

        return sprintf('SO-%s-%04d', $dateCompact, $next);
    }

    /** @param array<string, mixed> $o */
    public function insertOrder(array $o): string
    {
        return (string) $this->scalar(
            'INSERT INTO orders (order_number, customer_id, channel, progress_key, payment_status, fulfillment_method, pickup_branch_id, ship_from_branch_id, '
            . 'ship_to, subtotal, discount_total, delivery_fee, assembly_fee, installation_fee, vat_amount, total, deposit_due, promised_date, created_by) '
            . "VALUES (?, ?, ?, ?, 'unpaid', ?, ?, ?, CAST(? AS jsonb), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [
                $o['order_number'], $o['customer_id'], $o['channel'], $o['progress_key'], $o['fulfillment_method'], $o['pickup_branch_id'], $o['ship_from_branch_id'],
                $o['ship_to'], $o['subtotal'], $o['discount_total'], $o['delivery_fee'], $o['assembly_fee'], $o['installation_fee'], $o['vat_amount'],
                $o['total'], $o['deposit_due'], $o['promised_date'], $o['created_by'],
            ],
        );
    }

    /** @param array<string, mixed> $l */
    public function insertLine(string $orderId, array $l): void
    {
        $this->pdo->prepare(
            'INSERT INTO order_lines (order_id, variant_id, sku_snapshot, name_snapshot, qty, unit_price, discount, line_total, is_made_to_order) '
            . 'VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)',
        )->execute([$orderId, $l['variant_id'], $l['sku'], $l['name'], $l['qty'], $l['unit_price'], $l['line_total'], $l['is_made_to_order'] ? 'true' : 'false']);
    }

    public function setLineCost(string $lineId, ?string $unitCost): void
    {
        $this->pdo->prepare('UPDATE order_lines SET unit_cost = ? WHERE id = ?')->execute([$unitCost, $lineId]);
    }

    public function addHistory(string $orderId, string $stepKey, ?string $changedBy): void
    {
        $this->pdo->prepare(
            'INSERT INTO order_status_history (order_id, progress_key, changed_at, changed_by) VALUES (?, ?, clock_timestamp(), ?)',
        )->execute([$orderId, $stepKey, $changedBy]);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id, bool $lock = false): ?array
    {
        return $this->rows('SELECT ' . self::ORDER_COLUMNS . ' FROM orders o WHERE o.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id])[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function lines(string $orderId): array
    {
        return $this->rows(
            'SELECT id, variant_id, sku_snapshot AS sku, name_snapshot AS name, qty, unit_price, discount, line_total, unit_cost, is_made_to_order '
            . 'FROM order_lines WHERE order_id = ? ORDER BY created_at, id',
            [$orderId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function payments(string $orderId): array
    {
        return $this->rows(
            'SELECT id, method, amount, reference, status, paid_at, recorded_by FROM payments WHERE order_id = ? ORDER BY paid_at, id',
            [$orderId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function history(string $orderId): array
    {
        return $this->rows(
            'SELECT h.progress_key, s.label, h.changed_at, h.changed_by FROM order_status_history h '
            . 'JOIN order_progress_steps s ON s.key = h.progress_key WHERE h.order_id = ? ORDER BY h.changed_at, h.id',
            [$orderId],
        );
    }

    public function paidTotal(string $orderId): string
    {
        return (string) $this->scalar("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = 'recorded'", [$orderId]);
    }

    public function addPayment(string $orderId, string $method, string $amount, ?string $reference, ?string $recordedBy): string
    {
        return (string) $this->scalar(
            'INSERT INTO payments (order_id, method, amount, reference, recorded_by, paid_at) VALUES (?, ?, ?, ?, ?, clock_timestamp()) RETURNING id',
            [$orderId, $method, $amount, $reference, $recordedBy],
        );
    }

    public function setProgress(string $orderId, string $stepKey): void
    {
        $this->pdo->prepare('UPDATE orders SET progress_key = ? WHERE id = ?')->execute([$stepKey, $orderId]);
    }

    public function setPaymentStatus(string $orderId, string $status): void
    {
        $this->pdo->prepare('UPDATE orders SET payment_status = ? WHERE id = ?')->execute([$status, $orderId]);
    }

    public function cancel(string $orderId, string $reason, bool $refundReview): void
    {
        $this->pdo->prepare(
            'UPDATE orders SET cancelled_at = now(), cancellation_reason = ?, refund_review = ? WHERE id = ?',
        )->execute([$reason, $refundReview ? 'true' : 'false', $orderId]);
    }

    /** True once the order's stock has been sold (it was fulfilled). */
    public function hasSaleMovements(string $orderId): bool
    {
        return $this->scalar("SELECT 1 FROM stock_movements WHERE reference_type = 'order' AND reference_id = ? AND movement_type = 'sale_online' LIMIT 1", [$orderId]) !== false;
    }

    /**
     * Orders for the Admin list or one customer's list.
     * Filters: progress_key, payment_status, customer_id, refund_review, cancelled, from, to (calendar days, Manila).
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function list(ListQuery $query, ?string $onlyCustomerId): array
    {
        $where = new SqlWhere();
        if ($onlyCustomerId !== null) {
            $where->add('o.customer_id = ?', $onlyCustomerId);
        }
        foreach (['progress_key' => 'o.progress_key', 'payment_status' => 'o.payment_status', 'customer_id' => 'o.customer_id', 'channel' => 'o.channel'] as $key => $column) {
            $value = $query->filter($key);
            if ($onlyCustomerId === null && is_string($value) && $value !== '') {
                $where->add($column . ' = ?', $value);
            } elseif ($onlyCustomerId !== null && $key !== 'customer_id' && is_string($value) && $value !== '') {
                $where->add($column . ' = ?', $value);
            }
        }
        if (filter_var($query->filter('refund_review'), FILTER_VALIDATE_BOOLEAN)) {
            $where->add('o.refund_review');
        }
        if (is_string($query->filter('cancelled')) && $query->filter('cancelled') !== '') {
            $where->add(filter_var($query->filter('cancelled'), FILTER_VALIDATE_BOOLEAN) ? 'o.cancelled_at IS NOT NULL' : 'o.cancelled_at IS NULL');
        }
        if (is_string($from = $query->filter('from')) && $from !== '') {
            $where->add("(o.placed_at AT TIME ZONE 'Asia/Manila')::date >= CAST(? AS date)", $from);
        }
        if (is_string($to = $query->filter('to')) && $to !== '') {
            $where->add("(o.placed_at AT TIME ZONE 'Asia/Manila')::date <= CAST(? AS date)", $to);
        }
        if (($pattern = $query->likePattern()) !== null) {
            $where->add('o.order_number ILIKE ? OR c.full_name ILIKE ? OR c.email ILIKE ?', $pattern, $pattern, $pattern);
        }

        $from = 'FROM orders o JOIN customers c ON c.id = o.customer_id JOIN order_progress_steps s ON s.key = o.progress_key ';
        $total = (int) $this->scalar('SELECT COUNT(*) ' . $from . $where->sql(), $where->params());
        $rows = $this->rows(
            'SELECT o.id, o.order_number, o.channel, o.progress_key, s.label AS progress_label, o.payment_status, o.fulfillment_method, o.total, o.deposit_due, '
            . 'o.cancelled_at, o.refund_review, o.placed_at, o.customer_id, c.full_name AS customer_name '
            . $from . $where->sql() . ' ORDER BY ' . $query->orderBy . ' LIMIT ' . $query->perPage . ' OFFSET ' . $query->offset(),
            $where->params(),
        );

        return [$rows, $total];
    }
}
