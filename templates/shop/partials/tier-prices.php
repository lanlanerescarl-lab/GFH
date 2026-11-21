<?php
/**
 * Price tiers for a signed-in customer. The catalog service returns tiers only for a signed-in customer and only the
 * ones that customer qualifies for, so this partial is not printed at all for a guest. One block per variant; the
 * variant module shows the block of the chosen variant.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var list<array<string, mixed>> $variants
 * @var string $selected_variant_id
 */
use Gfh\Core\Support\Format;
?>
<div class="tier-prices">
<?php foreach ($variants as $variant): ?>
<?php if ($variant['tiers'] === []) { continue; } ?>
    <div class="tier-prices__block" data-variant-tiers="<?= $this->e($variant['id']) ?>"<?= (string) $variant['id'] === $selected_variant_id ? '' : ' hidden' ?>>
        <h2 class="tier-prices__title">Your prices</h2>
        <table class="table tier-prices__table">
            <caption class="visually-hidden">Your prices for <?= $this->e($variant['name']) ?></caption>
            <thead>
                <tr><th scope="col">Price</th><th scope="col">When you buy</th><th scope="col">Each</th></tr>
            </thead>
            <tbody>
<?php foreach ($variant['tiers'] as $tier): ?>
                <tr>
                    <th scope="row"><?= $this->e($tier['label'] !== null && $tier['label'] !== '' ? $tier['label'] : ($tier['basis'] === 'quantity' ? 'Volume price' : 'Member price')) ?></th>
                    <td><?= (float) $tier['min_qty'] > 0 ? $this->e(Format::qty((string) $tier['min_qty'])) . ' or more' : 'Any quantity' ?></td>
                    <td><?= $this->e(Format::peso((string) $tier['price'])) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>
</div>
