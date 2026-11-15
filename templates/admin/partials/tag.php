<?php
/**
 * Pill tag partial with optional keyboard-accessible remove button.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $label
 * @var bool $removable
 */
$removable ??= false;
?>
<span class="tag">
    <span><?= $this->e($label) ?></span>
<?php if ($removable): ?>
    <button class="tag__remove" type="button" data-tag-remove aria-label="Remove <?= $this->e($label) ?>">×</button>
<?php endif; ?>
</span>
