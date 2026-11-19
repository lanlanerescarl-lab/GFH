<?php

declare(strict_types=1);

/**
 * Admin Add branch screen (GET /{org}/admin/settings/branches/new, posts to /{org}/admin/settings/branches).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var list<string> $kinds
 * @var string $branchesUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Add branch');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings · Branches',
    'title' => 'Add branch',
    'subtitle' => 'The code is chosen now and cannot be changed later. A new branch starts active.',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($branchesUrl) . '">Back to branches</a>',
]) ?>

<section class="kit-section" aria-labelledby="branch-new-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="branch-new-heading">Branch details</h2>
            </div>
        </div>
        <form method="post" action="<?= $this->e($branchesUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <?= $this->partial('partials/branch-fields', ['input' => $input, 'errors' => $errors, 'kinds' => $kinds, 'codeEditable' => true]) ?>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Add branch', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>
