<?php
/**
 * Accessible Modal dialog partial (focus trap and Escape close via ui.js).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $id
 * @var string $title
 * @var string $bodyHtml pre-escaped body HTML
 * @var string $footerHtml optional pre-escaped footer HTML
 * @var bool $open
 */
$footerHtml ??= '';
$open ??= false;
?>
<div class="overlay-backdrop" id="<?= $this->e($id) ?>"<?= $open ? '' : ' hidden' ?>>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="<?= $this->e($id) ?>-title">
        <div class="modal__header">
            <h2 class="modal__title" id="<?= $this->e($id) ?>-title"><?= $this->e($title) ?></h2>
            <button class="button button--ghost button--sm" type="button" data-overlay-close aria-label="Close dialog">Close</button>
        </div>
        <div class="modal__body"><?= $bodyHtml ?></div>
<?php if ($footerHtml !== ''): ?>
        <div class="modal__footer"><?= $footerHtml ?></div>
<?php endif; ?>
    </div>
</div>
