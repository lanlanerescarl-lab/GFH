<?php

declare(strict_types=1);

/**
 * Admin Settings, Order steps list (GET /{org}/admin/settings/order-steps), in the order orders follow them.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var list<array<string, mixed>> $steps
 * @var int $openOrders
 * @var array<string, string> $mapsToLabels
 * @var array<string, string> $appliesWhenLabels
 * @var string $stepsUrl
 * @var string $newUrl
 * @var string $settingsUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Order steps');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings',
    'title' => 'Order steps',
    'subtitle' => 'The stages an order moves through. You can always rename a step. Adding, deleting or changing the position, group, last-step flag or condition of a step is only possible while no order is open.',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($settingsUrl) . '">Back to settings</a>'
        . '<a class="button button--primary" href="' . $this->e($newUrl) . '">Add step</a>',
]) ?>

<?php if ($openOrders > 0): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', [
        'tone' => 'info',
        'title' => 'Steps are locked for structural changes',
        'message' => $openOrders . ' order(s) are open (not cancelled and not finished). You can still rename steps.',
    ]) ?>
</div>
<?php endif; ?>

<?php if ($steps === []): ?>
<?= $this->partial('partials/empty-state', [
    'title' => 'No order steps yet',
    'body' => 'Orders cannot be placed without steps. Add a first step.',
    'actionsHtml' => '<a class="button button--primary" href="' . $this->e($newUrl) . '">Add step</a>',
]) ?>
<?php else: ?>
<section class="card">
    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Order steps in the order orders follow them</caption>
            <thead>
                <tr>
                    <th scope="col" class="data-table__num">Position</th>
                    <th scope="col">Name</th>
                    <th scope="col">Key</th>
                    <th scope="col">Counts as</th>
                    <th scope="col">Last step</th>
                    <th scope="col">Applies when</th>
                    <th scope="col" class="data-table__num">Orders</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($steps as $step): ?>
<?php $url = $stepsUrl . '/' . (string) $step['key']; ?>
                <tr>
                    <td class="data-table__num"><?= $this->e((string) $step['sort_order']) ?></td>
                    <td><a class="table-link" href="<?= $this->e($url) ?>"><?= $this->e((string) $step['label']) ?></a></td>
                    <td><?= $this->e((string) $step['key']) ?></td>
                    <td><?= $this->e($mapsToLabels[(string) $step['maps_to']] ?? (string) $step['maps_to']) ?></td>
                    <td><?= (bool) $step['is_terminal'] ? 'Yes' : 'No' ?></td>
                    <td><?= $this->e($appliesWhenLabels[(string) $step['applies_when']] ?? (string) $step['applies_when']) ?></td>
                    <td class="data-table__num"><?= $this->e((string) $step['order_count']) ?></td>
                    <td><a class="button button--ghost button--sm" href="<?= $this->e($url) ?>">Manage</a></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
