<?php

declare(strict_types=1);

/**
 * Admin Settings, Terminals list (GET /{org}/admin/settings/terminals).
 * A plain table of every POS terminal: these tables are small, so there is no search or paging.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var list<array<string, mixed>> $terminals
 * @var string $terminalsUrl
 * @var string $newUrl
 * @var string $settingsUrl
 * @var string $csrf
 */
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Terminals');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings',
    'title' => 'Terminals',
    'subtitle' => 'POS terminals. A terminal is never deleted: deactivate it when it is no longer used.',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($settingsUrl) . '">Back to settings</a>'
        . '<a class="button button--primary" href="' . $this->e($newUrl) . '">Add terminal</a>',
]) ?>

<?php if ($terminals === []): ?>
<?= $this->partial('partials/empty-state', [
    'title' => 'No terminals yet',
    'body' => 'Add a terminal for each counter that will use the POS.',
    'actionsHtml' => '<a class="button button--primary" href="' . $this->e($newUrl) . '">Add terminal</a>',
]) ?>
<?php else: ?>
<section class="card">
    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Terminals</caption>
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Name</th>
                    <th scope="col">Branch</th>
                    <th scope="col">Status</th>
                    <th scope="col">Last seen</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($terminals as $terminal): ?>
<?php
    $url = $terminalsUrl . '/' . (string) $terminal['id'];
    $isActive = (string) $terminal['status'] === 'active';
?>
                <tr>
                    <td><a class="table-link" href="<?= $this->e($url) ?>"><?= $this->e((string) $terminal['code']) ?></a></td>
                    <td><?= $this->e((string) $terminal['name']) ?></td>
                    <td><?= $this->e((string) $terminal['branch_name']) ?></td>
                    <td><?= $this->partial('partials/badge', ['label' => $isActive ? 'Active' : 'Inactive', 'tone' => $isActive ? 'ok' : 'neutral']) ?></td>
                    <td><?= $terminal['last_seen_at'] !== null ? $this->e(Format::dateTime((string) $terminal['last_seen_at'])) : '<span class="cell-meta">Never</span>' ?></td>
                    <td><a class="button button--ghost button--sm" href="<?= $this->e($url) ?>">Manage</a></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
