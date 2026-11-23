<?php
/**
 * The signed-in customer's own orders, newest first, with a status filter and pages.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var list<array<string, mixed>> $orders
 * @var array<string, int> $pagination
 * @var list<array{key: string, label: string}> $statuses
 * @var string $status the chosen step key, or ""
 * @var string|null $notice
 * @var string $ordersUrl
 * @var string $orderBase
 * @var string $shopUrl the product listing
 */
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('My orders');
$this->end();

$page = (int) $pagination['page'];
$pages = (int) $pagination['pages'];
$href = static function (int $number) use ($ordersUrl, $status): string {
    $query = array_filter(['progress_key' => $status, 'page' => $number > 1 ? (string) $number : ''], static fn (string $value): bool => $value !== '');

    return $ordersUrl . ($query === [] ? '' : '?' . http_build_query($query));
};
?>
<section class="orders-page" aria-labelledby="orders-title">
    <h1 id="orders-title">My orders</h1>
<?php if ($notice !== null): ?>
    <p class="alert" role="status"><?= $this->e($notice) ?></p>
<?php endif; ?>

    <form class="orders-filter" method="get" action="<?= $this->e($ordersUrl) ?>">
        <div class="field">
            <label for="orders-status">Show orders that are</label>
            <select id="orders-status" name="progress_key">
                <option value="">Any status</option>
<?php foreach ($statuses as $option): ?>
                <option value="<?= $this->e($option['key']) ?>"<?= $option['key'] === $status ? ' selected' : '' ?>><?= $this->e($option['label']) ?></option>
<?php endforeach; ?>
            </select>
        </div>
        <button class="button button--secondary" type="submit">Show</button>
    </form>

<?php if ($orders === []): ?>
    <div class="empty-state">
        <h2 class="empty-state__title"><?= $status === '' ? 'You have not placed an order yet' : 'No orders with that status' ?></h2>
        <p><?= $status === '' ? 'When you place an order it will show up here, and you can follow it from step to step.' : 'Try another status, or show all of your orders.' ?></p>
        <a class="button" href="<?= $this->e($status === '' ? $shopUrl : $ordersUrl) ?>"><?= $status === '' ? 'Browse all products' : 'Show all orders' ?></a>
    </div>
<?php else: ?>
    <ul class="order-list">
<?php foreach ($orders as $order): ?>
        <li class="order-card">
            <p class="order-card__number"><a href="<?= $this->e($orderBase . $order['id']) ?>">Order <?= $this->e($order['order_number']) ?></a></p>
            <p class="order-card__meta">Placed <?= $this->e(Format::date($order['placed_at'])) ?> · <?= $order['fulfillment_method'] === 'delivery' ? 'Delivery' : 'Pickup' ?></p>
            <p class="order-card__status"><span class="badge"><?= $this->e($order['cancelled'] ? 'Cancelled' : $order['progress_label']) ?></span> <span class="badge badge--muted"><?= $this->e($order['payment_label']) ?></span></p>
            <p class="order-card__total"><?= $this->e(Format::peso($order['total'])) ?></p>
        </li>
<?php endforeach; ?>
    </ul>

<?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="Pages of orders">
        <ul class="pagination__list">
<?php if ($page > 1): ?>
            <li><a class="pagination__link" href="<?= $this->e($href($page - 1)) ?>" rel="prev">Previous<span class="visually-hidden"> page</span></a></li>
<?php endif; ?>
            <li><span class="pagination__link is-current" aria-current="page">Page <?= $page ?> of <?= $pages ?></span></li>
<?php if ($page < $pages): ?>
            <li><a class="pagination__link" href="<?= $this->e($href($page + 1)) ?>" rel="next">Next<span class="visually-hidden"> page</span></a></li>
<?php endif; ?>
        </ul>
    </nav>
<?php endif; ?>
<?php endif; ?>
</section>
