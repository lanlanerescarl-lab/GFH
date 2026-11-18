<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\ReportRepository;
use Gfh\Core\Support\Format;

/**
 * Dashboard figures, read from the org_contract views so the Admin dashboard, the Super Admin app and (later)
 * the OLAP module all show the same numbers. The Reports page (periodReport) uses the same views and the same period
 * totals as the dashboard, so the two can never disagree; the OLAP module adds deeper analysis later.
 *
 * Gross profit is net sales (VAT removed, after discounts) minus the cost of the lines that have a cost.
 * Cost completeness is the share of net sales that comes from lines with a known cost; for a period it is the
 * average of the daily percentages weighted by each day's net sales.
 */
final class ReportService
{
    /** The calendar views of the Reports page. */
    public const VIEWS = ['day' => 'Day', 'month' => 'Month', 'year' => 'Year'];

    /** The query-string field each view reads its calendar value from. */
    public const FIELDS = ['day' => 'date', 'month' => 'month', 'year' => 'year'];

    /** Every calendar day, month and year is reckoned in this zone (never UTC). */
    public const TIMEZONE = 'Asia/Manila';

    private const FIRST_YEAR = 2000;
    private const LAST_YEAR = 2100;

    public function __construct(private readonly ReportRepository $repository, private readonly Hooks $hooks)
    {
    }

    /**
     * @param \DateTimeImmutable|null $now the current moment (tests pass one); defaults to now in Manila
     * @return array<string, mixed>
     */
    public function dashboard(?\DateTimeImmutable $now = null): array
    {
        $today = $this->today($now);

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

    /** The current moment as a Manila calendar date. A sale at 00:30 Manila time is already tomorrow's, whatever UTC says. */
    public function today(?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $zone = new \DateTimeZone(self::TIMEZONE);

        return ($now ?? new \DateTimeImmutable('now', $zone))->setTimezone($zone);
    }

    /**
     * One calendar period of the Reports page.
     *
     * $view is day, month or year and $value is what the person picked: YYYY-MM-DD, YYYY-MM or YYYY. An empty value
     * means the current day, month or year in Manila. An unreadable value is refused with a field error named after
     * the field (date, month or year). Totals come from the same queries as the dashboard. The breakdown is by branch
     * for a day, by day for a month and by month for a year, with zero rows where nothing was sold, so an empty
     * period reads as zeros.
     *
     * @return array<string, mixed>
     * @throws HttpException 422 when the value is not a real date inside the supported years
     */
    public function periodReport(string $view, string $value = '', ?\DateTimeImmutable $now = null): array
    {
        if (!isset(self::VIEWS[$view])) {
            $view = 'day';
        }

        $today = $this->today($now);
        [$start, $end] = $this->range($view, trim($value), $today);
        $from = $start->format('Y-m-d');
        $to = $end->format('Y-m-d');

        $totals = $this->period($from, $to);
        $breakdown = match ($view) {
            'day' => $this->byBranch($from),
            'month' => $this->byDay($start, $end),
            default => $this->byMonth($start),
        };

        $report = [
            'view' => $view,
            'field' => self::FIELDS[$view],
            'value' => match ($view) {
                'day' => $from,
                'month' => $start->format('Y-m'),
                default => $start->format('Y'),
            },
            'label' => match ($view) {
                'day' => Format::date($from),
                'month' => $start->format('F Y'),
                default => $start->format('Y'),
            },
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'breakdown' => $breakdown,
            'previous' => $this->neighbour($view, $start, -1),
            'next' => $this->neighbour($view, $start, 1),
            'switch' => [
                'day' => ['view' => 'day', 'date' => $from],
                'month' => ['view' => 'month', 'month' => $start->format('Y-m')],
                'year' => ['view' => 'year', 'year' => $start->format('Y')],
            ],
        ];

        $tabs = $this->hooks->filter(Hooks::REPORTS_TABS, [], [
            'view' => $view,
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
        ]);
        $report['extension_tabs'] = $this->normalizeTabs(is_array($tabs) ? $tabs : []);

        return $report;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} first and last calendar day of the period
     */
    private function range(string $view, string $value, \DateTimeImmutable $today): array
    {
        $zone = new \DateTimeZone(self::TIMEZONE);
        $field = self::FIELDS[$view];

        if ($value === '') {
            $value = match ($view) {
                'day' => $today->format('Y-m-d'),
                'month' => $today->format('Y-m'),
                default => $today->format('Y'),
            };
        }

        // The examples come from today in Manila, so they never go stale like a fixed date would.
        [$pattern, $format, $example, $missing] = match ($view) {
            'day' => ['/^\d{4}-\d{2}-\d{2}$/', '!Y-m-d', 'a date as YYYY-MM-DD, for example ' . $today->format('Y-m-d'), 'That date does not exist in the calendar. Enter a real date, for example ' . $today->format('Y-m-d') . '.'],
            'month' => ['/^\d{4}-\d{2}$/', '!Y-m', 'a month as YYYY-MM, for example ' . $today->format('Y-m'), 'That month does not exist. Use a month from 01 to 12, for example ' . $today->format('Y-m') . '.'],
            default => ['/^\d{4}$/', '!Y', 'a four-digit year, for example ' . $today->format('Y'), 'That year does not exist. Enter a four-digit year, for example ' . $today->format('Y') . '.'],
        };

        // Two different mistakes: the text is not in the expected shape, or it is in the shape but names a day (or month)
        // the calendar does not have, such as 2026-02-30 or 2026-13.
        if (preg_match($pattern, $value) !== 1) {
            throw HttpException::unprocessable([$field => 'Enter ' . $example . '.']);
        }
        $start = \DateTimeImmutable::createFromFormat($format, $value, $zone);
        if ($start === false || $start->format(substr($format, 1)) !== $value) {
            throw HttpException::unprocessable([$field => $missing]);
        }

        $year = (int) $start->format('Y');
        if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR) {
            throw HttpException::unprocessable([$field => sprintf('Choose a year between %d and %d.', self::FIRST_YEAR, self::LAST_YEAR)]);
        }

        $end = match ($view) {
            'day' => $start,
            'month' => $start->modify('last day of this month'),
            default => $start->setDate((int) $start->format('Y'), 12, 31),
        };

        return [$start, $end];
    }

