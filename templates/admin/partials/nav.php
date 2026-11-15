<?php
/**
 * Admin sidebar navigation. Items arrive already filtered by AdminNavigationService (PermissionService::can() and
 * the navigation.admin hook). This partial still shows only links inside this site (a path starting with a single
 * "/"), so an override or hook can never print an outside or script link by mistake.
 * Items with the same "group" are listed together under that heading, in the order the group first appears.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var list<array{label: string, url: string, current?: bool, group?: string}> $navigation
 */
$items = array_values(array_filter(
    $navigation,
    static fn (mixed $item): bool => is_array($item)
        && isset($item['label'], $item['url'])
        && is_string($item['label'])
        && is_string($item['url'])
        && preg_match('#^/(?!/)#', $item['url']) === 1,
));

$groups = [];
foreach ($items as $item) {
    $group = isset($item['group']) && is_string($item['group']) ? $item['group'] : '';
    $groups[$group][] = $item;
}
?>
<?php if ($items !== []): ?>
<nav class="shell-nav admin-nav" id="shell-nav" aria-label="Main">
<?php foreach ($groups as $group => $groupItems): ?>
<?php if ($group !== ''): ?>
    <p class="admin-nav__group" id="nav-group-<?= $this->e(md5((string) $group)) ?>"><?= $this->e($group) ?></p>
<?php endif; ?>
    <ul<?= $group !== '' ? ' aria-labelledby="nav-group-' . $this->e(md5((string) $group)) . '"' : '' ?>>
<?php foreach ($groupItems as $item): ?>
        <li><a href="<?= $this->e($item['url']) ?>"<?= !empty($item['current']) ? ' aria-current="page"' : '' ?>><?= $this->e($item['label']) ?></a></li>
<?php endforeach; ?>
    </ul>
<?php endforeach; ?>
</nav>
<?php endif; ?>
