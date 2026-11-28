<?php
/**
 * Super Admin sidebar navigation. Items are pre-filtered by SuperAdminNavigationService
 * (Modules::groupAllows and PermissionService::can). Only relative paths starting with a
 * single "/" are rendered.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var list<array{label: string, url: string, current?: bool}> $navigation
 */
$items = array_values(array_filter(
    $navigation,
    static fn (mixed $item): bool => is_array($item)
        && isset($item['label'], $item['url'])
        && is_string($item['label'])
        && is_string($item['url'])
        && preg_match('#^/(?!/)#', $item['url']) === 1,
));
?>
<?php if ($items !== []): ?>
<nav class="shell-nav admin-nav sa-nav" id="shell-nav" aria-label="Super Admin">
    <ul>
<?php foreach ($items as $item): ?>
        <li><a href="<?= $this->e($item['url']) ?>"<?= !empty($item['current']) ? ' aria-current="page"' : '' ?>><?= $this->e($item['label']) ?></a></li>
<?php endforeach; ?>
    </ul>
</nav>
<?php endif; ?>
