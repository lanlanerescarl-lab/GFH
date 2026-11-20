<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/**
 * All SQL for the order progress steps in an organization database. It holds no business rule: OrderStepService
 * decides. The order path itself reads steps through OrderRepository::steps(); this is the admin side.
 */
final class OrderStepRepository
{
    private const COLUMNS = 's.key, s.label, s.sort_order, s.is_terminal, s.maps_to, s.applies_when';

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
     * Every step in path order, with the number of orders on it. The count leaves out cancelled orders, like the
     * contract view org_contract.order_stage_counts does, so the two never disagree.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->rows(
            'SELECT ' . self::COLUMNS . ', '
            . '(SELECT COUNT(*) FROM orders o WHERE o.progress_key = s.key AND o.cancelled_at IS NULL) AS order_count '
            . 'FROM order_progress_steps s ORDER BY s.sort_order ASC, s.key ASC',
        );
    }

    /**
     * Every step in path order with its row locked, so two Admins cannot change the steps at the same time and each
     * sees the other's result before checking the rules.
     *
     * @return list<array<string, mixed>>
     */
    public function lockAll(): array
    {
        return $this->rows('SELECT ' . self::COLUMNS . ' FROM order_progress_steps s ORDER BY s.sort_order ASC, s.key ASC FOR UPDATE');
    }

    /** Serialises step changes, including the first insert into an empty table, which has no row to lock. */
    public function lockChanges(): void
    {
        $this->pdo->exec("SELECT pg_advisory_xact_lock(hashtext('gfh_order_steps'))");
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        return $this->rows(
            'SELECT ' . self::COLUMNS . ', '
            . '(SELECT COUNT(*) FROM orders o WHERE o.progress_key = s.key AND o.cancelled_at IS NULL) AS order_count, '
            . '(SELECT COUNT(*) FROM orders o WHERE o.progress_key = s.key) AS orders_total, '
            . '(SELECT COUNT(*) FROM order_status_history h WHERE h.progress_key = s.key) AS history_total '
            . 'FROM order_progress_steps s WHERE s.key = ?',
            [$key],
        )[0] ?? null;
    }

    /**
     * Orders that are still moving: not cancelled and not on a terminal step. The same meaning BranchRepository uses
     * when it refuses to deactivate a branch.
     */
    public function countOpenOrders(): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM orders o JOIN order_progress_steps s ON s.key = o.progress_key '
            . 'WHERE o.cancelled_at IS NULL AND s.is_terminal = false',
        );
    }

    public function insert(string $key, string $label, int $sortOrder, bool $isTerminal, string $mapsTo, string $appliesWhen): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO order_progress_steps (key, label, sort_order, is_terminal, maps_to, applies_when) '
            . 'VALUES (:key, :label, :sort_order, :is_terminal, :maps_to, :applies_when)',
        );
        $statement->bindValue('key', $key);
        $statement->bindValue('label', $label);
        $statement->bindValue('sort_order', $sortOrder, \PDO::PARAM_INT);
        $statement->bindValue('is_terminal', $isTerminal, \PDO::PARAM_BOOL);
        $statement->bindValue('maps_to', $mapsTo);
        $statement->bindValue('applies_when', $appliesWhen);
        $statement->execute();
    }

    /** The key never changes: orders and history point at it. */
    public function update(string $key, string $label, int $sortOrder, bool $isTerminal, string $mapsTo, string $appliesWhen): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE order_progress_steps SET label = :label, sort_order = :sort_order, is_terminal = :is_terminal, '
            . 'maps_to = :maps_to, applies_when = :applies_when WHERE key = :key',
        );
        $statement->bindValue('label', $label);
        $statement->bindValue('sort_order', $sortOrder, \PDO::PARAM_INT);
        $statement->bindValue('is_terminal', $isTerminal, \PDO::PARAM_BOOL);
        $statement->bindValue('maps_to', $mapsTo);
        $statement->bindValue('applies_when', $appliesWhen);
        $statement->bindValue('key', $key);
        $statement->execute();
    }

    public function delete(string $key): void
    {
        $this->pdo->prepare('DELETE FROM order_progress_steps WHERE key = ?')->execute([$key]);
    }
}
