<?php
/**
 * The Shop's own 404 page, inside the Shop layout.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $productsUrl
 * @var string $message
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Not found');
$this->end();
?>
<section class="empty-state empty-state--page" aria-labelledby="shop-notfound-title">
    <h1 class="empty-state__title" id="shop-notfound-title">We could not find that</h1>
    <p><?= $this->e($message) ?></p>
    <a class="button" href="<?= $this->e($productsUrl) ?>">Browse all products</a>
</section>
