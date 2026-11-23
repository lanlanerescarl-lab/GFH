<?php
/**
 * One labelled form field with its inline error. The error is tied to the field with aria-describedby and the field
 * is marked aria-invalid when there is one. Organizations can override this partial like any other.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $name the field name; the id is "field-{name}"
 * @var string $label
 * @var string $type text, email, password, tel
 * @var string $value
 * @var string $error the message, or "" when the field is fine
 * @var string $autocomplete
 * @var bool $required
 * @var string $help optional hint under the field
 */
$type ??= 'text';
$value ??= '';
$error ??= '';
$autocomplete ??= '';
$required ??= false;
$help ??= '';
$id = 'field-' . $name;
$describedBy = trim(($help !== '' ? $id . '-help ' : '') . ($error !== '' ? $id . '-error' : ''));
?>
<div class="field">
    <label for="<?= $this->e($id) ?>"><?= $this->e($label) ?><?= $required ? ' (required)' : '' ?></label>
    <input id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" type="<?= $this->e($type) ?>" value="<?= $this->e($value) ?>"<?= $autocomplete !== '' ? ' autocomplete="' . $this->e($autocomplete) . '"' : '' ?><?= $required ? ' aria-required="true"' : '' ?><?= $error !== '' ? ' aria-invalid="true"' : '' ?><?= $describedBy !== '' ? ' aria-describedby="' . $this->e($describedBy) . '"' : '' ?>>
<?php if ($help !== ''): ?>
    <p class="help" id="<?= $this->e($id) ?>-help"><?= $this->e($help) ?></p>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <p class="field-error" id="<?= $this->e($id) ?>-error"><?= $this->e($error) ?></p>
<?php endif; ?>
</div>
