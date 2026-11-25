<?php

declare(strict_types=1);

use Gfh\Core\Extension\ExtensionContext;

// Test-only fixture (Batch 8.3). Never copy this into extensions/: it breaks on purpose.
return function (ExtensionContext $x): void {
    foreach (['navigation.admin', 'navigation.shop'] as $name) {
        $x->hook($name, static fn (array $items): array => [...$items, ['label' => 'Before', 'url' => '/fixture-before']], 5);
        $x->hook($name, static function (array $items): array {
            throw new \RuntimeException('fixture callback exploded');
        }, 10);
        $x->hook($name, static fn (array $items): array => [...$items, ['label' => 'After', 'url' => '/fixture-after']], 20);
    }

    // Wrong type: a string where the page expects a list of sections.
    $x->hook('product.detail.sections', static fn (array $sections): string => 'not a list', 10);
};
