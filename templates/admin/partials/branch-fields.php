<?php

declare(strict_types=1);

/**
 * The branch form fields shared by the Add branch and Edit branch screens. On the edit screen the code is shown but
 * disabled, so a browser never posts it.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var list<string> $kinds
 * @var bool $codeEditable
 * @var string $codeValue the stored code, shown when the code is not editable
 */
$codeEditable ??= false;
$codeValue ??= '';

$kindOptions = [];
foreach ($kinds as $kind) {
    $kindOptions[$kind] = ucfirst($kind);
}
?>
<div class="kit-grid">
<?php if ($codeEditable): ?>
    <?= $this->partial('partials/field', [
        'id' => 'branch-code',
        'name' => 'code',
        'label' => 'Code',
        'value' => $input['code'] ?? '',
        'required' => true,
        'placeholder' => 'BCD-MAIN',
        'help' => '2 to 12 characters: capital letters, digits or hyphens. It cannot be changed later.',
        'error' => $errors['code'] ?? null,
    ]) ?>
<?php else: ?>
    <?= $this->partial('partials/field', [
        'id' => 'branch-code',
        'name' => 'code',
        'label' => 'Code',
        'value' => $codeValue,
        'disabled' => true,
        'help' => 'Set when the branch was created. It cannot be changed.',
        'error' => $errors['code'] ?? null,
    ]) ?>
<?php endif; ?>
    <?= $this->partial('partials/field', [
        'id' => 'branch-name',
        'name' => 'name',
        'label' => 'Name',
        'value' => $input['name'] ?? '',
        'required' => true,
        'error' => $errors['name'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'branch-kind',
        'name' => 'kind',
        'type' => 'select',
        'label' => 'Kind',
        'value' => $input['kind'] ?? 'store',
        'options' => $kindOptions,
        'required' => true,
        'error' => $errors['kind'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'branch-address',
        'name' => 'address',
        'label' => 'Address',
        'value' => $input['address'] ?? '',
        'error' => $errors['address'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'branch-city',
        'name' => 'city',
        'label' => 'City',
        'value' => $input['city'] ?? '',
        'error' => $errors['city'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'branch-province',
        'name' => 'province',
        'label' => 'Province',
        'value' => $input['province'] ?? '',
        'error' => $errors['province'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'branch-region',
        'name' => 'region',
        'label' => 'Region',
        'value' => $input['region'] ?? '',
        'error' => $errors['region'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'branch-pickup',
        'name' => 'is_pickup_point',
        'type' => 'checkbox',
        'label' => 'Pickup point',
        'value' => $input['is_pickup_point'] ?? '',
        'options' => ['1' => 'Customers can collect online orders here'],
        'help' => 'Only an active branch can be a pickup point.',
        'error' => $errors['is_pickup_point'] ?? null,
    ]) ?>
    <?= $this->partial('partials/field', [
        'id' => 'branch-delivery',
        'name' => 'is_delivery_origin',
        'type' => 'checkbox',
        'label' => 'Delivery branch',
        'value' => $input['is_delivery_origin'] ?? '',
        'options' => ['1' => 'Online orders for delivery ship from here'],
        'help' => 'Only one branch ships online orders. Ticking this moves it here from the current delivery branch.',
        'error' => $errors['is_delivery_origin'] ?? null,
    ]) ?>
</div>
