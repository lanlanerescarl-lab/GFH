<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/**
 * All SQL for branches in an organization database, plus the counts that decide whether a branch may be deactivated.
 * It holds no business rule: BranchService decides what the numbers mean.
 */
final class BranchRepository
{
    private const COLUMNS = 'id, code, name, address, city, province, region, kind, is_pickup_point, is_delivery_origin, status, created_at, updated_at';

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
     * Serialises every branch write for the rest of the transaction. Branches are few and written rarely, so one lock
     * is the simplest way to make "at most one delivery branch" and "deactivate only when nothing uses it" safe when
     * two Admins save at once, and it cannot deadlock because it is always taken first.
     */
    public function lockBranchWrites(): void
    {
        $this->pdo->query("SELECT pg_advisory_xact_lock(hashtext('gfh.branches.write'))")->fetchColumn();
    }

    /**
     * Every branch with the number of its active terminals, active branches first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->rows(
            'SELECT b.id, b.code, b.name, b.address, b.city, b.province, b.region, b.kind, b.is_pickup_point, b.is_delivery_origin, b.status, '
            . "(SELECT COUNT(*) FROM terminals t WHERE t.branch_id = b.id AND t.status = 'active') AS active_terminals "
            . "FROM branches b ORDER BY (b.status = 'active') DESC, b.name ASC, b.code ASC",
        );
    }

    /**
     * One branch. $lock is '' (plain read), 'update' (row lock for a change) or 'share' (keeps the branch from being
     * changed while a terminal is added to it). The SQL text is fixed; nothing from outside is put into it.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $lock = ''): ?array
    {
        $suffix = match ($lock) {
            'update' => ' FOR UPDATE',
            'share' => ' FOR SHARE',
            default => '',
        };

        return $this->rows('SELECT ' . self::COLUMNS . ' FROM branches WHERE id = ?' . $suffix, [$id])[0] ?? null;
    }

    public function idByCode(string $code): ?string
    {
        $id = $this->scalar('SELECT id FROM branches WHERE code = ?', [$code]);

        return $id === false ? null : (string) $id;
    }

    /** Active branches for a drop-down, in name order. @return list<array<string, mixed>> */
    public function activeOptions(): array
    {
        return $this->rows("SELECT id, code, name FROM branches WHERE status = 'active' ORDER BY name ASC, code ASC");
    }

    /** @param array<string, mixed> $f cleaned fields: code, name, address, city, province, region, kind, is_pickup_point, is_delivery_origin */
    public function insert(array $f): string
    {
        return (string) $this->scalar(
            'INSERT INTO branches (code, name, address, city, province, region, kind, is_pickup_point, is_delivery_origin) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $f['code'], $f['name'], $f['address'], $f['city'], $f['province'], $f['region'], $f['kind'],
                $f['is_pickup_point'] ? 'true' : 'false', $f['is_delivery_origin'] ? 'true' : 'false',
            ],
        );
    }

    /** @param array<string, mixed> $f cleaned fields: name, address, city, province, region, kind, is_pickup_point, is_delivery_origin (never code or status) */
    public function update(string $id, array $f): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE branches SET name = ?, address = ?, city = ?, province = ?, region = ?, kind = ?, is_pickup_point = ?, is_delivery_origin = ? WHERE id = ?',
        );
        $statement->execute([
            $f['name'], $f['address'], $f['city'], $f['province'], $f['region'], $f['kind'],
            $f['is_pickup_point'] ? 'true' : 'false', $f['is_delivery_origin'] ? 'true' : 'false', $id,
        ]);
    }

    public function setStatus(string $id, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE branches SET status = ? WHERE id = ?');
        $statement->execute([$status, $id]);
    }

    /**
     * Takes the delivery designation away from whichever branch holds it and returns that branch's id, or null when
     * no branch held it. The database allows only one, so this runs before the new designation is written.
     */
    public function clearDeliveryOrigin(): ?string
    {
        $id = $this->scalar('UPDATE branches SET is_delivery_origin = false WHERE is_delivery_origin RETURNING id');

        return $id === false ? null : (string) $id;
    }

    // ------------------------------------------------------------------ deactivation checks (one query each)

    /** Active terminals at the branch. */
    public function countActiveTerminals(string $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM terminals WHERE branch_id = ? AND status = 'active'", [$id]);
    }

    /**
     * Products (variants) with stock on hand at the branch. On hand is the sum of the append-only movements per
     * branch and variant, the same sum the Inventory screen shows, so a variant counts when that sum is above zero.
     */
    public function countVariantsWithStockOnHand(string $id): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM (SELECT 1 FROM stock_movements WHERE branch_id = ? GROUP BY variant_id HAVING SUM(quantity_delta) > 0) AS stocked',
            [$id],
        );
    }

    /**
     * Open orders that use the branch as their pickup branch or ship-from branch. Open means not cancelled and not on
     * a step the organization marked terminal (order_progress_steps.is_terminal).
     */
    public function countOpenOrders(string $id): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM orders o JOIN order_progress_steps s ON s.key = o.progress_key '
            . 'WHERE o.cancelled_at IS NULL AND s.is_terminal = false AND (o.pickup_branch_id = ? OR o.ship_from_branch_id = ?)',
            [$id, $id],
        );
    }
}
