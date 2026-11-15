<?php
/**
 * Toast notification partial. Tones: ok, warn, danger, info.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $title
 * @var string $message
 * @var string $tone
 */
$tone ??= 'info';
$message ??= '';
$valid = ['ok', 'warn', 'danger', 'info'];
$safeTone = in_array($tone, $valid, true) ? $tone : 'info';
?>
<div class="toast toast--<?= $this->e($safeTone) ?>" role="<?= $safeTone === 'danger' ? 'alert' : 'status' ?>">
    <div>
        <p class="toast__title"><?= $this->e($title) ?></p>
<?php if ($message !== ''): ?>
        <p class="toast__message"><?= $this->e($message) ?></p>
<?php endif; ?>
    </div>
    <button class="button button--ghost button--sm" type="button" data-toast-close aria-label="Dismiss notice">Dismiss</button>
</div>
