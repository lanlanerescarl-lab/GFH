<?php
/**
 * Status badge partial (dot + text, color is never the only signal).
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var string $label
 * @var string $tone 'ok' | 'warn' | 'danger' | 'info' | 'neutral'
 */
$tone ??= 'neutral';
$allowed = ['ok', 'warn', 'danger', 'info', 'neutral'];
if (!in_array($tone, $allowed, true)) {
    $tone = 'neutral';
}
?>
<span class="badge badge--<?= $this->e($tone) ?>"><span class="badge__dot" aria-hidden="true"></span><?= $this->e($label) ?></span>
