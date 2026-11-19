<?php

declare(strict_types=1);

/**
 * Admin Add terminal screen (GET /{org}/admin/settings/terminals/new, posts to /{org}/admin/settings/terminals).
 * Only active branches are offered. The branch and the code are chosen now and cannot be changed later.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var array<string, string> $branchOptions branch id => label, active branches only
 * @var string $terminalsUrl
 * @var string $branchesUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Add terminal');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$choices = ['' => 'Choose a branch'] + $branchOptions;
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings · Terminals',
    'title' => 'Add terminal',
    'subtitle' => 'The branch and the code are chosen now and cannot be changed later. A new terminal starts active.',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($terminalsUrl) . '">Back to terminals</a>',
]) ?>

<?php if ($branchOptions === []): ?>
<?= $this->partial('partials/empty-state', [
    'title' => 'No active branch to put a terminal in',
    'body' => 'A terminal belongs to an active branch. Add or reactivate a branch first.',
    'actionsHtml' => '<a class="button button--primary" href="' . $this->e($branchesUrl) . '">Go to branches</a>',
]) ?>
<?php endif; ?>

<section class="kit-section" aria-labelledby="terminal-new-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="terminal-new-heading">Terminal details</h2>
            </div>
        </div>
        <form method="post" action="<?= $this->e($terminalsUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'terminal-branch',
                    'name' => 'branch_id',
                    'type' => 'select',
                    'label' => 'Branch',
                    'value' => $input['branch_id'] ?? '',
                    'options' => $choices,
                    'required' => true,
                    'error' => $errors['branch_id'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'terminal-code',
                    'name' => 'code',
                    'label' => 'Code',
                    'value' => $input['code'] ?? '',
                    'required' => true,
                    'placeholder' => 'T3',
                    'help' => '1 to 6 characters: capital letters and digits. It starts every receipt number from this terminal and cannot be changed later.',
                    'error' => $errors['code'] ?? null,
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
                <?= $this->partial('partials/button', ['label' => 'Add terminal', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>
