<?php
/**
 * Admin Brands screen (GET /{org}/admin/brands).
 * Edit permission allows create, rename and logo upload; delete permission allows archive and restore.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} $result
 * @var \Gfh\Core\Support\ListQuery $query
 * @var array<string, string> $createInput
 * @var array<string, string> $createErrors
 * @var array<string, array<string, string>> $rowInputs
 * @var array<string, string> $rowErrors
 * @var array<string, string> $logoErrors
 * @var bool $canEdit
 * @var bool $canDelete
 * @var string $activeTab
 * @var string $productsUrl
 * @var string $brandsUrl
 * @var string $categoriesUrl
 * @var string $mediaBase
 * @var array<string, string> $filters
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Brands');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Stock',
    'title' => 'Brands',
    'subtitle' => 'Create, rename, upload brand logos, and archive unused brands. Brand names must not start with GFH.',
]) ?>

<?= $this->partial('partials/products-nav', [
    'productsUrl' => $productsUrl,
    'brandsUrl' => $brandsUrl,
    'categoriesUrl' => $categoriesUrl,
    'activeTab' => $activeTab,
]) ?>

<?php if ($canEdit): ?>
<section class="kit-section" aria-labelledby="create-brand-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="create-brand-heading">New brand</h2>
                <p class="card__subtitle">Brand names must be unique and must not begin with GFH.</p>
            </div>
        </div>
        <form method="post" action="<?= $this->e($brandsUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <input type="hidden" name="return_to" value="<?= $this->e($brandsUrl) ?>">
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'brand-create-name',
                    'name' => 'name',
                    'label' => 'Brand name',
                    'value' => $createInput['name'] ?? '',
                    'placeholder' => 'e.g. Kape Negros',
                    'required' => true,
                    'error' => $createErrors['name'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'brand-create-manufacturer',
                    'name' => 'manufacturer',
                    'label' => 'Manufacturer (optional)',
                    'value' => $createInput['manufacturer'] ?? '',
                    'placeholder' => 'e.g. Negros Cooperative',
                    'error' => $createErrors['manufacturer'] ?? null,
                ]) ?>
            </div>
            <div class="card__footer">
                <?= $this->partial('partials/button', ['label' => 'Create brand', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>
<?php endif; ?>

<?php
$rows = [];
foreach ($result['items'] as $brand) {
    $id = (string) $brand['id'];
    $name = (string) $brand['name'];
    $status = (string) $brand['status'];
    $count = (int) ($brand['product_count'] ?? 0);
    $logoId = $brand['logo_media_id'] !== null ? (string) $brand['logo_media_id'] : null;

    if ($logoId !== null) {
        $logoHtml = '<img class="brand-logo-thumb" src="' . $this->e($mediaBase . '/' . $logoId . '?thumb=1') . '" alt="' . $this->e($name) . ' logo">';
    } else {
        $logoHtml = '<span class="table-meta">No logo</span>';
    }

    if ($canEdit) {
        $logoHtml .= '<form class="inline-upload-form" method="post" enctype="multipart/form-data" action="' . $this->e($brandsUrl . '/' . $id . '/logo') . '">'
            . '<input type="hidden" name="_csrf" value="' . $this->e($csrf) . '">'
            . '<input type="hidden" name="return_to" value="' . $this->e($brandsUrl) . '">'
            . '<label class="visually-hidden" for="brand-logo-' . $this->e($id) . '">Upload logo for ' . $this->e($name) . '</label>'
            . '<input class="compact-file-input" id="brand-logo-' . $this->e($id) . '" type="file" name="file" accept="image/jpeg,image/png,image/webp">'
            . '<button class="button button--secondary button--sm" type="submit">Upload</button>';
        if ($logoId !== null) {
            $logoHtml .= ' <button class="button button--ghost button--sm" type="submit" name="clear" value="1">Clear</button>';
        }
        $logoHtml .= '</form>';
        if (isset($logoErrors[$id])) {
            $logoHtml .= '<p class="field-error" role="alert">' . $this->e($logoErrors[$id]) . '</p>';
        }
    }

    $nameHtml = '<strong>' . $this->e($name) . '</strong>';
    if ($brand['manufacturer'] !== null && $brand['manufacturer'] !== '') {
        $nameHtml .= '<div class="table-meta">' . $this->e((string) $brand['manufacturer']) . '</div>';
    }

    if ($canEdit) {
        $renameVal = $rowInputs[$id]['name'] ?? $name;
        $renameErr = $rowErrors[$id] ?? null;
        $renameForm = '<form class="inline-action-form" method="post" action="' . $this->e($brandsUrl . '/' . $id . '/rename') . '" novalidate>'
            . '<input type="hidden" name="_csrf" value="' . $this->e($csrf) . '">'
            . '<input type="hidden" name="return_to" value="<?= $this->e($brandsUrl) ?>">'
            . '<label class="visually-hidden" for="brand-name-' . $this->e($id) . '">Rename ' . $this->e($name) . '</label>'
            . '<input type="text" id="brand-name-' . $this->e($id) . '" name="name" value="' . $this->e($renameVal) . '" required' . ($renameErr !== null ? ' aria-invalid="true"' : '') . '>'
            . '<button class="button button--secondary button--sm" type="submit">Rename</button>'
            . '</form>';
        $renameForm = str_replace('<?= $this->e($brandsUrl) ?>', $this->e($brandsUrl), $renameForm);
    } else {
        $renameForm = '';
    }

    $archiveForm = '';
    if ($canDelete) {
        $actionPath = $status === 'archived' ? '/restore' : '/archive';
        $actionLabel = $status === 'archived' ? 'Restore' : 'Archive';
        $btnTone = $status === 'archived' ? 'button--secondary' : 'button--danger';
        $archiveForm = '<form method="post" action="' . $this->e($brandsUrl . '/' . $id . $actionPath) . '">'
            . '<input type="hidden" name="_csrf" value="' . $this->e($csrf) . '">'
            . '<input type="hidden" name="return_to" value="' . $this->e($brandsUrl) . '">'
            . '<button class="button ' . $btnTone . ' button--sm" type="submit">' . $this->e($actionLabel) . '</button>'
            . '</form>';
    }

    $actionsHtml = '<div class="inline-actions">' . $renameForm . $archiveForm . '</div>';
    if (isset($rowErrors[$id])) {
        $actionsHtml .= '<p class="field-error" role="alert">' . $this->e($rowErrors[$id]) . '</p>';
    }

    $rows[] = [
        'cells' => [
            ['html' => $logoHtml],
            ['html' => $nameHtml],
            ['text' => (string) $count, 'numeric' => true],
            ['html' => $this->partial('partials/badge', ['label' => ucfirst($status), 'tone' => $status === 'active' ? 'ok' : 'neutral'])],
            ['html' => $actionsHtml],
        ],
    ];
}

ob_start();
?>
<label class="visually-hidden" for="brands-filter-archived">Show archived</label>
<select id="brands-filter-archived" name="archived">
    <option value=""<?= $filters['archived'] === '' ? ' selected' : '' ?>>Active brands only</option>
    <option value="1"<?= $filters['archived'] === '1' ? ' selected' : '' ?>>Include archived</option>
</select>
<?php
$filtersHtml = (string) ob_get_clean();
?>

<section class="kit-section" aria-labelledby="brands-list-heading">
    <h2 class="visually-hidden" id="brands-list-heading">Brand directory</h2>
    <?= $this->partial('partials/server-table', [
        'id' => 'brands-table',
        'action' => $brandsUrl,
        'searchPlaceholder' => 'Search brand name…',
        'searchValue' => (string) ($query->search ?? ''),
        'sort' => $query->sortLabel,
        'perPage' => $query->perPage,
        'filters' => $filters,
        'filtersHtml' => $filtersHtml,
        'columns' => [
            ['label' => 'Logo'],
            ['label' => 'Brand', 'sortKey' => 'name'],
            ['label' => 'Products', 'sortKey' => 'products', 'numeric' => true],
            ['label' => 'Status', 'sortKey' => 'status'],
            ['label' => 'Actions'],
        ],
        'rows' => $rows,
        'pagination' => $result['pagination'],
        'emptyTitle' => 'No brands found',
        'emptyBody' => 'Create a brand above or adjust your search.',
    ]) ?>
</section>
