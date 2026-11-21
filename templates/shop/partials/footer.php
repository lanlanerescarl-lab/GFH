<?php
/**
 * Shop footer content. Fills the footer element of layout.php unless a view sets its own "footer" section.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $organizationName
 * @var string $productsUrl optional
 */
$productsUrl ??= '';
?>
<p class="shop-footer__name"><?= $this->e($organizationName) ?></p>
<?php if ($productsUrl !== ''): ?>
<p class="shop-footer__links"><a href="<?= $this->e($productsUrl) ?>">All products</a></p>
<?php endif; ?>
<p class="shop-footer__note">Prices are in Philippine pesos (₱).</p>
