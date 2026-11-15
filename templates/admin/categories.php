<?php
/**
 * Admin Categories screen (GET /{org}/admin/categories).
 * Categories support two levels; edit allows create/rename, delete allows archive/restore.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} $result
 * @var \Gfh\Core\Support\ListQuery $query
 * @var list<array<string, mixed>> $topCategories
 * @var array<string, string> $createInput
 * @var array<string, string> $createErrors
 * @var array<string, array<string, string>> $rowInputs
 * @var array<string, string> $rowErrors
 * @var bool $canEdit
 * @var bool $canDelete
 * @var string $activeTab
 * @var string $productsUrl
 * @var string $brandsUrl
 * @var string $categoriesUrl
 * @var array<string, string> $filters
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Categories');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Stock',
    'title' => 'Categories',
    'subtitle' => 'Two-level product categories. Categories with active products or sub-categories cannot be archived.',
]) ?>

<?= $this->partial('partials/products-nav', [
    'productsUrl' => $productsUrl,
    'brandsUrl' => $brandsUrl,
    'categoriesUrl' => $categoriesUrl,
    'activeTab' => $activeTab,
]) ?>

<?php if ($canEdit): ?>
<?php
$parentOptions = ['' => 'Top-level category'];
foreach ($topCategories as $top) {
    $parentOptions[(string) $top['id']] = (string) $top['name'];
}
?>
<section class="kit-section" aria-labelledby="create-category-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="create-category-heading">New category</h2>
                <p class="card__subtitle">Leave parent blank for a top-level category, or pick a top-level parent for a sub-category.</p>
            </div>
        </div>
        <form method="post" action="<?= $this->e($categoriesUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <input type="hidden" name="return_to" value="<?= $this->e($categoriesUrl) ?>">
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'category-create-parent',
                    'name' => 'parent_id',
                    'label' => 'Parent category',
                    'type' => 'select',
                    'value' => $createInput['parent_id'] ?? '',
                    'options' => $parentOptions,
                    'error' => $createErrors['parent_id'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'category-create-name',
                    'name' => 'name',
                    'label' => 'Category name',
                    'value' => $createInput['name'] ?? '',
                    'placeholder' => 'e.g. Espresso Machines',
                    'required' => true,
                    'error' => $createErrors['name'] ?? null,
                ]) ?>
            </div>
            <div class="card__footer">
                <?= $this->partial('partials/button', ['label' => 'Create category', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>
<?php endif; ?>

<?php
$rows = [];
foreach ($result['items'] as $cat) {
    $id = (string) $cat['id'];
    $name = (string) $cat['name'];
    $status = (string) $cat['status'];
    $depth = (int) ($cat['depth'] ?? 0);
    $parentName = (string) ($cat['parent_name'] ?? '');

    $labelHtml = ($depth > 0 ? '<span class="category-indent" aria-hidden="true">↳</span> ' : '')
        . '<strong>' . $this->e($name) . '</strong>'
        . '<div class="table-meta">' . $this->e((string) $cat['slug'])
        . ($parentName !== '' ? ' · under ' . $this->e($parentName) : ' · Top-level') . '</div>';

    $renameForm = '';
    if ($canEdit) {
        $renameVal = $rowInputs[$id]['name'] ?? $name;
        $renameErr = $rowErrors[$id] ?? null;
        $renameForm = '<form class="inline-action-form" method="post" action="' . $this->e($categoriesUrl . '/' . $id . '/rename') . '" novalidate>'
            . '<input type="hidden" name="_csrf" value="' . $this->e($csrf) . '">'
            . '<input type="hidden" name="return_to" value="' . $this->e($categoriesUrl) . '">'
            . '<label class="visually-hidden" for="cat-name-' . $this->e($id) . '">Rename ' . $this->e($name) . '</label>'
            . '<input type="text" id="cat-name-' . $this->e($id) . '" name="name" value="' . $this->e($renameVal) . '" required' . ($renameErr !== null ? ' aria-invalid="true"' : '') . '>'
            . '<button class="button button--secondary button--sm" type="submit">Rename</button>'
            . '</form>';
    }

    $archiveForm = '';
    if ($canDelete) {
        $actionPath = $status === 'archived' ? '/restore' : '/archive';
        $actionLabel = $status === 'archived' ? 'Restore' : 'Archive';
        $btnTone = $status === 'archived' ? 'button--secondary' : 'button--danger';
        $archiveForm = '<form method="post" action="' . $this->e($categoriesUrl . '/' . $id . $actionPath) . '">'
            . '<input type="hidden" name="_csrf" value="' . $this->e($csrf) . '">'
            . '<input type="hidden" name="return_to" value="' . $this->e($categoriesUrl) . '">'
            . '<button class="button ' . $btnTone . ' button--sm" type="submit">' . $this->e($actionLabel) . '</button>'
            . '</form>';
    }

    $actionsHtml = '<div class="inline-actions">' . $renameForm . $archiveForm . '</div>';
    if (isset($rowErrors[$id])) {
        $actionsHtml .= '<p class="field-error" role="alert">' . $this->e($rowErrors[$id]) . '</p>';
    }

    $rows[] = [
        'cells' => [
            ['html' => $labelHtml],
            ['html' => $this->partial('partials/badge', ['label' => ucfirst($status), 'tone' => $status === 'active' ? 'ok' : 'neutral'])],
            ['html' => $actionsHtml],
        ],
    ];
}

ob_start();
?>
<label class="visually-hidden" for="categories-filter-archived">Show archived</label>
<select id="categories-filter-archived" name="archived">
    <option value=""<?= $filters['archived'] === '' ? ' selected' : '' ?>>Active categories only</option>
    <option value="1"<?= $filters['archived'] === '1' ? ' selected' : '' ?>>Include archived</option>
</select>
<?php
$filtersHtml = (string) ob_get_clean();
?>

<section class="kit-section" aria-labelledby="categories-list-heading">
    <h2 class="visually-hidden" id="categories-list-heading">Category tree</h2>
    <?= $this->partial('partials/server-table', [
        'id' => 'categories-table',
        'action' => $categoriesUrl,
        'searchPlaceholder' => 'Search category or slug…',
        'searchValue' => (string) ($query->search ?? ''),
        'sort' => $query->sortLabel,
        'perPage' => $query->perPage,
        'filters' => $filters,
        'filtersHtml' => $filtersHtml,
        'columns' => [
            ['label' => 'Category', 'sortKey' => 'name'],
            ['label' => 'Status', 'sortKey' => 'status'],
            ['label' => 'Actions'],
        ],
        'rows' => $rows,
        'pagination' => $result['pagination'],
        'emptyTitle' => 'No categories found',
        'emptyBody' => 'Create a category above or clear the search.',
    ]) ?>
</section>