    /** The value of the period before (-1) or after (+1), or null when it would leave the supported years. */
    private function neighbour(string $view, \DateTimeImmutable $start, int $step): ?string
    {
        $moved = match ($view) {
            'day' => $start->modify(sprintf('%+d day', $step)),
            'month' => $start->modify(sprintf('%+d month', $step)),
            default => $start->modify(sprintf('%+d year', $step)),
        };
        $year = (int) $moved->format('Y');
        if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR) {
            return null;
        }

        return match ($view) {
            'day' => $moved->format('Y-m-d'),
            'month' => $moved->format('Y-m'),
            default => $moved->format('Y'),
        };
    }

    /** @return array<string, mixed> */
    private function byBranch(string $day): array
    {
        $sold = [];
        foreach ($this->repository->branchRows($day) as $row) {
            $sold[(string) ($row['branch_id'] ?? '')] = $row;
        }

        $rows = [];
        foreach ($this->repository->branches() as $branch) {
            $id = (string) $branch['id'];
            // An inactive branch is listed only if it still sold something that day.
            if ($branch['status'] !== 'active' && !isset($sold[$id])) {
                continue;
            }
            $rows[] = $this->row($id, (string) $branch['name'], $sold[$id] ?? []);
            unset($sold[$id]);
        }
        if (isset($sold[''])) {
            $rows[] = $this->row('none', 'No branch', $sold['']);
        }

        return ['kind' => 'branch', 'title' => 'By branch', 'column' => 'Branch', 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function byDay(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $sold = [];
        foreach ($this->repository->dailyRows($start->format('Y-m-d'), $end->format('Y-m-d')) as $row) {
            $sold[(string) $row['date']] = $row;
        }

        $rows = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $rows[] = $this->row($key, Format::date($key), $sold[$key] ?? [], $day->format('j M'));
        }

        return ['kind' => 'day', 'title' => 'By day', 'column' => 'Day', 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function byMonth(\DateTimeImmutable $start): array
    {
        $sold = [];
        foreach ($this->repository->monthlyRows($start->format('Y-01-01'), $start->format('Y-12-31')) as $row) {
            $sold[(string) $row['month']] = $row;
        }

        $rows = [];
        for ($month = 1; $month <= 12; $month++) {
            $key = sprintf('%s-%02d', $start->format('Y'), $month);
            $first = \DateTimeImmutable::createFromFormat('!Y-m', $key, new \DateTimeZone(self::TIMEZONE));
            $rows[] = $this->row($key, $first->format('F Y'), $sold[$key] ?? [], $first->format('M'));
        }

        return ['kind' => 'month', 'title' => 'By month', 'column' => 'Month', 'rows' => $rows];
    }

    /**
     * One breakdown row. A missing source row is a row of zeros.
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function row(string $key, string $label, array $source, ?string $short = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'short' => $short ?? $label,
            'net_sales' => $this->money($source['net_revenue'] ?? '0'),
            'gross_profit' => $this->money($source['gross_profit'] ?? '0'),
            'cost' => $this->money($source['cost'] ?? '0'),
            'units' => (string) ($source['units'] ?? '0'),
            'orders' => (int) ($source['orders'] ?? 0),
        ];
    }

    /**
     * Extension tabs: each needs a label; the id is cleaned to letters, digits, underscore and hyphen, and the
     * built-in "sales" tab cannot be replaced. contentHtml is the extension's own HTML, as in product.form.tabs.
     *
     * @param array<mixed> $raw
     * @return list<array{id: string, label: string, contentHtml: string}>
     */
    private function normalizeTabs(array $raw): array
    {
        $tabs = [];
        foreach ($raw as $index => $tab) {
            if (!is_array($tab)) {
                continue;
            }
            $label = trim((string) ($tab['label'] ?? ''));
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) ($tab['id'] ?? 'ext-' . $index)) ?? ('ext-' . $index);
            if ($label === '' || $id === '' || $id === 'sales') {
                continue;
            }
            $tabs[] = ['id' => $id, 'label' => $label, 'contentHtml' => (string) ($tab['contentHtml'] ?? $tab['content'] ?? '')];
        }

        return $tabs;
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
