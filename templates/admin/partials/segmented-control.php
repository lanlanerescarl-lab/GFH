<?php
/**
 * Keyboard-operable segmented control partial (arrow keys + roving tabindex via ui.js).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $label
 * @var list<array{value: string, label: string, active?: bool}> $options
 */
?>
<div class="segmented" role="group" aria-label="<?= $this->e($label) ?>" data-segmented>
<?php foreach (array_values($options) as $index => $option): ?>
<?php $isActive = !empty($option['active']) || ($index === 0 && !array_filter($options, static fn (array $o): bool => !empty($o['active']))); ?>
    <button
        class="segmented__option"
        type="button"
        data-value="<?= $this->e($option['value']) ?>"
        aria-pressed="<?= $isActive ? 'true' : 'false' ?>"
        tabindex="<?= $isActive ? '0' : '-1' ?>"
    ><?= $this->e($option['label']) ?></button>
<?php endforeach; ?>
</div>
