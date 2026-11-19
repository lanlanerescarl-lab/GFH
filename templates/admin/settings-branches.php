<?php

declare(strict_types=1);

/**
 * Admin Settings, Branches list (GET /{org}/admin/settings/branches).
 * A plain table of every branch: these tables are small, so there is no search or paging.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var list<array<string, mixed>> $branches
 * @var string $branchesUrl
 * @var string $newUrl
 * @var string $settingsUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Branches');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings',
    'title' => 'Branches',
    'subtitle' => 'Stores and warehouses. A branch is never deleted: deactivate it when it is no longer used.',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($settingsUrl) . '">Back to settings</a>'
        . '<a class="button button--primary" href="' . $this->e($newUrl) . '">Add branch</a>',
]) ?>

<?php if ($branches === []): ?>
<?= $this->partial('partials/empty-state', [
    'title' => 'No branches yet',
    'body' => 'Add the first branch to start receiving stock and taking orders.',
    'actionsHtml' => '<a class="button button--primary" href="' . $this->e($newUrl) . '">Add branch</a>',
]) ?>
<?php else: ?>
<section class="card">
    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Branches</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Code</th>
                    <th scope="col">Kind</th>
                    <th scope="col">Flags</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="data-table__num">Active terminals</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($branches as $branch): ?>
<?php
    $url = $branchesUrl . '/' . (string) $branch['id'];
    $isActive = (string) $branch['status'] === 'active';
    $flags = '';
    if ((bool) $branch['is_pickup_point']) {
        $flags .= $this->partial('partials/badge', ['label' => 'Pickup point', 'tone' => 'info', 'dot' => false]);
    }
    if ((bool) $branch['is_delivery_origin']) {
        $flags .= $this->partial('partials/badge', ['label' => 'Delivery branch', 'tone' => 'info', 'dot' => false]);
    }
?>
                <tr>
                    <td><a class="table-link" href="<?= $this->e($url) ?>"><?= $this->e((string) $branch['name']) ?></a></td>
                    <td><?= $this->e((string) $branch['code']) ?></td>
                    <td><?= $this->e(ucfirst((string) $branch['kind'])) ?></td>
                    <td><?= $flags !== '' ? $flags : '<span class="cell-meta">None</span>' ?></td>
                    <td><?= $this->partial('partials/badge', ['label' => $isActive ? 'Active' : 'Inactive', 'tone' => $isActive ? 'ok' : 'neutral']) ?></td>
                    <td class="data-table__num"><?= $this->e((string) $branch['active_terminals']) ?></td>
                    <td><a class="button button--ghost button--sm" href="<?= $this->e($url) ?>">Manage</a></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
