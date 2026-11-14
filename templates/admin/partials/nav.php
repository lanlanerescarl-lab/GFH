<?php
/**
 * Header navigation. Items come from the app (and, through the navigation hooks, from extensions).
 * Only links inside this site are shown (a path starting with a single "/"), so a hook cannot add an outside
 * or script link by mistake.
 *
 * @var \Gfh\Core\View\AppView $this
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
<nav class="shell-nav" id="shell-nav" aria-label="Main">
    <ul>
<?php foreach ($items as $item): ?>
        <li><a href="<?= $this->e($item['url']) ?>"<?= !empty($item['current']) ? ' aria-current="page"' : '' ?>><?= $this->e($item['label']) ?></a></li>
<?php endforeach; ?>
    </ul>
</nav>
<?php endif; ?>
