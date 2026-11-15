<?php
/**
 * Status badge partial. Tones: ok (green), warn (amber), danger (red), info (blue), neutral.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $label
 * @var string $tone
 * @var bool $dot
 */
$tone ??= 'neutral';
$dot ??= true;
$validTones = ['ok', 'warn', 'danger', 'info', 'neutral'];
$safeTone = in_array($tone, $validTones, true) ? $tone : 'neutral';
?>
<span class="badge badge--<?= $this->e($safeTone) ?>">
<?php if ($dot): ?>
    <span class="badge__dot" aria-hidden="true"></span>
<?php endif; ?>
    <?= $this->e($label) ?>
</span>
