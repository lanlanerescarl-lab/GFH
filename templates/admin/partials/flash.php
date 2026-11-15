<?php
/**
 * Inline session flash banner rendered through the shared toast partial.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array{tone?: string, title?: string, message?: string}|null $flash
 */
if (!is_array($flash) || empty($flash['title'])) {
    return;
}
?>
<div class="toast-region toast-region--inline kit-stack" role="region" aria-label="Notification">
    <?= $this->partial('partials/toast', [
        'tone' => (string) ($flash['tone'] ?? 'info'),
        'title' => (string) $flash['title'],
        'message' => (string) ($flash['message'] ?? ''),
    ]) ?>
</div>
