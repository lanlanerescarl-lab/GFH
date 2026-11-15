<?php

declare(strict_types=1);

/**
 * Admin Product create & edit screen (GET /{org}/admin/products/new and /{org}/admin/products/{id}).
 * Registers details, variants, pricing & photos tabs plus any extension tabs via product.form.tabs.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var bool $isNew
 * @var array<string, mixed>|null $product
 * @var string $activeSectionTab
 * @var string $productsUrl
 * @var string $brandsUrl
 * @var string $categoriesUrl
 * @var string $adminBase
 * @var string $mediaBase
 * @var list<array<string, mixed>> $brands
 * @var list<array<string, mixed>> $categories
 * @var list<array<string, mixed>> $suppliers
 * @var array<string, mixed> $productInput
 * @var array<string, string> $errors
 * @var list<array{key: string, value: string, error: ?string}> $attributeRows
 * @var array<string, mixed> $variantInput
 * @var array<string, array<string, mixed>> $variantInputs
 * @var array<string, array<string, string>> $variantErrors
 * @var array<string, mixed> $newVariantInput
 * @var array<string, string> $newVariantErrors
 * @var array<string, array<string, mixed>> $newTierInputs
 * @var array<string, array<string, string>> $newTierErrors
 * @var array<string, array<string, mixed>> $tierInputs
 * @var array<string, array<string, string>> $tierErrors
 * @var list<array<string, mixed>> $priceHistory
 * @var array<string, string> $photoErrors
 * @var list<array{id: string, label: string, contentHtml: string, active?: bool}> $extensionTabs
 * @var bool $canEdit
 * @var string $csrf
 */
$pageTitle = $isNew ? 'New product' : (string) ($product['name'] ?? 'Product');

$this->layout('layout');
$this->start('title');
echo $this->e($pageTitle);
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$actionsHtml = '<a class="button button--secondary" href="' . $this->e($productsUrl) . '">Back to products</a>';
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Stock · Products',
    'title' => $pageTitle,
    'subtitle' => $isNew
        ? 'Create a draft or active product with attributes and an optional initial variant.'
        : 'Edit product details, variants, price tiers, price history and photos.',
    'actionsHtml' => $actionsHtml,
]) ?>

<?= $this->partial('partials/products-nav', [
    'productsUrl' => $productsUrl,
    'brandsUrl' => $brandsUrl,
    'categoriesUrl' => $categoriesUrl,
    'activeTab' => 'products',
]) ?>

<?php if ($isNew): ?>
    <?= $this->partial('partials/product-details-form', [
        'isNew' => true,
        'formAction' => $productsUrl,
        'productInput' => $productInput,
        'errors' => $errors,
        'brands' => $brands,
        'categories' => $categories,
        'suppliers' => $suppliers,
        'attributeRows' => $attributeRows,
        'variantInput' => $variantInput,
        'canEdit' => $canEdit,
        'csrf' => $csrf,
    ]) ?>
<?php else: ?>
<?php
    $productId = (string) $product['id'];
    $status = (string) ($product['status'] ?? 'draft');
    $statusTones = ['active' => 'ok', 'draft' => 'warn', 'discontinued' => 'neutral'];
    $variants = $product['variants'] ?? [];
    $images = $product['images'] ?? [];
?>
<section class="kit-section" aria-label="Product publication status">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">Catalog status</h2>
                <p class="card__subtitle">
                    Slug: <code><?= $this->e((string) $product['slug']) ?></code>
                    · Current status: <?= $this->partial('partials/badge', ['label' => ucfirst($status), 'tone' => $statusTones[$status] ?? 'neutral']) ?>
                </p>
            </div>
<?php if ($canEdit): ?>
            <form class="inline-action-form" method="post" action="<?= $this->e($productsUrl . '/' . $productId . '/status') ?>" novalidate>
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <label class="visually-hidden" for="product-status-change">Catalog status</label>
                <select id="product-status-change" name="status"<?= isset($errors['status']) ? ' aria-invalid="true"' : '' ?>>
<?php foreach (['draft' => 'Draft', 'active' => 'Active', 'discontinued' => 'Discontinued'] as $optVal => $optLabel): ?>
                    <option value="<?= $this->e($optVal) ?>"<?= $status === $optVal ? ' selected' : '' ?>><?= $this->e($optLabel) ?></option>
<?php endforeach; ?>
                </select>
                <button class="button button--secondary button--sm" type="submit">Update status</button>
            </form>
<?php endif; ?>
        </div>
<?php if (isset($errors['status'])): ?>
        <p class="field-error" role="alert"><?= $this->e($errors['status']) ?></p>
<?php endif; ?>
    </section>
</section>

<?php
    $tabs = [
        [
            'id' => 'details',
            'label' => 'Details & attributes',
            'active' => $activeSectionTab === 'details',
            'contentHtml' => $this->partial('partials/product-details-form', [
                'isNew' => false,
                'formAction' => $productsUrl . '/' . $productId,
                'productInput' => $productInput,
                'errors' => $errors,
                'brands' => $brands,
                'categories' => $categories,
                'suppliers' => $suppliers,
                'attributeRows' => $attributeRows,
                'variantInput' => [],
                'canEdit' => $canEdit,
                'csrf' => $csrf,
            ]),
        ],
        [
            'id' => 'variants',
            'label' => 'Variants (' . count($variants) . ')',
            'active' => $activeSectionTab === 'variants',
            'contentHtml' => $this->partial('partials/product-variants', [
                'product' => $product,
                'adminBase' => $adminBase,
                'variantInputs' => $variantInputs,
                'variantErrors' => $variantErrors,
                'newVariantInput' => $newVariantInput,
                'newVariantErrors' => $newVariantErrors,
                'canEdit' => $canEdit,
                'csrf' => $csrf,
            ]),
        ],
        [
            'id' => 'pricing',
            'label' => 'Price tiers & history',
            'active' => $activeSectionTab === 'pricing',
            'contentHtml' => $this->partial('partials/product-pricing', [
                'product' => $product,
                'priceHistory' => $priceHistory,
                'adminBase' => $adminBase,
                'newTierInputs' => $newTierInputs,
                'newTierErrors' => $newTierErrors,
                'tierInputs' => $tierInputs,
                'tierErrors' => $tierErrors,
                'canEdit' => $canEdit,
                'csrf' => $csrf,
            ]),
        ],
        [
            'id' => 'photos',
            'label' => 'Photos (' . count($images) . ')',
            'active' => $activeSectionTab === 'photos',
            'contentHtml' => $this->partial('partials/product-photos', [
                'product' => $product,
                'adminBase' => $adminBase,
                'mediaBase' => $mediaBase,
                'photoErrors' => $photoErrors,
                'canEdit' => $canEdit,
                'csrf' => $csrf,
            ]),
        ],
    ];
    foreach ($extensionTabs as $extTab) {
        $extTab['active'] = $activeSectionTab === $extTab['id'];
        $tabs[] = $extTab;
    }
?>
<section class="kit-section" aria-label="Product sections">
    <?= $this->partial('partials/tabs', [
        'id' => 'product-editor-tabs',
        'label' => 'Product sections',
        'tabs' => $tabs,
    ]) ?>
</section>
<?php endif; ?>
