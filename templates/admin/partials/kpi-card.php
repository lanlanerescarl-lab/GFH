<?php
/**
 * KPI card with sparkline. Key number uses --color-heading (gfh-800).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $label
 * @var string $value
 * @var string $delta
 * @var string $tone 'ok' | 'warn' | 'danger' | 'info' | 'neutral'
 * @var string $period
 * @var list<int|float> $points sparkline points
 */
$tone ??= 'ok';
$delta ??= '';
$period ??= 'vs last 7 days';
$points ??= [12, 16, 14, 21, 19, 25, 28];

$count = count($points);
$min = $count > 0 ? (float) min($points) : 0.0;
$max = $count > 0 ? (float) max($points) : 1.0;
$range = max(1.0, $max - $min);
$coords = [];
foreach (array_values($points) as $idx => $pt) {
    $x = $count > 1 ? round(($idx / ($count - 1)) * 120, 1) : 60.0;
    $y = round(34 - (((float) $pt - $min) / $range) * 28, 1);
    $coords[] = $x . ',' . $y;
}
$polyline = implode(' ', $coords);
?>
<article class="kpi-card">
    <div class="kpi-card__header">
        <p class="kpi-card__label"><?= $this->e($label) ?></p>
<?php if ($delta !== ''): ?>
        <?= $this->partial('partials/badge', ['label' => $delta, 'tone' => $tone, 'dot' => false]) ?>
<?php endif; ?>
    </div>
    <p class="kpi-card__value"><?= $this->e($value) ?></p>
    <div class="kpi-card__sparkline" aria-hidden="true">
        <svg viewBox="0 0 120 40" preserveAspectRatio="none" focusable="false">
            <polyline fill="none" stroke="var(--chart-1)" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" points="<?= $this->e($polyline) ?>"></polyline>
        </svg>
    </div>
    <div class="kpi-card__meta">
        <span><?= $this->e($period) ?></span>
    </div>
</article>
