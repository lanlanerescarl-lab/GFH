<?php
/**
 * Form field partial supporting text, number, date, select, checkbox, radio and switch inputs.
 * Every control has an explicit <label> and optional help or error message linked via aria-describedby.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $id
 * @var string $name
 * @var string $label
 * @var string $type 'text' | 'number' | 'date' | 'select' | 'checkbox' | 'radio' | 'switch'
 * @var string $value
 * @var string|null $placeholder
 * @var string|null $help
 * @var string|null $error
 * @var bool $required
 * @var bool $disabled
 * @var bool $checked
 * @var array<string, string> $options for select, checkbox or radio groups (value => label)
 * @var string|null $min
 * @var string|null $max
 * @var string|null $step
 */
$type ??= 'text';
$value ??= '';
$placeholder ??= null;
$help ??= null;
$error ??= null;
$required ??= false;
$disabled ??= false;
$checked ??= false;
$options ??= [];
$min ??= null;
$max ??= null;
$step ??= null;

$describedBy = [];
if ($help !== null && $help !== '') {
    $describedBy[] = $id . '-help';
}
if ($error !== null && $error !== '') {
    $describedBy[] = $id . '-error';
}
$ariaDescribedBy = $describedBy !== [] ? ' aria-describedby="' . $this->e(implode(' ', $describedBy)) . '"' : '';
$ariaInvalid = ($error !== null && $error !== '') ? ' aria-invalid="true"' : '';
?>
<div class="field">
<?php if ($type === 'switch'): ?>
    <div class="switch-row">
        <label class="field__label" id="<?= $this->e($id) ?>-label" for="<?= $this->e($id) ?>"><?= $this->e($label) ?></label>
        <input type="hidden" id="<?= $this->e($id) ?>-input" name="<?= $this->e($name) ?>" value="<?= $checked ? '1' : '0' ?>">
        <button
            class="switch"
            type="button"
            role="switch"
            id="<?= $this->e($id) ?>"
            aria-labelledby="<?= $this->e($id) ?>-label"
            aria-checked="<?= $checked ? 'true' : 'false' ?>"
            data-switch-input="<?= $this->e($id) ?>-input"<?= $disabled ? ' disabled' : '' ?><?= $ariaDescribedBy ?>
        >
            <span class="switch__thumb" aria-hidden="true"></span>
        </button>
    </div>
<?php elseif ($type === 'checkbox' || $type === 'radio'): ?>
    <fieldset class="choice-list"<?= $ariaDescribedBy ?>>
        <legend class="field__label"><?= $this->e($label) ?></legend>
<?php foreach ($options as $optValue => $optLabel): ?>
<?php $optId = $id . '-' . preg_replace('/[^a-z0-9_-]/i', '-', (string) $optValue); ?>
        <label class="choice" for="<?= $this->e($optId) ?>">
            <input
                type="<?= $this->e($type) ?>"
                id="<?= $this->e($optId) ?>"
                name="<?= $this->e($name) ?>"
                value="<?= $this->e((string) $optValue) ?>"<?= ((string) $optValue === (string) $value || ($checked && count($options) === 1)) ? ' checked' : '' ?><?= $disabled ? ' disabled' : '' ?>
            >
            <span><?= $this->e($optLabel) ?></span>
        </label>
<?php endforeach; ?>
    </fieldset>
<?php elseif ($type === 'select'): ?>
    <label class="field__label" for="<?= $this->e($id) ?>">
        <?= $this->e($label) ?><?php if ($required): ?><span class="field__required" aria-hidden="true">*</span><?php endif; ?>
    </label>
    <select
        id="<?= $this->e($id) ?>"
        name="<?= $this->e($name) ?>"<?= $required ? ' required' : '' ?><?= $disabled ? ' disabled' : '' ?><?= $ariaInvalid ?><?= $ariaDescribedBy ?>
    >
<?php foreach ($options as $optValue => $optLabel): ?>
        <option value="<?= $this->e((string) $optValue) ?>"<?= (string) $optValue === (string) $value ? ' selected' : '' ?>><?= $this->e($optLabel) ?></option>
<?php endforeach; ?>
    </select>
<?php else: ?>
    <label class="field__label" for="<?= $this->e($id) ?>">
        <?= $this->e($label) ?><?php if ($required): ?><span class="field__required" aria-hidden="true">*</span><?php endif; ?>
    </label>
    <input
        type="<?= $this->e($type) ?>"
        id="<?= $this->e($id) ?>"
        name="<?= $this->e($name) ?>"
        value="<?= $this->e($value) ?>"<?= $placeholder !== null ? ' placeholder="' . $this->e($placeholder) . '"' : '' ?><?= $min !== null ? ' min="' . $this->e($min) . '"' : '' ?><?= $max !== null ? ' max="' . $this->e($max) . '"' : '' ?><?= $step !== null ? ' step="' . $this->e($step) . '"' : '' ?><?= $required ? ' required' : '' ?><?= $disabled ? ' disabled' : '' ?><?= $ariaInvalid ?><?= $ariaDescribedBy ?>
    >
<?php endif; ?>
<?php if ($help !== null && $help !== ''): ?>
    <p class="help" id="<?= $this->e($id) ?>-help"><?= $this->e($help) ?></p>
<?php endif; ?>
<?php if ($error !== null && $error !== ''): ?>
    <p class="field-error" id="<?= $this->e($id) ?>-error" role="alert"><?= $this->e($error) ?></p>
<?php endif; ?>
</div>
