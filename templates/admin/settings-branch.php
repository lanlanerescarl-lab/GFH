<?php

declare(strict_types=1);

/**
 * Admin Edit branch screen (GET /{org}/admin/settings/branches/{id}).
 * Edits everything except the code (shown read-only) and the status, which changes only through the Deactivate and
 * Reactivate buttons below. A refused deactivation shows its reason at the top.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, mixed> $branch
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var list<string> $kinds
 * @var string $branchUrl
 * @var string $branchesUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e((string) $branch['name']);
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$isActive = (string) $branch['status'] === 'active';
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings · Branches',
    'title' => (string) $branch['name'],
    'subtitle' => (string) $branch['code'] . ' · ' . ucfirst((string) $branch['kind']),
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($branchesUrl) . '">Back to branches</a>',
]) ?>

<?php if (!empty($errors['action'])): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', ['tone' => 'danger', 'title' => 'Could not complete that action', 'message' => $errors['action']]) ?>
</div>
<?php endif; ?>

<section class="kit-section" aria-labelledby="branch-details-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="branch-details-heading">Branch details</h2>
            </div>
            <div>
                <?= $this->partial('partials/badge', ['label' => $isActive ? 'Active' : 'Inactive', 'tone' => $isActive ? 'ok' : 'neutral']) ?>
            </div>
        </div>
        <form method="post" action="<?= $this->e($branchUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <?= $this->partial('partials/branch-fields', ['input' => $input, 'errors' => $errors, 'kinds' => $kinds, 'codeEditable' => false, 'codeValue' => (string) $branch['code']]) ?>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Save changes', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>

<section class="kit-section" aria-labelledby="branch-status-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="branch-status-heading">Status</h2>
<?php if ($isActive): ?>
                <p class="card__subtitle">A branch can be deactivated when it is not the delivery branch, has no active terminal, holds no stock on hand and has no open order for pickup or shipping. Nothing is deleted.</p>
<?php else: ?>
                <p class="card__subtitle">This branch is inactive. Reactivating it is always allowed.</p>
<?php endif; ?>
            </div>
        </div>
        <div class="form-actions">
<?php if ($isActive): ?>
            <form method="post" action="<?= $this->e($branchUrl . '/deactivate') ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <?= $this->partial('partials/button', ['label' => 'Deactivate', 'variant' => 'danger', 'type' => 'submit']) ?>
            </form>
<?php else: ?>
            <form method="post" action="<?= $this->e($branchUrl . '/reactivate') ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <?= $this->partial('partials/button', ['label' => 'Reactivate', 'variant' => 'primary', 'type' => 'submit']) ?>
            </form>
<?php endif; ?>
        </div>
    </section>
</section>
