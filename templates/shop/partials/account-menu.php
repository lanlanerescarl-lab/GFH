<?php
/**
 * The account menu: Profile, Addresses, Password and My orders, then whatever extensions add through the account.menu
 * hook. Only links inside this site are printed (a path starting with a single "/"), so a hook cannot add an outside
 * or script link by mistake. The page the visitor is on carries aria-current="page" (said in words by the browser's
 * reader, not by colour alone).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var list<array{label: string, url: string, current?: bool}> $accountMenu
 */
$items = array_values(array_filter(
    $accountMenu,
    static fn (mixed $item): bool => is_array($item)
        && isset($item['label'], $item['url'])
        && is_string($item['label'])
        && is_string($item['url'])
        && trim($item['label']) !== ''
        && preg_match('#^/(?!/)[^\s\\\\]*$#D', $item['url']) === 1,
));
?>
<?php if ($items !== []): ?>
<nav class="account-menu" aria-label="Account">
    <ul>
<?php foreach ($items as $item): ?>
        <li><a href="<?= $this->e($item['url']) ?>"<?= !empty($item['current']) ? ' aria-current="page"' : '' ?>><?= $this->e($item['label']) ?></a></li>
<?php endforeach; ?>
    </ul>
</nav>
<?php endif; ?>
