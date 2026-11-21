<?php
/**
 * Product photos: the primary photo first, then the others. Every photo is visible without JavaScript (stacked, with
 * thumbnail links that jump to them); the gallery module shows one at a time and makes the thumbnails keyboard
 * operable. A product with no photo shows the inline placeholder.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $mediaUrl
 * @var array<string, mixed> $product
 */
$images = $product['images'];
usort($images, static fn (array $a, array $b): int => [!$a['is_primary'], (int) $a['sort_order']] <=> [!$b['is_primary'], (int) $b['sort_order']]);
$count = count($images);
?>
<section class="gallery" aria-label="Product photos" data-gallery>
<?php if ($count === 0): ?>
    <div class="gallery__stage">
        <div class="media-frame gallery__frame">
            <?= $this->partial('partials/placeholder') ?>
        </div>
        <p class="visually-hidden">This product has no photo yet.</p>
    </div>
<?php else: ?>
    <ul class="gallery__slides">
<?php foreach ($images as $index => $image): ?>
        <li class="gallery__slide" id="photo-<?= $index + 1 ?>" data-gallery-slide>
            <div class="media-frame gallery__frame">
                <img src="<?= $this->e($mediaUrl . '/' . $image['media_id']) ?>" width="800" height="1000" alt="<?= $this->e($product['name']) ?>"<?= $index === 0 ? ' fetchpriority="high"' : ' loading="lazy"' ?> decoding="async">
            </div>
        </li>
<?php endforeach; ?>
    </ul>
<?php if ($count > 1): ?>
    <p class="visually-hidden" role="status" aria-live="polite" data-gallery-status></p>
    <ul class="gallery__thumbs" aria-label="Photo thumbnails">
<?php foreach ($images as $index => $image): ?>
        <li>
            <a class="gallery__thumb" href="#photo-<?= $index + 1 ?>" aria-label="<?= $this->e('Show photo ' . ($index + 1) . ' of ' . $count) ?>" data-gallery-thumb>
                <img src="<?= $this->e($mediaUrl . '/' . $image['media_id'] . '?thumb=1') ?>" width="400" height="500" alt="<?= $this->e($product['name']) ?>" loading="lazy" decoding="async">
            </a>
        </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php endif; ?>
</section>
