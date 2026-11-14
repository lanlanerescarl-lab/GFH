<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Repository\ReportRepository;

/**
 * Dashboard figures, read from the org_contract views so the Admin dashboard, the Super Admin app and (later)
 * the OLAP module all show the same numbers. Calendar reports by day, month and year are not here: the OLAP module
 * replaces them.
 *
 * Gross profit is net sales (VAT removed, after discounts) minus the cost of the lines that have a cost.
 * Cost completeness is the share of net sales that comes from lines with a known cost; for a period it is the
 * average of the daily percentages weighted by each day's net sales.
 */
final class ReportService
{
    public function __construct(private readonly ReportRepository $repository, private readonly Hooks $hooks)
    {
    }

    /**
     * @param \DateTimeImmutable|null $now the current moment (tests pass one); defaults to now in Manila
     * @return array<string, mixed>
     */
    public function dashboard(?\DateTimeImmutable $now = null): array
    {
        $zone = new \DateTimeZone('Asia/Manila');
        $today = ($now ?? new \DateTimeImmutable('now', $zone))->setTimezone($zone);

        $todayText = $today->format('Y-m-d');
        $monthStart = $today->modify('first day of this month')->format('Y-m-d');
        $thirtyAgo = $today->modify('-29 days')->format('Y-m-d');

        $kpis = $this->repository->kpis();
        $widgets = $this->hooks->filter(Hooks::DASHBOARD_WIDGETS, [], ['as_of' => $todayText]);

        return [
            'as_of' => $today->format('c'),
            'periods' => [
                'today' => $this->period($todayText, $todayText),
                'month_to_date' => $this->period($monthStart, $todayText),
                'last_30_days' => $this->period($thirtyAgo, $todayText),
            ],
            'series' => array_map(static fn (array $r): array => [
                'date' => (string) $r['date'],
                'net_revenue' => (string) $r['net_revenue'],
                'gross_profit' => (string) $r['gross_profit'],
                'orders' => (int) $r['orders'],
            ], $this->repository->series($thirtyAgo, $todayText)),
            'kpis' => [
                'low_stock_variants' => (int) ($kpis['low_stock_variants'] ?? 0),
                'out_of_stock_variants' => (int) ($kpis['out_of_stock_variants'] ?? 0),
                'active_orders' => (int) ($kpis['active_orders'] ?? 0),
                'open_purchase_orders' => (int) ($kpis['open_purchase_orders'] ?? 0),
                'overdue_purchase_orders' => (int) ($kpis['overdue_purchase_orders'] ?? 0),
            ],
            'alerts' => $this->repository->alerts(10),
            'order_stages' => $this->repository->stageCounts(),
            'widgets' => is_array($widgets) ? $widgets : [],
        ];
    }

    /** @return array<string, mixed> */
    private function period(string $from, string $to): array
    {
        $row = $this->repository->totals($from, $to);

        return [
            'from' => $from,
            'to' => $to,
            'gross_profit' => $this->money($row['gross_profit'] ?? '0'),
            'net_sales' => $this->money($row['net_revenue'] ?? '0'),
            'cost' => $this->money($row['cost'] ?? '0'),
            'units' => (string) ($row['units'] ?? '0'),
            'orders' => (int) ($row['orders'] ?? 0),
            'cost_complete_pct' => isset($row['cost_complete_pct']) ? $this->money($row['cost_complete_pct']) : null,
        ];
    }

    /** The database already rounds to centavos; this only makes sure there are two decimals, without using floats. */
    private function money(mixed $value): string
    {
        $text = (string) $value;

        return str_contains($text, '.') ? $text : $text . '.00';
    }
}
