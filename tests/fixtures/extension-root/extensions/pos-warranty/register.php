<?php

declare(strict_types=1);

use Gfh\Core\Extension\ExtensionContext;

// Test-only fixture (Batch 8.3b). A warranty extension for real use reads the extension-owned product attribute
// ext_warranty_months (set on the product by the extension's own Admin tab, like care-notes does for ext_care_notes).
return function (ExtensionContext $x): void {
    $x->hook('pos.receipt.lines', static function (array $lines, array $context): array {
        // What the hook was given, for the test.
        $GLOBALS['gfh_fixture_receipt_context'] = $context;

        // A test may put exact lines in here to prove the server's limits; otherwise the lines come from the products.
        if (isset($GLOBALS['gfh_fixture_receipt_lines']) && is_array($GLOBALS['gfh_fixture_receipt_lines'])) {
            return [...$lines, ...$GLOBALS['gfh_fixture_receipt_lines']];
        }

        $any = false;
        foreach ($context['variants'] as $variant) {
            $months = $variant['attributes']['ext_warranty_months'] ?? null;
            if (is_int($months) && $months > 0) {
                $lines[] = ['variant_id' => $variant['variant_id'], 'text' => sprintf('Warranty: %d month%s', $months, $months === 1 ? '' : 's')];
                $any = true;
            }
        }
        if ($any) {
            $lines[] = ['text' => 'Keep this receipt for warranty claims.'];
        }

        return $lines;
    });
};
