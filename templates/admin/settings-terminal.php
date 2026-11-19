<?php

declare(strict_types=1);

/**
 * Admin Edit terminal screen (GET /{org}/admin/settings/terminals/{id}).
 * Only the name can be edited. The code, branch and last seen time are shown read-only, and the status changes only
 * through the Deactivate and Reactivate buttons below. A refused action shows its reason at the top.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, mixed> $terminal
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var string $terminalUrl
 * @var string $terminalsUrl
 * @var string $branchUrl
 * @var string $csrf
 */
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e((string) $terminal['code'] . ' · ' . (string) $terminal['name']);
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$isActive = (string) $terminal['status'] === 'active';
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings · Terminals',
    'title' => (string) $terminal['code'] . ' · ' . (string) $terminal['name'],
    'subtitle' => (string) $terminal['branch_name'],
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($terminalsUrl) . '">Back to terminals</a>',
]) ?>

<?php if (!empty($errors['action'])): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', ['tone' => 'danger', 'title' => 'Could not complete that action', 'message' => $errors['action']]) ?>
</div>
<?php endif; ?>

<section class="kit-section" aria-labelledby="terminal-details-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="terminal-details-heading">Terminal details</h2>
                <p class="card__subtitle">
                    Branch: <a class="table-link" href="<?= $this->e($branchUrl) ?>"><?= $this->e((string) $terminal['branch_name']) ?></a>
                    · Last seen: <?= $terminal['last_seen_at'] !== null ? $this->e(Format::dateTime((string) $terminal['last_seen_at'])) : 'Never' ?>
                </p>
            </div>
            <div>
                <?= $this->partial('partials/badge', ['label' => $isActive ? 'Active' : 'Inactive', 'tone' => $isActive ? 'ok' : 'neutral']) ?>
            </div>
        </div>
        <form method="post" action="<?= $this->e($terminalUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'terminal-code',
                    'name' => 'code',
                    'label' => 'Code',
                    'value' => (string) $terminal['code'],
                    'disabled' => true,
                    'help' => 'It starts every receipt number from this terminal, so it cannot be changed.',
                    'error' => $errors['code'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'terminal-branch-name',
                    'name' => 'branch_name',
                    'label' => 'Branch',
                    'value' => (string) $terminal['branch_name'],
                    'disabled' => true,
                    'help' => 'A terminal cannot move to another branch.',
                    'error' => $errors['branch_id'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'terminal-name',
                    'name' => 'name',
                    'label' => 'Name',
                    'value' => $input['name'] ?? '',
                    'required' => true,
                    'error' => $errors['name'] ?? null,
                ]) ?>
            </div>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Save changes', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>

<section class="kit-section" aria-labelledby="terminal-status-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="terminal-status-heading">Status</h2>
<?php if ($isActive): ?>
                <p class="card__subtitle">A terminal with an open shift cannot be deactivated. Nothing is deleted.</p>
<?php else: ?>
                <p class="card__subtitle">This terminal is inactive. It can be reactivated while its branch is active.</p>
<?php endif; ?>
            </div>
        </div>
        <div class="form-actions">
<?php if ($isActive): ?>
            <form method="post" action="<?= $this->e($terminalUrl . '/deactivate') ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <?= $this->partial('partials/button', ['label' => 'Deactivate', 'variant' => 'danger', 'type' => 'submit']) ?>
            </form>
<?php else: ?>
            <form method="post" action="<?= $this->e($terminalUrl . '/reactivate') ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <?= $this->partial('partials/button', ['label' => 'Reactivate', 'variant' => 'primary', 'type' => 'submit']) ?>
            </form>
<?php endif; ?>
        </div>
    </section>
</section>
