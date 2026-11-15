<?php
/**
 * Admin Dashboard screen (GET /{org}/admin).
 * Shows gross profit first, then net sales, orders, low-stock count and cost-completeness percentage,
 * with charts, alerts, order stages and extension widgets from dashboard.widgets.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, mixed> $dashboard
 * @var array<string, string> $periods
 * @var string $selectedPeriod
 * @var array<string, mixed> $period
 * @var string $dashboardUrl
 * @var string|null $inventoryUrl
 * @var string|null $reorderUrl
 * @var string|null $ordersUrl
 * @var string|null $procurementUrl
 */

use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Dashboard');
$this->end();

$this->start('head');
?>
    <script src="<?= $this->e($assets->shared('js/vendor/chart.js')) ?>" defer></script>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$kpis = $dashboard['kpis'] ?? [];
$series = $dashboard['series'] ?? [];
$stages = $dashboard['order_stages'] ?? [];
$alerts = $dashboard['alerts'] ?? [];
$widgets = $dashboard['widgets'] ?? [];

$gpPoints = [];
$salesPoints = [];
$orderPoints = [];
$chartLabels = [];
$chartGp = [];
$chartSales = [];
foreach ($series as $point) {
    $gpCents = Decimal::parse((string) ($point['gross_profit'] ?? '0'), 2) ?? 0;
    $salesCents = Decimal::parse((string) ($point['net_revenue'] ?? '0'), 2) ?? 0;
    $gpPoints[] = $gpCents;
    $salesPoints[] = $salesCents;
    $orderPoints[] = (int) ($point['orders'] ?? 0);
    $chartLabels[] = Format::date((string) ($point['date'] ?? ''));
    $chartGp[] = Decimal::format($gpCents, 2);
    $chartSales[] = Decimal::format($salesCents, 2);
}

$stageLabels = [];
$stageCounts = [];
foreach ($stages as $stage) {
    $stageLabels[] = (string) ($stage['stage_label'] ?? $stage['stage_key'] ?? '');
    $stageCounts[] = (string) ((int) ($stage['order_count'] ?? 0));
}

$periodRange = Format::date((string) ($period['from'] ?? '')) . ' – ' . Format::date((string) ($period['to'] ?? ''));
$lowCount = (int) ($kpis['low_stock_variants'] ?? 0);
$outCount = (int) ($kpis['out_of_stock_variants'] ?? 0);
$costCompletePct = $period['cost_complete_pct'] ?? null;
$costTone = $costCompletePct === null ? 'neutral' : ((Decimal::parse((string) $costCompletePct, 2) ?? 0) >= 9500 ? 'ok' : 'warn');

$periodLinksHtml = '<nav class="segmented" aria-label="Dashboard period">';
foreach ($periods as $key => $label) {
    $active = $key === $selectedPeriod;
    $href = $dashboardUrl . '?period=' . rawurlencode($key);
    $periodLinksHtml .= '<a class="segmented__option" href="' . $this->e($href) . '"'
        . ($active ? ' aria-current="page" aria-pressed="true"' : ' aria-pressed="false"')
        . '>' . $this->e($label) . '</a>';
}
$periodLinksHtml .= '</nav>';
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Overview · As of ' . Format::dateTime((string) ($dashboard['as_of'] ?? '')),
    'title' => 'Dashboard',
    'subtitle' => $periods[$selectedPeriod] . ' (' . $periodRange . ')',
    'actionsHtml' => $periodLinksHtml,
]) ?>

