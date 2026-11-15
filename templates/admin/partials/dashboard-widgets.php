<?php
/**
 * Renders extension widgets registered via the dashboard.widgets hook.
 * Every field is escaped with $this->e(), and optional links are restricted to internal site paths.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var list<mixed> $widgets
 */
if ($widgets === []) {
    return;
}
$validTones = ['ok', 'warn', 'danger', 'info', 'neutral'];
?>
<section class="kit-section" aria-labelledby="dashboard-widgets-heading">
    <h2 class="kit-section__title" id="dashboard-widgets-heading">Extension widgets</h2>
    <div class="kit-grid">
<?php foreach ($widgets as $widget): ?>
<?php
    if (!is_array($widget)) {
        continue;
    }
    $title = isset($widget['title']) && is_string($widget['title']) ? $widget['title'] : 'Widget';
    $subtitle = isset($widget['subtitle']) && is_string($widget['subtitle']) ? $widget['subtitle'] : null;
    $value = isset($widget['value']) && (is_string($widget['value']) || is_int($widget['value']) || is_float($widget['value'])) ? (string) $widget['value'] : null;
    $body = isset($widget['body']) && is_string($widget['body']) ? $widget['body']
        : (isset($widget['description']) && is_string($widget['description']) ? $widget['description']
            : (isset($widget['meta']) && is_string($widget['meta']) ? $widget['meta'] : ''));
    $badge = isset($widget['badge']) && is_string($widget['badge']) ? $widget['badge'] : '';
    $tone = isset($widget['tone']) && is_string($widget['tone']) && in_array($widget['tone'], $validTones, true) ? $widget['tone'] : 'info';
    $rawUrl = $widget['url'] ?? ($widget['action_url'] ?? null);
    $url = is_string($rawUrl) && preg_match('#^/(?!/)[^\s\\\\]*$#D', $rawUrl) === 1 ? $rawUrl : null;
    $actionLabel = isset($widget['action_label']) && is_string($widget['action_label']) && trim($widget['action_label']) !== ''
        ? $widget['action_label'] : 'Open';
    $items = isset($widget['items']) && is_array($widget['items']) ? $widget['items'] : [];

    $inner = '';
    if ($value !== null && $value !== '') {
        $inner .= '<p class="kpi-card__value kit-stack">' . $this->e($value) . '</p>';
    }
    if ($body !== '') {
        $inner .= '<p>' . $this->e($body) . '</p>';
    }
    if ($items !== []) {
        $inner .= '<ul class="compact-list">';
        foreach ($items as $item) {
            if (is_string($item)) {
                $inner .= '<li>' . $this->e($item) . '</li>';
            } elseif (is_array($item) && isset($item['label'])) {
                $val = isset($item['value']) ? ': ' . (string) $item['value'] : '';
                $inner .= '<li>' . $this->e((string) $item['label'] . $val) . '</li>';
            }
        }
        $inner .= '</ul>';
    }
    $headerBadge = $badge !== '' ? $this->partial('partials/badge', ['label' => $badge, 'tone' => $tone, 'dot' => false]) : '';
    $footer = $url !== null ? '<a class="button button--secondary button--sm" href="' . $this->e($url) . '">' . $this->e($actionLabel) . '</a>' : '';
?>
        <?= $this->partial('partials/card', [
            'title' => $title,
            'subtitle' => $subtitle,
            'headerActionsHtml' => $headerBadge,
            'bodyHtml' => $inner,
            'footerHtml' => $footer,
        ]) ?>
<?php endforeach; ?>
    </div>
</section>
