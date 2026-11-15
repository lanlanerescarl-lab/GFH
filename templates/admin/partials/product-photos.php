<?php

declare(strict_types=1);

/**
 * Product photos tab partial: multi-photo upload, primary toggle, reordering, and removal.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, mixed> $product
 * @var string $adminBase
 * @var string $mediaBase
 * @var array<string, string> $photoErrors
 * @var bool $canEdit
 * @var string $csrf
 */
$productId = (string) $product['id'];
$productName = (string) $product['name'];
$images = $product['images'] ?? [];
$variants = $product['variants'] ?? [];

$variantLabels = ['' => 'Whole product'];
foreach ($variants as $variant) {
    $variantLabels[(string) $variant['id']] = (string) $variant['sku'] . ' — ' . (string) $variant['name'];
}
?>
<div class="kit-stack">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">Product gallery</h2>
                <p class="card__subtitle">Upload several photos, pick the primary image shown in Shop and POS, and adjust display order.</p>
            </div>
        </div>
<?php if (isset($photoErrors['order'])): ?>
        <p class="field-error" role="alert"><?= $this->e($photoErrors['order']) ?></p>
<?php endif; ?>
<?php if ($images === []): ?>
        <?= $this->partial('partials/empty-state', [
            'title' => 'No product photos yet',
            'body' => 'Upload one or more JPEG, PNG or WebP images below. The first uploaded photo becomes the primary image.',
        ]) ?>
<?php else: ?>
        <div class="photo-grid">
<?php $totalImages = count($images); ?>
<?php foreach ($images as $index => $image): ?>
<?php
    $imgId = (string) $image['id'];
    $mediaId = (string) $image['media_id'];
    $alt = (string) ($image['alt_text'] ?? '');
    if ($alt === '') {
        $alt = $productName . ' photo ' . ($index + 1);
    }
    $isPrimary = (bool) $image['is_primary'];
    $vId = $image['variant_id'] !== null ? (string) $image['variant_id'] : '';
?>
            <article class="photo-card">
                <img class="photo-card__img" src="<?= $this->e($mediaBase . '/' . $mediaId . '?thumb=1') ?>" alt="<?= $this->e($alt) ?>">
                <div class="photo-card__meta">
                    <span>#<?= $index + 1 ?><?= $vId !== '' && isset($variantLabels[$vId]) ? ' · ' . $this->e($variantLabels[$vId]) : '' ?></span>
<?php if ($isPrimary): ?>
                    <?= $this->partial('partials/badge', ['label' => 'Primary', 'tone' => 'ok']) ?>
<?php endif; ?>
                </div>
<?php if ($canEdit): ?>
                <div class="photo-card__actions">
<?php if (!$isPrimary): ?>
                    <form method="post" action="<?= $this->e($adminBase . '/images/' . $imgId . '/primary') ?>">
                        <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                        <input type="hidden" name="product_id" value="<?= $this->e($productId) ?>">
                        <button class="button button--secondary button--sm" type="submit">Set primary</button>
                    </form>
<?php endif; ?>
<?php if ($index > 0): ?>
                    <form method="post" action="<?= $this->e($adminBase . '/products/' . $productId . '/photos/order') ?>">
                        <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                        <input type="hidden" name="move_id" value="<?= $this->e($imgId) ?>">
                        <input type="hidden" name="direction" value="up">
                        <button class="button button--ghost button--sm" type="submit" aria-label="Move photo <?= $index + 1 ?> earlier">Move left</button>
                    </form>
<?php endif; ?>
<?php if ($index + 1 < $totalImages): ?>
                    <form method="post" action="<?= $this->e($adminBase . '/products/' . $productId . '/photos/order') ?>">
                        <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                        <input type="hidden" name="move_id" value="<?= $this->e($imgId) ?>">
                        <input type="hidden" name="direction" value="down">
                        <button class="button button--ghost button--sm" type="submit" aria-label="Move photo <?= $index + 1 ?> later">Move right</button>
                    </form>
<?php endif; ?>
                    <form method="post" action="<?= $this->e($adminBase . '/images/' . $imgId . '/delete') ?>">
                        <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                        <input type="hidden" name="product_id" value="<?= $this->e($productId) ?>">
                        <button class="button button--danger button--sm" type="submit">Remove</button>
                    </form>
                </div>
<?php endif; ?>
            </article>
<?php endforeach; ?>
        </div>

<?php if ($canEdit && $totalImages > 1): ?>
        <form class="inline-actions" method="post" action="<?= $this->e($adminBase . '/products/' . $productId . '/photos/order') ?>">
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
<?php foreach ($images as $index => $image): ?>
            <label class="field__label" for="photo-order-<?= $this->e((string) $image['id']) ?>">
                Photo #<?= $index + 1 ?> pos
                <input
                    class="photo-order-input"
                    id="photo-order-<?= $this->e((string) $image['id']) ?>"
                    type="number"
                    min="1"
                    name="order[<?= $this->e((string) $image['id']) ?>]"
                    value="<?= $index + 1 ?>"
                >
            </label>
<?php endforeach; ?>
            <button class="button button--secondary button--sm" type="submit">Save order</button>
        </form>
<?php endif; ?>
<?php endif; ?>
    </section>

<?php if ($canEdit): ?>
    <section class="card" aria-labelledby="upload-photos-heading">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="upload-photos-heading">Upload photos</h2>
                <p class="card__subtitle">Select one or more JPEG, PNG or WebP files (up to 5 MB each). EXIF metadata is stripped automatically.</p>
            </div>
        </div>
        <form method="post" enctype="multipart/form-data" action="<?= $this->e($adminBase . '/products/' . $productId . '/photos') ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <div class="kit-grid">
                <div class="field">
                    <label class="field__label" for="product-photo-files">Image files <span aria-hidden="true">*</span></label>
                    <input
                        id="product-photo-files"
                        type="file"
                        name="files[]"
                        multiple
                        required
                        accept="image/jpeg,image/png,image/webp"<?= isset($photoErrors['files']) ? ' aria-invalid="true" aria-describedby="product-photo-files-error"' : '' ?>
                    >
<?php if (isset($photoErrors['files'])): ?>
                    <p class="field-error" id="product-photo-files-error" role="alert"><?= $this->e($photoErrors['files']) ?></p>
<?php endif; ?>
                </div>
                <?= $this->partial('partials/field', [
                    'id' => 'photo-variant',
                    'name' => 'variant_id',
                    'label' => 'Apply to variant (optional)',
                    'type' => 'select',
                    'value' => '',
                    'options' => $variantLabels,
                    'error' => $photoErrors['variant_id'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'photo-alt',
                    'name' => 'alt_text',
                    'label' => 'Alt text (optional)',
                    'value' => '',
                    'placeholder' => 'Describe the photo for screen readers',
                ]) ?>
            </div>
            <label class="field__checkbox" style="margin-top: var(--space-3);">
                <input type="checkbox" name="primary" value="1">
                <span>Make the first uploaded photo primary</span>
            </label>
            <div class="card__footer">
                <?= $this->partial('partials/button', ['label' => 'Upload photos', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
<?php endif; ?>
</div>
