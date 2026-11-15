<?php
/**
 * Order / fulfilment status pipeline partial.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $label
 * @var list<array{label: string, meta?: string, state?: string}> $steps
 */
$label ??= 'Order progress';
?>
<ol class="pipeline" aria-label="<?= $this->e($label) ?>">
<?php foreach (array_values($steps) as $index => $step): ?>
<?php
    $state = $step['state'] ?? 'upcoming';
    $valid = ['done', 'current', 'upcoming', 'cancelled'];
    $safeState = in_array($state, $valid, true) ? $state : 'upcoming';
    $marker = match ($safeState) {
        'done' => '✓',
        'cancelled' => '!',
        default => (string) ($index + 1),
    };
?>
    <li class="pipeline__step pipeline__step--<?= $this->e($safeState) ?>"<?= $safeState === 'current' ? ' aria-current="step"' : '' ?>>
        <span class="pipeline__marker" aria-hidden="true"><?= $this->e($marker) ?></span>
        <div>
            <span class="pipeline__label"><?= $this->e($step['label']) ?></span>
<?php if (!empty($step['meta'])): ?>
            <span class="pipeline__meta"><?= $this->e($step['meta']) ?></span>
<?php endif; ?>
        </div>
    </li>
<?php endforeach; ?>
</ol>
