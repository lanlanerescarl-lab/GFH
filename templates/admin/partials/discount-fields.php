<?php

declare(strict_types=1);

/**
 * The discount rule fields shared by the Add and Edit screens. On the edit screen the key is shown but disabled, so a
 * browser never posts it.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var bool $keyEditable
 * @var string $keyValue the stored key, shown when the key is not editable
 */
$keyEditable ??= false;
$keyValue ??= '';
?>
<div class="kit-grid">
<?php if ($keyEditable): ?>
    <?= $this->partial('partials/field', [
        'id' => 'discount-key',
        'name' => 'key',
        'label' => 'Key',
        'value' => $input['key'] ?? '',
        'required' => true,
        'placeholder' => 'senior_citizen',
        'help' => 'Lower-case letters, digits and underscores, starting with a letter, up to 40 characters. It cannot be changed later.',
        'error' => $errors['key'] ?? null,
    ]) ?>
<?php else: ?>
    <?= $this->partial('partials/field', [
        'id' => 'discount-key',
        'name' => 'key',
        'label' => 'Key',
        'value' => $keyValue,
        'disabled' => true,
        'help' => 'Set when the rule was created. Past sales point at it, so it cannot be changed.',
        'error' => $errors['key'] ?? null,
    ]) ?>
<?php endif; ?>
    <?= $this->partial('partials/field', [
        'id' => 'discount-label',
        'name' => 'label',
        'label' => 'Name shown to cashiers',
        'value' => $input['label'] ?? '',
        'required' => true,
        'error' => $errors['label'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'discount-percent',
        'name' => 'percent',
        'type' => 'number',
        'label' => 'Percent off',
        'value' => $input['percent'] ?? '',
        'required' => true,
        'min' => '0',
        'max' => '100',
        'step' => '0.01',
        'help' => 'From 0 to 100, with at most two decimals.',
        'error' => $errors['percent'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'discount-vat-exempt',
        'name' => 'vat_exempt',
        'type' => 'checkbox',
        'label' => 'VAT exempt',
        'value' => $input['vat_exempt'] ?? '',
        'options' => ['1' => 'Take the 12% VAT off first, then the percent (senior and PWD style)'],
        'error' => $errors['vat_exempt'] ?? null,
    ]) ?>
</div>
