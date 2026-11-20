<?php

declare(strict_types=1);

/**
 * Admin Add discount rule screen (GET /{org}/admin/settings/discounts/new, posts to /{org}/admin/settings/discounts).
 * The key is chosen now and cannot be changed later. A new rule starts active.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var string $rulesUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Add discount rule');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings · Discount rules',
    'title' => 'Add discount rule',
    'subtitle' => 'The key is chosen now and cannot be changed later. A new rule starts active.',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($rulesUrl) . '">Back to discount rules</a>',
]) ?>

<section class="kit-section" aria-labelledby="discount-new-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="discount-new-heading">Rule details</h2>
            </div>
        </div>
        <form method="post" action="<?= $this->e($rulesUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <?= $this->partial('partials/discount-fields', ['input' => $input, 'errors' => $errors, 'keyEditable' => true]) ?>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Add discount rule', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>
