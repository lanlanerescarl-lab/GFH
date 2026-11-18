<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/**
 * Reads the org_contract views for the dashboard and the Reports page. It never reads the operations tables directly.
 * The one exception is branchRows(): the contract has no per-branch figures, so that query reads the same two helper
 * views (sales_facts, sales_line_facts) that daily_sales is built from, which keeps one definition of net sales.
 */
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

    /**
     * One row per day that had sales, with every figure the Reports page shows.
     *
     * @return list<array<string, mixed>>
     */
    public function dailyRows(string $from, string $to): array
    {
        $statement = $this->pdo->prepare(
            'SELECT "date", net_revenue, cost, gross_profit, units, orders FROM org_contract.daily_sales '
            . 'WHERE "date" BETWEEN CAST(? AS date) AND CAST(? AS date) ORDER BY "date"',
        );
        $statement->execute([$from, $to]);

        return $statement->fetchAll();
    }

    /**
     * One row per month that had sales (month is YYYY-MM), added up from the same daily rows as the dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public function monthlyRows(string $from, string $to): array
    {
        $statement = $this->pdo->prepare(
            "SELECT to_char(\"date\", 'YYYY-MM') AS month, SUM(net_revenue) AS net_revenue, SUM(cost) AS cost, "
            . 'SUM(gross_profit) AS gross_profit, SUM(units) AS units, SUM(orders) AS orders '
            . 'FROM org_contract.daily_sales WHERE "date" BETWEEN CAST(? AS date) AND CAST(? AS date) '
            . 'GROUP BY 1 ORDER BY 1',
        );
        $statement->execute([$from, $to]);

        return $statement->fetchAll();
    }

    /**
     * One day split by branch (branch_id is null for a sale with no branch). The figures are worked out exactly as
     * daily_sales works them out, so the rows add up to that day's total.
     *
     * @return list<array<string, mixed>>
     */
    public function branchRows(string $day): array
    {
        $statement = $this->pdo->prepare(
            'WITH h AS (SELECT branch_id, SUM(net_revenue) AS net_revenue, COUNT(*) AS orders '
            . 'FROM sales_facts WHERE sale_date = CAST(? AS date) GROUP BY branch_id), '
            . 'l AS (SELECT f.branch_id, SUM(lf.qty) AS units, '
            . 'SUM(lf.line_net) FILTER (WHERE lf.has_cost) AS costed_net, SUM(lf.line_cost) FILTER (WHERE lf.has_cost) AS cost '
            . 'FROM sales_line_facts lf JOIN sales_facts f ON f.sale_id = lf.sale_id AND f.channel = lf.channel '
            . 'WHERE lf.sale_date = CAST(? AS date) GROUP BY f.branch_id) '
            . 'SELECT h.branch_id, ROUND(h.net_revenue, 2) AS net_revenue, ROUND(COALESCE(l.cost, 0), 2) AS cost, '
            . 'ROUND(COALESCE(l.costed_net, 0) - COALESCE(l.cost, 0), 2) AS gross_profit, COALESCE(l.units, 0) AS units, h.orders AS orders '
            . 'FROM h LEFT JOIN l ON l.branch_id IS NOT DISTINCT FROM h.branch_id',
        );
        $statement->execute([$day, $day]);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function branches(): array
    {
        return $this->pdo->query('SELECT id, name, status FROM branches ORDER BY name, id')->fetchAll();
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
