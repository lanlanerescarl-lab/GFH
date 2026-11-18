<?php
/**
 * Admin Reports page (GET /{org}/admin/reports): one calendar day, month or year at a time.
 * Net sales, gross profit, cost, units and orders, a breakdown (by branch for a day, by day for a month, by month
 * for a year) and one chart. Extension tabs arrive through reports.tabs.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var string $view day | month | year
 * @var array<string, string> $viewLabels
 * @var string $field date | month | year
 * @var string $input the value shown in the selector
 * @var array<string, string> $errors
 * @var array<string, mixed>|null $report null when the chosen value was refused
 * @var string $reportsUrl
 * @var list<array{key: string, label: string, url: string, active: bool}> $viewLinks
 * @var string|null $previousUrl
 * @var string|null $nextUrl
 * @var string $dashboardUrl
 */

use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Reports');
$this->end();

$this->start('head');
?>
    <script src="<?= $this->e($assets->shared('js/vendor/chart.js')) ?>" defer></script>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$inputLabels = ['day' => 'Day', 'month' => 'Month (YYYY-MM)', 'year' => 'Year'];
$inputTypes = ['day' => 'date', 'month' => 'month', 'year' => 'number'];

$linksHtml = '<nav class="segmented" aria-label="Report period type">';
foreach ($viewLinks as $link) {
    $linksHtml .= '<a class="segmented__option" href="' . $this->e($link['url']) . '"'
        . ($link['active'] ? ' aria-current="page" aria-pressed="true"' : ' aria-pressed="false"')
        . '>' . $this->e($link['label']) . '</a>';
}
$linksHtml .= '</nav>';
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Reports',
    'title' => 'Reports',
    'subtitle' => $report !== null ? $viewLabels[$view] . ': ' . $report['label'] : 'Choose a day, month or year.',
    'actionsHtml' => $linksHtml,
]) ?>

<section class="kit-section" aria-labelledby="reports-select-heading">
    <h2 class="visually-hidden" id="reports-select-heading">Choose the period</h2>
    <form class="inline-form inline-form--spaced" method="get" action="<?= $this->e($reportsUrl) ?>" novalidate>
        <input type="hidden" name="view" value="<?= $this->e($view) ?>">
        <?= $this->partial('partials/field', [
            'id' => 'report-' . $field,
            'name' => $field,
            'type' => $inputTypes[$view],
            'label' => $inputLabels[$view],
            'value' => $input,
            'placeholder' => $view === 'month' ? '2026-11' : ($view === 'year' ? '2026' : null),
            'min' => $view === 'year' ? '2000' : null,
            'max' => $view === 'year' ? '2100' : null,
            'required' => true,
            'error' => $errors[$field] ?? null,
        ]) ?>
        <?= $this->partial('partials/button', ['label' => 'Show report', 'variant' => 'primary', 'type' => 'submit']) ?>
<?php if ($report !== null): ?>
        <?php if ($previousUrl !== null): ?><a class="button button--secondary" href="<?= $this->e($previousUrl) ?>">Previous <?= $this->e(strtolower($viewLabels[$view])) ?></a><?php endif; ?>
        <?php if ($nextUrl !== null): ?><a class="button button--secondary" href="<?= $this->e($nextUrl) ?>">Next <?= $this->e(strtolower($viewLabels[$view])) ?></a><?php endif; ?>
<?php endif; ?>
    </form>
</section>

<?php if ($report === null): ?>
<section class="kit-section">
    <?= $this->partial('partials/empty-state', [
        'title' => 'Choose a valid period',
        'body' => 'Correct the highlighted field and the report will appear here.',
    ]) ?>
</section>
<?php else: ?>
<?php
$totals = $report['totals'];
$breakdown = $report['breakdown'];
$rows = $breakdown['rows'];

$salesPoints = [];
$labels = [];
$chartSales = [];
$chartProfit = [];
$hasSales = (int) $totals['orders'] > 0;
foreach ($rows as $row) {
    $salesCents = Decimal::parse((string) $row['net_sales'], 2) ?? 0;
    $profitCents = Decimal::parse((string) $row['gross_profit'], 2) ?? 0;
    $salesPoints[] = $salesCents;
    $labels[] = str_replace('|', '/', (string) $row['short']);
    $chartSales[] = Decimal::format($salesCents, 2);
    $chartProfit[] = Decimal::format($profitCents, 2);
}
$grossCents = Decimal::parse((string) $totals['gross_profit'], 2) ?? 0;
$costComplete = $totals['cost_complete_pct'] ?? null;

