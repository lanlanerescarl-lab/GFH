<?php

declare(strict_types=1);

/**
 * The order step fields shared by the Add and Edit screens. On the edit screen the key is shown but disabled, so a
 * browser never posts it.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var array<string, string> $mapsToLabels
 * @var array<string, string> $appliesWhenLabels
 * @var bool $keyEditable
 * @var string $keyValue the stored key, shown when the key is not editable
 */
$keyEditable ??= false;
$keyValue ??= '';
?>
<div class="kit-grid">
<?php if ($keyEditable): ?>
    <?= $this->partial('partials/field', [
        'id' => 'step-key',
        'name' => 'key',
        'label' => 'Key',
        'value' => $input['key'] ?? '',
        'required' => true,
        'placeholder' => 'out_for_delivery',
        'help' => 'Lower-case letters, digits and underscores, starting with a letter, up to 40 characters. It cannot be changed later.',
        'error' => $errors['key'] ?? null,
    ]) ?>
<?php else: ?>
    <?= $this->partial('partials/field', [
        'id' => 'step-key',
        'name' => 'key',
        'label' => 'Key',
        'value' => $keyValue,
        'disabled' => true,
        'help' => 'Set when the step was created. Orders and their history point at it, so it cannot be changed.',
        'error' => $errors['key'] ?? null,
    ]) ?>
<?php endif; ?>
    <?= $this->partial('partials/field', [
        'id' => 'step-label',
        'name' => 'label',
        'label' => 'Name',
        'value' => $input['label'] ?? '',
        'required' => true,
        'help' => 'You can always rename a step, even while orders are moving.',
        'error' => $errors['label'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'step-sort-order',
        'name' => 'sort_order',
        'type' => 'number',
        'label' => 'Position',
        'value' => $input['sort_order'] ?? '',
        'required' => true,
        'min' => '1',
        'max' => '9999',
        'step' => '1',
        'help' => 'Steps run from the lowest number to the highest. Leave gaps (10, 20, 30) so you can insert steps later.',
        'error' => $errors['sort_order'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'step-maps-to',
        'name' => 'maps_to',
        'type' => 'select',
        'label' => 'Counts as',
        'value' => $input['maps_to'] ?? '',
        'options' => $mapsToLabels,
        'required' => true,
        'help' => 'The group reports use. A step that counts as Complete is where stock is sold.',
        'error' => $errors['maps_to'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'step-applies-when',
        'name' => 'applies_when',
        'type' => 'select',
        'label' => 'Applies when',
        'value' => $input['applies_when'] ?? '',
        'options' => $appliesWhenLabels,
        'required' => true,
        'help' => 'Which orders pass through this step.',
        'error' => $errors['applies_when'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'step-is-terminal',
        'name' => 'is_terminal',
        'type' => 'switch',
        'label' => 'Last step',
        'checked' => ($input['is_terminal'] ?? '') === '1',
        'help' => 'An order that reaches a last step is finished and cannot move on.',
        'error' => $errors['is_terminal'] ?? null,
    ]) ?>
</div>