<section class="kit-section" aria-labelledby="dashboard-kpis-heading">
    <h2 class="visually-hidden" id="dashboard-kpis-heading">Key metrics</h2>
    <div class="kpi-grid">
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Gross profit',
            'value' => Format::peso((string) ($period['gross_profit'] ?? '0.00')),
            'delta' => $periods[$selectedPeriod],
            'tone' => (Decimal::parse((string) ($period['gross_profit'] ?? '0'), 2) ?? 0) >= 0 ? 'ok' : 'danger',
            'period' => 'Cost ' . Format::peso((string) ($period['cost'] ?? '0.00')),
            'points' => $gpPoints !== [] ? $gpPoints : [0, 0],
        ]) ?>
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Net sales (VAT-excl.)',
            'value' => Format::peso((string) ($period['net_sales'] ?? '0.00')),
            'delta' => Format::qty((string) ($period['units'] ?? '0')) . ' units',
            'tone' => 'info',
            'period' => $periodRange,
            'points' => $salesPoints !== [] ? $salesPoints : [0, 0],
        ]) ?>
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Orders',
            'value' => (string) ((int) ($period['orders'] ?? 0)),
            'delta' => (int) ($kpis['active_orders'] ?? 0) . ' active',
            'tone' => 'info',
            'period' => 'Online & POS sales',
            'points' => $orderPoints !== [] ? $orderPoints : [0, 0],
        ]) ?>
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Low-stock count',
            'value' => (string) $lowCount,
            'delta' => $outCount > 0 ? $outCount . ' out of stock' : 'In stock',
            'tone' => $outCount > 0 ? 'danger' : ($lowCount > 0 ? 'warn' : 'ok'),
            'period' => 'Variants at or below reorder',
            'points' => [$lowCount, $lowCount],
        ]) ?>
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Cost completeness',
            'value' => Format::pct($costCompletePct !== null ? (string) $costCompletePct : null),
            'delta' => $costCompletePct === null ? 'No sales yet' : 'Costed share',
            'tone' => $costTone,
            'period' => 'Share of net sales with cost',
            'points' => [0, $costCompletePct === null ? 0 : (Decimal::parse((string) $costCompletePct, 2) ?? 0)],
        ]) ?>
    </div>
</section>

<section class="kit-section" aria-labelledby="dashboard-charts-heading">
    <h2 class="kit-section__title" id="dashboard-charts-heading">Performance &amp; fulfilment</h2>
    <div class="kit-grid">
        <?php
        $salesChartBody = $series === []
            ? $this->partial('partials/empty-state', [
                'title' => 'No sales in the last 30 days',
                'body' => 'Completed orders and POS sales will chart here once recorded.',
            ])
            : '<div class="chart-wrap"><canvas data-chart="line" data-chart-labels="'
                . $this->e(implode('|', $chartLabels))
                . '" data-chart-series="'
                . $this->e('Gross profit (₱):' . implode(',', $chartGp) . ';Net sales (₱):' . implode(',', $chartSales))
                . '" aria-label="Daily gross profit and net sales over the last 30 days" role="img"></canvas></div>';

        $stageChartBody = $stages === []
            ? $this->partial('partials/empty-state', [
                'title' => 'No order stages configured',
                'body' => 'Order progress stages will appear once configured in Settings.',
            ])
            : '<div class="chart-wrap"><canvas data-chart="bar" data-chart-labels="'
                . $this->e(implode('|', $stageLabels))
                . '" data-chart-series="'
                . $this->e('Orders:' . implode(',', $stageCounts))
                . '" aria-label="Active orders by progress stage" role="img"></canvas></div>';
        ?>
        <?= $this->partial('partials/card', [
            'title' => 'Gross profit & net sales (Last 30 days)',
            'subtitle' => 'Daily VAT-excluded revenue and gross profit',
            'bodyHtml' => $salesChartBody,
        ]) ?>
        <?= $this->partial('partials/card', [
            'title' => 'Orders by stage',
            'subtitle' => 'Current active orders across progress steps',
            'headerActionsHtml' => $ordersUrl !== null ? '<a class="button button--secondary button--sm" href="' . $this->e($ordersUrl) . '">View orders</a>' : '',
            'bodyHtml' => $stageChartBody,
        ]) ?>
    </div>
</section>

