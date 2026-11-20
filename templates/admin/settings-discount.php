<?php

declare(strict_types=1);

/**
 * Admin Edit discount rule screen (GET /{org}/admin/settings/discounts/{id}).
 * The name, percent and VAT-exempt box can be edited, and only future sales see the change. The key is shown
 * read-only, and the status changes only through the Deactivate and Reactivate buttons below.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, mixed> $rule
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var string $ruleUrl
 * @var string $rulesUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e((string) $rule['label']);
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$isActive = (bool) $rule['active'];
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings · Discount rules',
    'title' => (string) $rule['label'],
    'subtitle' => (string) $rule['key'],
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($rulesUrl) . '">Back to discount rules</a>',
]) ?>

<?php if (!empty($errors['action'])): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', ['tone' => 'danger', 'title' => 'Could not complete that action', 'message' => $errors['action']]) ?>
</div>
<?php endif; ?>

<section class="kit-section" aria-labelledby="discount-details-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="discount-details-heading">Rule details</h2>
                <p class="card__subtitle">
                    Used on <?= $this->e((string) $rule['sales_count']) ?> recorded sale(s). Changes apply to future sales only: a recorded sale keeps its own amounts.
                </p>
            </div>
            <div>
                <?= $this->partial('partials/badge', ['label' => $isActive ? 'Active' : 'Inactive', 'tone' => $isActive ? 'ok' : 'neutral']) ?>
            </div>
        </div>
        <form method="post" action="<?= $this->e($ruleUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <?= $this->partial('partials/discount-fields', ['input' => $input, 'errors' => $errors, 'keyEditable' => false, 'keyValue' => (string) $rule['key']]) ?>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Save changes', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>

<section class="kit-section" aria-labelledby="discount-status-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="discount-status-heading">Status</h2>
<?php if ($isActive): ?>
                <p class="card__subtitle">An active rule is offered by the POS. Deactivating it hides it from the POS; nothing is deleted.</p>
<?php else: ?>
                <p class="card__subtitle">This rule is inactive: the POS does not offer it and it cannot be applied to a sale. Reactivate it to use it again.</p>
<?php endif; ?>
            </div>
        </div>
        <div class="form-actions">
<?php if ($isActive): ?>
            <form method="post" action="<?= $this->e($ruleUrl . '/deactivate') ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <?= $this->partial('partials/button', ['label' => 'Deactivate', 'variant' => 'danger', 'type' => 'submit']) ?>
            </form>
<?php else: ?>
            <form method="post" action="<?= $this->e($ruleUrl . '/reactivate') ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <?= $this->partial('partials/button', ['label' => 'Reactivate', 'variant' => 'primary', 'type' => 'submit']) ?>
            </form>
<?php endif; ?>
        </div>
    </section>
</section>