ob_start();
?>
<section class="kit-section" aria-labelledby="reports-kpis-heading">
    <h2 class="visually-hidden" id="reports-kpis-heading">Totals for <?= $this->e($report['label']) ?></h2>
    <div class="kpi-grid">
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Net sales (VAT-excl.)',
            'value' => Format::peso((string) $totals['net_sales']),
            'delta' => $viewLabels[$view] . ' total',
            'tone' => 'info',
            'period' => $report['label'],
            'points' => $salesPoints !== [] ? $salesPoints : [0, 0],
        ]) ?>
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Gross profit',
            'value' => Format::peso((string) $totals['gross_profit']),
            'delta' => $grossCents >= 0 ? 'On lines with a known cost' : 'Below cost',
            'tone' => $grossCents >= 0 ? 'ok' : 'danger',
            'period' => 'Cost ' . Format::peso((string) $totals['cost']),
            'points' => [0, 0],
        ]) ?>
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Units sold',
            'value' => Format::qty((string) $totals['units']),
            'delta' => $viewLabels[$view] . ' total',
            'tone' => 'neutral',
            'period' => $report['label'],
            'points' => [0, 0],
        ]) ?>
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Orders',
            'value' => (string) ((int) $totals['orders']),
            'delta' => 'POS sales and online orders',
            'tone' => 'neutral',
            'period' => $report['label'],
            'points' => [0, 0],
        ]) ?>
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Cost',
            'value' => Format::peso((string) $totals['cost']),
            'delta' => 'Cost known for ' . Format::pct($costComplete !== null ? (string) $costComplete : null) . ' of sales',
            'tone' => 'neutral',
            'period' => $report['label'],
            'points' => [0, 0],
        ]) ?>
    </div>
    <ul class="kpi-notes cell-meta" id="reports-notes">
        <li><?= $this->partial('partials/net-sales-rule') ?></li>
        <li><strong>Gross profit</strong> is the net sales of the lines that have a cost, minus that cost. Lines with no cost recorded are left out of gross profit, so it is never overstated. The Dashboard shows the same figures for Today, Month to date and Last 30 days.</li>
        <li><strong>Days</strong> run from midnight to midnight in Manila time (Asia/Manila), not UTC. Amounts are in pesos and exact to the centavo; rows in the breakdown are rounded one by one, so they can differ from the total by a centavo.</li>
    </ul>
</section>

<section class="kit-section" aria-labelledby="reports-chart-heading">
    <h2 class="kit-section__title" id="reports-chart-heading">Net sales and gross profit <?= $this->e(strtolower($breakdown['title'])) ?></h2>
<?php if (!$hasSales): ?>
    <?= $this->partial('partials/empty-state', [
        'title' => 'No sales in this period',
        'body' => 'Every figure is zero. Completed POS sales and orders that are not cancelled appear here once recorded.',
    ]) ?>
<?php else: ?>
    <?= $this->partial('partials/card', [
        'title' => $breakdown['title'],
        'subtitle' => $report['label'],
        'bodyHtml' => '<div class="chart-wrap"><canvas data-chart="' . ($breakdown['kind'] === 'day' ? 'line' : 'bar')
            . '" data-chart-labels="' . $this->e(implode('|', $labels))
            . '" data-chart-series="' . $this->e('Net sales (₱):' . implode(',', $chartSales) . ';Gross profit (₱):' . implode(',', $chartProfit))
            . '" aria-label="' . $this->e('Net sales and gross profit ' . strtolower($breakdown['title']) . ' for ' . $report['label'])
            . '" role="img"></canvas></div>',
    ]) ?>
<?php endif; ?>
</section>

<section class="kit-section" aria-labelledby="reports-table-heading">
    <h2 class="kit-section__title" id="reports-table-heading"><?= $this->e($breakdown['title']) ?></h2>
    <div class="table-wrap">
        <table class="data-table" id="reports-breakdown">
            <thead>
                <tr>
                    <th scope="col"><?= $this->e($breakdown['column']) ?></th>
                    <th scope="col" class="data-table__num">Net sales</th>
                    <th scope="col" class="data-table__num">Gross profit</th>
                    <th scope="col" class="data-table__num">Cost</th>
                    <th scope="col" class="data-table__num">Units</th>
                    <th scope="col" class="data-table__num">Orders</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($rows as $row): ?>
                <tr>
                    <th scope="row"><?= $this->e($row['label']) ?></th>
                    <td class="data-table__num"><?= $this->e(Format::peso((string) $row['net_sales'])) ?></td>
                    <td class="data-table__num"><?= $this->e(Format::peso((string) $row['gross_profit'])) ?></td>
                    <td class="data-table__num"><?= $this->e(Format::peso((string) $row['cost'])) ?></td>
                    <td class="data-table__num"><?= $this->e(Format::qty((string) $row['units'])) ?></td>
                    <td class="data-table__num"><?= $this->e((string) ((int) $row['orders'])) ?></td>
                </tr>
<?php endforeach; ?>
<?php if ($rows === []): ?>
                <tr><td colspan="6">Nothing to show for this period.</td></tr>
<?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row">Total</th>
                    <td class="data-table__num"><?= $this->e(Format::peso((string) $totals['net_sales'])) ?></td>
                    <td class="data-table__num"><?= $this->e(Format::peso((string) $totals['gross_profit'])) ?></td>
                    <td class="data-table__num"><?= $this->e(Format::peso((string) $totals['cost'])) ?></td>
                    <td class="data-table__num"><?= $this->e(Format::qty((string) $totals['units'])) ?></td>
                    <td class="data-table__num"><?= $this->e((string) ((int) $totals['orders'])) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>
<?php
$salesTabHtml = (string) ob_get_clean();
?>
<?php if ($report['extension_tabs'] === []): ?>
<?= $salesTabHtml ?>
<?php else: ?>
<section class="kit-section" aria-label="Report sections">
    <?= $this->partial('partials/tabs', [
        'id' => 'report-tabs',
        'label' => 'Report sections',
        'tabs' => array_merge(
            [['id' => 'sales', 'label' => 'Sales', 'contentHtml' => $salesTabHtml, 'active' => true]],
            $report['extension_tabs'],
        ),
    ]) ?>
</section>
<?php endif; ?>
<?php endif; ?>
