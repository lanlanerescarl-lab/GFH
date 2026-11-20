<?php

declare(strict_types=1);

/**
 * Admin Settings, Discount rules list (GET /{org}/admin/settings/discounts).
 * A plain table of every rule: the table is small, so there is no search or paging.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var list<array<string, mixed>> $rules
 * @var string $rulesUrl
 * @var string $newUrl
 * @var string $settingsUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Discount rules');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings',
    'title' => 'Discount rules',
    'subtitle' => 'Percent discounts the POS can apply. A rule is never deleted, because sales point at it: deactivate it instead. Editing a rule changes future sales only.',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($settingsUrl) . '">Back to settings</a>'
        . '<a class="button button--primary" href="' . $this->e($newUrl) . '">Add discount rule</a>',
]) ?>

<?php if ($rules === []): ?>
<?= $this->partial('partials/empty-state', [
    'title' => 'No discount rules yet',
    'body' => 'Add a rule such as a senior citizen or promo discount for the POS to offer.',
    'actionsHtml' => '<a class="button button--primary" href="' . $this->e($newUrl) . '">Add discount rule</a>',
]) ?>
<?php else: ?>
<section class="card">
    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Discount rules</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Key</th>
                    <th scope="col" class="data-table__num">Percent</th>
                    <th scope="col">VAT</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="data-table__num">Sales</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($rules as $rule): ?>
<?php
    $url = $rulesUrl . '/' . (string) $rule['id'];
    $isActive = (bool) $rule['active'];
?>
                <tr>
                    <td><a class="table-link" href="<?= $this->e($url) ?>"><?= $this->e((string) $rule['label']) ?></a></td>
                    <td><?= $this->e((string) $rule['key']) ?></td>
                    <td class="data-table__num"><?= $this->e((string) $rule['percent']) ?>%</td>
                    <td><?= (bool) $rule['vat_exempt'] ? 'Exempt' : 'Included' ?></td>
                    <td><?= $this->partial('partials/badge', ['label' => $isActive ? 'Active' : 'Inactive', 'tone' => $isActive ? 'ok' : 'neutral']) ?></td>
                    <td class="data-table__num"><?= $this->e((string) $rule['sales_count']) ?></td>
                    <td><a class="button button--ghost button--sm" href="<?= $this->e($url) ?>">Manage</a></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
