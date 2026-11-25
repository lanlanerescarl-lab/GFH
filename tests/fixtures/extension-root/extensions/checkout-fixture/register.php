<?php

declare(strict_types=1);

use Gfh\Core\Extension\ExtensionContext;

// Test-only fixture (Batch 8.3). The care-notes extension is not touched; this one lives under tests/fixtures only.
return function (ExtensionContext $x): void {
    $x->hook('checkout.fields', static fn (array $fields): array => [
        ...$fields,
        ['id' => 'gift_note', 'label' => 'Gift note', 'type' => 'textarea', 'required' => false, 'help' => 'Printed on a card.', 'max_length' => 20],
        ['id' => 'company', 'label' => 'Company', 'type' => 'text', 'required' => true],
        ['id' => 'slot', 'label' => 'Time slot', 'type' => 'select', 'options' => [['value' => 'am', 'label' => 'Morning'], ['value' => 'pm', 'label' => 'Afternoon']]],
        ['id' => 'gift_wrap', 'label' => 'Gift wrap', 'type' => 'checkbox'],
        // Bad definitions: each one is ignored and logged, and the good ones above still work.
        ['id' => 'Bad Id', 'label' => 'Bad id', 'type' => 'text'],
        ['id' => 'weird', 'label' => 'Weird type', 'type' => 'radio'],
        ['id' => 'no_options', 'label' => 'No options', 'type' => 'select', 'options' => []],
        ['id' => 'company', 'label' => 'Same id again', 'type' => 'text'],
        'junk',
    ]);

    // What the pricing hook receives is kept for the test, and a ticked "gift_wrap" adds a fee, so the price shows it.
    $x->hook('checkout.adjustments', static function (array $adjustments, array $context): array {
        $GLOBALS['gfh_fixture_checkout_seen'][] = $context['checkout'] ?? null;
        if (($context['checkout']['gift_wrap'] ?? false) === true) {
            $adjustments['assembly_fee'] = '30.00';
        }

        return $adjustments;
    });
};
