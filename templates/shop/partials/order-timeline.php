<?php
/**
 * An order's progress: its path as done, current and upcoming steps, with the date each step was reached (Asia/Manila).
 * State is said in words as well as shown, so it never depends on colour.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var list<array{key: string, label: string, state: string, reached_at: ?string}> $timeline
 */
use Gfh\Core\Support\Format;

$words = ['done' => 'Done', 'current' => 'Current step', 'upcoming' => 'Coming up'];
?>
<ol class="timeline">
<?php foreach ($timeline as $step): ?>
    <li class="timeline__step timeline__step--<?= $this->e($step['state']) ?>"<?= $step['state'] === 'current' ? ' aria-current="step"' : '' ?>>
        <span class="timeline__label"><?= $this->e($step['label']) ?></span>
        <span class="timeline__state"><?= $this->e($words[$step['state']] ?? '') ?><?= $step['reached_at'] !== null ? ' · ' . $this->e(Format::date($step['reached_at'])) : '' ?></span>
    </li>
<?php endforeach; ?>
</ol>