<section class="kit-section" aria-labelledby="dashboard-ops-heading">
    <h2 class="kit-section__title" id="dashboard-ops-heading">Operations &amp; alerts</h2>
    <div class="kit-grid">
        <?php
        $periodRows = '';
        foreach ($periods as $key => $label) {
            $row = $dashboard['periods'][$key] ?? [];
            $periodRows .= '<tr>'
                . '<td><strong>' . $this->e($label) . '</strong></td>'
                . '<td class="data-table__num">' . $this->e(Format::peso((string) ($row['gross_profit'] ?? '0.00'))) . '</td>'
                . '<td class="data-table__num">' . $this->e(Format::peso((string) ($row['net_sales'] ?? '0.00'))) . '</td>'
                . '<td class="data-table__num">' . $this->e((string) ((int) ($row['orders'] ?? 0))) . '</td>'
                . '<td class="data-table__num">' . $this->e(Format::pct(isset($row['cost_complete_pct']) ? (string) $row['cost_complete_pct'] : null)) . '</td>'
                . '</tr>';
        }
        $periodTableHtml = '<div class="table-wrap"><table class="data-table"><thead><tr>'
            . '<th scope="col">Period</th>'
            . '<th scope="col" class="data-table__num">Gross profit</th>'
            . '<th scope="col" class="data-table__num">Net sales</th>'
            . '<th scope="col" class="data-table__num">Orders</th>'
            . '<th scope="col" class="data-table__num">Cost %</th>'
            . '</tr></thead><tbody>' . $periodRows . '</tbody></table></div>';

        $opsHtml = '<dl class="metric-list">'
            . '<div class="metric-list__row"><dt>Low-stock variants</dt><dd>' . $this->e((string) $lowCount) . '</dd></div>'
            . '<div class="metric-list__row"><dt>Out-of-stock variants</dt><dd>' . $this->e((string) $outCount) . '</dd></div>'
            . '<div class="metric-list__row"><dt>Active orders</dt><dd>' . $this->e((string) ((int) ($kpis['active_orders'] ?? 0))) . '</dd></div>'
            . '<div class="metric-list__row"><dt>Open purchase orders</dt><dd>' . $this->e((string) ((int) ($kpis['open_purchase_orders'] ?? 0))) . '</dd></div>'
            . '<div class="metric-list__row"><dt>Overdue purchase orders</dt><dd>' . $this->e((string) ((int) ($kpis['overdue_purchase_orders'] ?? 0))) . '</dd></div>'
            . '</dl>';
        $opsFooter = '';
        if ($inventoryUrl !== null) {
            $opsFooter .= '<a class="button button--secondary button--sm" href="' . $this->e($inventoryUrl) . '">Inventory</a>';
        }
        if ($reorderUrl !== null) {
            $opsFooter .= '<a class="button button--secondary button--sm" href="' . $this->e($reorderUrl) . '">Reorder suggestions</a>';
        }
        if ($procurementUrl !== null) {
            $opsFooter .= '<a class="button button--secondary button--sm" href="' . $this->e($procurementUrl) . '">Procurement</a>';
        }
        ?>
        <?= $this->partial('partials/card', [
            'title' => 'Period summary',
            'subtitle' => 'Today, month to date and last 30 days',
            'bodyHtml' => $periodTableHtml,
        ]) ?>
        <?= $this->partial('partials/card', [
            'title' => 'Operational snapshot',
            'subtitle' => 'Stock and open pipeline counts',
            'bodyHtml' => $opsHtml,
            'footerHtml' => $opsFooter,
        ]) ?>
    </div>
</section>

<section class="kit-section" aria-labelledby="dashboard-alerts-heading">
    <h2 class="kit-section__title" id="dashboard-alerts-heading">Attention needed</h2>
    <?php
    if ($alerts === []) {
        $alertsBody = $this->partial('partials/empty-state', [
            'title' => 'No active alerts',
            'body' => 'Stock levels, batches, deliveries and POS syncs are all clear.',
        ]);
    } else {
        $itemsHtml = '<ul class="alert-list">';
        foreach ($alerts as $alert) {
            $severity = (string) ($alert['severity'] ?? 'info');
            $tone = $severity === 'critical' ? 'danger' : ($severity === 'warning' ? 'warn' : 'info');
            $itemsHtml .= '<li class="alert-list__item">'
                . '<div class="alert-list__main">'
                . $this->partial('partials/badge', ['label' => ucfirst($severity), 'tone' => $tone])
                . '<span>' . $this->e((string) ($alert['message'] ?? '')) . '</span>'
                . '</div>'
                . '<span class="alert-list__time">' . $this->e(Format::dateTime((string) ($alert['raised_at'] ?? ''))) . '</span>'
                . '</li>';
        }
        $itemsHtml .= '</ul>';
        $alertsBody = $itemsHtml;
    }
    ?>
    <?= $this->partial('partials/card', [
        'title' => 'Recent alerts',
        'subtitle' => 'Low stock, out of stock, expiring batches, overdue deliveries and POS review queue',
        'bodyHtml' => $alertsBody,
    ]) ?>
</section>

<?= $this->partial('partials/dashboard-widgets', ['widgets' => $widgets]) ?>
