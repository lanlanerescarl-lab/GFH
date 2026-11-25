<?php
/**
 * The fields of a delivery address, shared by "Add an address" and "Edit address". The names are the JSON API's
 * (label, line1, barangay, city, province, postal_code), and the rules and messages come from the account service.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, string> $values
 * @var array<string, string> $errors field name => message
 */
?>
        <?= $this->partial('partials/field', ['name' => 'label', 'label' => 'Label (optional)', 'value' => $values['label'], 'help' => 'For example Home or Office.', 'error' => $errors['label'] ?? '']) ?>
        <?= $this->partial('partials/field', ['name' => 'line1', 'label' => 'Street address', 'value' => $values['line1'], 'autocomplete' => 'address-line1', 'required' => true, 'error' => $errors['line1'] ?? '']) ?>
        <?= $this->partial('partials/field', ['name' => 'barangay', 'label' => 'Barangay (optional)', 'value' => $values['barangay'], 'autocomplete' => 'address-level3', 'error' => $errors['barangay'] ?? '']) ?>
        <?= $this->partial('partials/field', ['name' => 'city', 'label' => 'City or municipality', 'value' => $values['city'], 'autocomplete' => 'address-level2', 'required' => true, 'error' => $errors['city'] ?? '']) ?>
        <?= $this->partial('partials/field', ['name' => 'province', 'label' => 'Province', 'value' => $values['province'], 'autocomplete' => 'address-level1', 'required' => true, 'error' => $errors['province'] ?? '']) ?>
        <?= $this->partial('partials/field', ['name' => 'postal_code', 'label' => 'Postal code (optional)', 'value' => $values['postal_code'], 'autocomplete' => 'postal-code', 'inputmode' => 'numeric', 'help' => '4 digits.', 'error' => $errors['postal_code'] ?? '']) ?>
