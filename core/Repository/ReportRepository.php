<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/** Reads the org_contract views for the dashboard. It never reads the operations tables directly. */
final class ReportRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Totals over a range of calendar days (Manila).
     *
     * @return array<string, mixed>
     */
    public function totals(string $from, string $to): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(net_revenue), 0) AS net_revenue, COALESCE(SUM(cost), 0) AS cost, COALESCE(SUM(gross_profit), 0) AS gross_profit, '
            . 'COALESCE(SUM(units), 0) AS units, COALESCE(SUM(orders), 0) AS orders, '
            . 'ROUND(SUM(net_revenue * cost_complete_pct) / NULLIF(SUM(CASE WHEN cost_complete_pct IS NOT NULL THEN net_revenue END), 0), 2) AS cost_complete_pct '
            . 'FROM org_contract.daily_sales WHERE "date" BETWEEN CAST(? AS date) AND CAST(? AS date)',
        );
        $statement->execute([$from, $to]);

        return $statement->fetch() ?: [];
    }

    /** @return list<array<string, mixed>> one row per day that had sales */
    public function series(string $from, string $to): array
    {
        $statement = $this->pdo->prepare(
            'SELECT "date", net_revenue, gross_profit, orders FROM org_contract.daily_sales WHERE "date" BETWEEN CAST(? AS date) AND CAST(? AS date) ORDER BY "date"',
        );
        $statement->execute([$from, $to]);

        return $statement->fetchAll();
    }

    /** @return array<string, string> kpi_key => value */
    public function kpis(): array
    {
        $result = [];
        foreach ($this->pdo->query('SELECT kpi_key, value FROM org_contract.kpis')->fetchAll() as $row) {
            $result[(string) $row['kpi_key']] = (string) $row['value'];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function alerts(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT alert_type, severity, entity, entity_id, message, raised_at FROM org_contract.alerts "
            . "ORDER BY CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END, raised_at DESC LIMIT " . max(1, $limit),
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function stageCounts(): array
    {
        return $this->pdo->query('SELECT stage_key, stage_label, mapped_group, order_count FROM org_contract.order_stage_counts ORDER BY sort_order')->fetchAll();
    }
}
