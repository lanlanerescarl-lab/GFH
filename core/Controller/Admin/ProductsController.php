<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\CatalogService;
use Gfh\Core\Support\ExtensionAttributes;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Tenant;

/**
 * Admin Products controller: catalog listing, product & variant editing, attributes,
 * price tiers, price history, status transitions, multi-photo gallery, and product form hooks.
 */
final class ProductsController extends AdminPageController
{
    public function index(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'view');
        $catalog = $auth['services']->catalog();
        $query = ListQuery::fromRequest(
            $request,
            ['name' => 'p.name', 'price' => 'price_from', 'newest' => 'p.created_at', 'status' => 'p.status'],
            'name',
        );

        $filterErrors = [];
        $statusCode = 200;
        try {
            $filters = CatalogService::parseFilters($request->query());
            $result = $catalog->searchProducts($filters, $query, false);
        } catch (HttpException $exception) {
            $filterErrors = $exception->fields() !== [] ? $exception->fields() : ['q' => $exception->getMessage()];
            $statusCode = $exception->status();
            $result = $query->result([], 0);
        }

        return $this->render($request, $auth['tenant'], $auth['staff'], 'products', [
            'result' => $result,
            'query' => $query,
            'brands' => $catalog->activeBrands(),
            'categories' => $catalog->categoryTree(false),
            'productsUrl' => $this->adminUrl($request, $auth['tenant'], '/products'),
            'brandsUrl' => $this->adminUrl($request, $auth['tenant'], '/brands'),
            'categoriesUrl' => $this->adminUrl($request, $auth['tenant'], '/categories'),
            'newProductUrl' => $this->adminUrl($request, $auth['tenant'], '/products/new'),
            'canEdit' => $this->can($auth['staff'], $auth['tenant'], 'products', 'edit'),
            'activeTab' => 'products',
            'filterErrors' => $filterErrors,
            'filters' => [
                'brand_id' => $this->queryText($request, 'brand_id'),
                'category_id' => $this->queryText($request, 'category_id'),
                'status' => $this->queryText($request, 'status'),
                'min_price' => $this->queryText($request, 'min_price'),
                'max_price' => $this->queryText($request, 'max_price'),
            ],
        ], $statusCode);
    }

    public function createForm(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');

        return $this->renderNewForm($request, $auth['tenant'], $auth['staff'], $auth['services']);
    }

    public function store(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $post = $request->body();
        $attributes = $this->collectAttributes($post);

        $productInput = [
            'name' => $this->text($request, 'name'),
            'status' => $this->text($request, 'status', 'draft'),
            'description' => $this->text($request, 'description'),
            'brand_id' => $this->text($request, 'brand_id'),
            'category_id' => $this->text($request, 'category_id'),
            'supplier_id' => $this->text($request, 'supplier_id'),
            'attributes' => $attributes,
        ];

        $variantInput = [
            'sku' => $this->text($request, 'sku'),
            'name' => $this->text($request, 'variant_name'),
            'retail_price' => $this->text($request, 'retail_price'),
            'trade_price' => $this->text($request, 'trade_price'),
            'unit' => $this->text($request, 'unit', 'pc'),
            'reorder_level' => $this->text($request, 'reorder_level', '0'),
        ];

        $payload = $productInput + $this->extraFormFields($post);
        $hasInitialVariant = trim($variantInput['sku']) !== ''
            || trim($variantInput['retail_price']) !== ''
            || trim($variantInput['trade_price']) !== '';
        if ($hasInitialVariant) {
            $payload['variants'] = [$variantInput];
        }

        try {
            $reserved = $this->reservedAttributeErrors($attributes);
            if ($reserved !== []) {
                throw HttpException::unprocessable($reserved);
            }
            $created = $auth['services']->catalog()->createProduct($payload, $auth['staff']->id);
            $this->flash($request, 'ok', 'Product created.', 'You can now manage variants, price tiers and photos.');

            return Response::redirect($this->adminUrl($request, $auth['tenant'], '/products/' . $created['id']));
        } catch (HttpException $exception) {
            return $this->renderNewForm(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                $productInput,
                $variantInput,
                $this->errors($exception, 'name'),
                $exception->status(),
            );
        }
    }

    public function edit(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'view');
        $id = $this->id($request);
        $tab = $this->queryText($request, 'tab', 'details');

        return $this->renderEditForm($request, $auth['tenant'], $auth['staff'], $auth['services'], $id, ['tab' => $tab]);
    }

    public function update(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $post = $request->body();
        $attributes = $this->collectAttributes($post);

        $productInput = [
            'name' => $this->text($request, 'name'),
            'description' => $this->text($request, 'description'),
            'brand_id' => $this->text($request, 'brand_id'),
            'category_id' => $this->text($request, 'category_id'),
            'supplier_id' => $this->text($request, 'supplier_id'),
            'attributes' => $attributes,
        ];

        // A form that carries only some of the fields (an extension's tab posts its own field and nothing else)
        // leaves the others as they are: what was not sent keeps its stored value, here and when the page is drawn again.
        foreach (['name', 'description', 'brand_id', 'category_id', 'supplier_id'] as $field) {
            if (!array_key_exists($field, $post)) {
                unset($productInput[$field]);
            }
        }
        if (!array_key_exists('attributes', $post) && !array_key_exists('attr_new_key', $post)) {
            unset($productInput['attributes']);
        }
        $extraInput = $this->extraFormFields($post);
        $updatePayload = $productInput + $extraInput;
        $tab = $this->returnTab($post);

        try {
            // Names that start with ext_ belong to extensions and have no row in this list; one typed here would vanish.
            $reserved = $this->reservedAttributeErrors($attributes);
            if ($reserved !== []) {
                throw HttpException::unprocessable($reserved);
            }
            $auth['services']->catalog()->updateProduct($id, $updatePayload, $auth['staff']->id);
            $this->flash($request, 'ok', 'Product details saved.');

            return Response::redirect($this->adminUrl($request, $auth['tenant'], '/products/' . $id . '?tab=' . $tab));
        } catch (HttpException $exception) {
            return $this->renderEditForm(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                $id,
                [
                    'tab' => $tab,
                    'productInput' => $productInput,
                    'errors' => $this->errors($exception, 'name'),
                    'extensionInput' => $extraInput,
                ],
                $exception->status(),
            );
        }
    }

    public function setStatus(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $statusValue = $this->text($request, 'status');

        try {
            $auth['services']->catalog()->setProductStatus($id, $statusValue, $auth['staff']->id);
            $this->flash($request, 'ok', 'Product status updated.', 'Status changed to ' . $statusValue . '.');

            return Response::redirect($this->adminUrl($request, $auth['tenant'], '/products/' . $id));
        } catch (HttpException $exception) {
            return $this->renderEditForm(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                $id,
                ['tab' => 'details', 'errors' => $this->errors($exception, 'status')],
                $exception->status(),
            );
        }
    }

    public function addVariant(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $data = $this->variantPostData($request);

        try {
            $auth['services']->catalog()->createVariant($id, $data, $auth['staff']->id);
            $this->flash($request, 'ok', 'Variant added.', $data['sku'] . ' has been added and logged to Price History.');

            return Response::redirect($this->adminUrl($request, $auth['tenant'], '/products/' . $id . '?tab=variants'));
        } catch (HttpException $exception) {
            return $this->renderEditForm(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                $id,
                ['tab' => 'variants', 'newVariantInput' => $data, 'newVariantErrors' => $this->errors($exception, 'sku')],
                $exception->status(),
            );
        }
    }

    public function updateVariant(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $variantId = $this->id($request);
        $productId = $this->requireProductId($request);
        $data = $this->variantPostData($request);

        try {
            $auth['services']->catalog()->updateVariant($variantId, $data, $auth['staff']->id);
            $this->flash($request, 'ok', 'Variant updated.', 'Changes saved for ' . $data['sku'] . '.');

            return Response::redirect($this->adminUrl($request, $auth['tenant'], '/products/' . $productId . '?tab=variants'));
        } catch (HttpException $exception) {
            return $this->renderEditForm(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                $productId,
                ['tab' => 'variants', 'variantInputs' => [$variantId => $data], 'variantErrors' => [$variantId => $this->errors($exception, 'sku')]],
                $exception->status(),
            );
        }
    }

    public function addTier(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $variantId = $this->id($request);
        $productId = $this->requireProductId($request);
        $data = [
            'basis' => $this->text($request, 'basis', 'quantity'),
            'tier_key' => $this->text($request, 'tier_key'),
            'label' => $this->text($request, 'label'),
            'min_qty' => $this->text($request, 'min_qty', '1'),
            'price' => $this->text($request, 'price'),
        ];

        try {
            $auth['services']->catalog()->saveTier($variantId, $data, null, $auth['staff']->id);
            $this->flash($request, 'ok', 'Price tier added.');

            return Response::redirect($this->adminUrl($request, $auth['tenant'], '/products/' . $productId . '?tab=pricing'));
        } catch (HttpException $exception) {
            return $this->renderEditForm(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                $productId,
                ['tab' => 'pricing', 'newTierInputs' => [$variantId => $data], 'newTierErrors' => [$variantId => $this->errors($exception, 'price')]],
                $exception->status(),
            );
        }
    }

    public function updateTier(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $tierId = $this->id($request);
        $productId = $this->requireProductId($request);
        $variantId = $this->text($request, 'variant_id');
        if (!Uuid::isValid($variantId)) {
            throw HttpException::notFound('That variant could not be found.');
        }

        $data = [
            'tier_key' => $this->text($request, 'tier_key'),
            'label' => $this->text($request, 'label'),
            'min_qty' => $this->text($request, 'min_qty', '1'),
            'price' => $this->text($request, 'price'),
        ];

        try {
            $auth['services']->catalog()->saveTier($variantId, $data, $tierId, $auth['staff']->id);
            $this->flash($request, 'ok', 'Price tier saved.');

            return Response::redirect($this->adminUrl($request, $auth['tenant'], '/products/' . $productId . '?tab=pricing'));
        } catch (HttpException $exception) {
            return $this->renderEditForm(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                $productId,
                ['tab' => 'pricing', 'tierInputs' => [$tierId => $data], 'tierErrors' => [$tierId => $this->errors($exception, 'price')]],
                $exception->status(),
            );
        }
    }

    public function deleteTier(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $tierId = $this->id($request);
        $productId = $this->requireProductId($request);

        $auth['services']->catalog()->deleteTier($tierId, $auth['staff']->id);
        $this->flash($request, 'ok', 'Price tier deleted.');

        return Response::redirect($this->adminUrl($request, $auth['tenant'], '/products/' . $productId . '?tab=pricing'));
    }

    public function uploadPhotos(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $catalog = $auth['services']->catalog();
        $product = $catalog->getProduct($id, ['tiers' => true, 'stock' => true]) ?? throw HttpException::notFound('That product could not be found.');

        $variantId = trim($this->text($request, 'variant_id'));
        $altText = trim($this->text($request, 'alt_text'));
        $makePrimary = $this->text($request, 'primary') === '1';

        try {
            if ($variantId !== '') {
                $belongs = false;
                foreach ($product['variants'] ?? [] as $v) {
                    if ((string) $v['id'] === $variantId) {
                        $belongs = true;
                        break;
                    }
                }
                if (!$belongs) {
                    throw HttpException::unprocessable(['variant_id' => 'That variant does not belong to this product.']);
                }
            }

            $uploaded = $this->uploadImages($request, $auth['services'], $auth['staff'], 'files', $altText === '' ? null : $altText);
            foreach ($uploaded as $index => $media) {
                $catalog->attachImage($id, $media['id'], $variantId === '' ? null : $variantId, $makePrimary && $index === 0);
            }
            $this->flash($request, 'ok', count($uploaded) === 1 ? 'Photo uploaded.' : count($uploaded) . ' photos uploaded.');

            return Response::redirect($this->adminUrl($request, $auth['tenant'], '/products/' . $id . '?tab=photos'));
        } catch (HttpException $exception) {
            return $this->renderEditForm(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                $id,
                ['tab' => 'photos', 'photoErrors' => $this->errors($exception, 'files')],
                $exception->status(),
            );
        }
    }

    public function reorderPhotos(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $catalog = $auth['services']->catalog();
        $product = $catalog->getProduct($id, ['tiers' => true, 'stock' => true]) ?? throw HttpException::notFound('That product could not be found.');
        $images = $product['images'] ?? [];
        $currentIds = array_map(static fn (array $img): string => (string) $img['id'], $images);

        try {
            $moveId = $this->text($request, 'move_id');
            $direction = $this->text($request, 'direction');
            if ($moveId !== '' && in_array($direction, ['up', 'down'], true)) {
                $pos = array_search($moveId, $currentIds, true);
                if ($pos === false) {
                    throw HttpException::unprocessable(['order' => 'That photo no longer belongs to this product.']);
                }
                $target = $direction === 'up' ? $pos - 1 : $pos + 1;
                if ($target >= 0 && $target < count($currentIds)) {
                    $tmp = $currentIds[$target];
                    $currentIds[$target] = $currentIds[$pos];
                    $currentIds[$pos] = $tmp;
                }
                $ordered = $currentIds;
            } else {
                $orderPost = $request->body()['order'] ?? null;
                if (!is_array($orderPost)) {
                    throw HttpException::unprocessable(['order' => 'Provide positions for every photo.']);
                }
                $indexed = [];
                foreach ($currentIds as $index => $imgId) {
                    $indexed[] = ['id' => $imgId, 'pos' => (int) ($orderPost[$imgId] ?? ($index + 1)), 'orig' => $index];
                }
                usort($indexed, static fn (array $a, array $b): int => ($a['pos'] <=> $b['pos']) ?: ($a['orig'] <=> $b['orig']));
                $ordered = array_map(static fn (array $row): string => $row['id'], $indexed);
            }

            $catalog->reorderImages($id, $ordered);
            $this->flash($request, 'ok', 'Photo order updated.');

            return Response::redirect($this->adminUrl($request, $auth['tenant'], '/products/' . $id . '?tab=photos'));
        } catch (HttpException $exception) {
            return $this->renderEditForm(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                $id,
                ['tab' => 'photos', 'photoErrors' => ['order' => $exception->getMessage()]],
                $exception->status(),
            );
        }
    }

    public function setPrimaryPhoto(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $imageId = $this->id($request);
        $productId = $this->requireProductId($request);

        $auth['services']->catalog()->setPrimaryImage($imageId);
        $this->flash($request, 'ok', 'Primary photo updated.');

        return Response::redirect($this->adminUrl($request, $auth['tenant'], '/products/' . $productId . '?tab=photos'));
    }

    public function removePhoto(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $imageId = $this->id($request);
        $productId = $this->requireProductId($request);

        $auth['services']->catalog()->removeImage($imageId);
        $this->flash($request, 'ok', 'Photo removed.');

        return Response::redirect($this->adminUrl($request, $auth['tenant'], '/products/' . $productId . '?tab=photos'));
    }

    /**
     * @param array<string, mixed> $productInput
     * @param array<string, mixed> $variantInput
     * @param array<string, string> $errors
     */
    private function renderNewForm(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        TenantServices $services,
        array $productInput = [],
        array $variantInput = [],
        array $errors = [],
        int $status = 200,
    ): Response {
        $catalog = $services->catalog();
        $attrs = is_array($productInput['attributes'] ?? null) ? $productInput['attributes'] : [];

        return $this->render($request, $tenant, $staff, 'product-form', [
            'isNew' => true,
            'product' => null,
            'activeSectionTab' => 'details',
            'productsUrl' => $this->adminUrl($request, $tenant, '/products'),
            'brandsUrl' => $this->adminUrl($request, $tenant, '/brands'),
            'categoriesUrl' => $this->adminUrl($request, $tenant, '/categories'),
            'adminBase' => $this->adminUrl($request, $tenant),
            'mediaBase' => $request->basePath() . '/media/' . $tenant->organization->slug,
            'brands' => $catalog->activeBrands(),
            'categories' => $catalog->categoryTree(false),
            'suppliers' => $catalog->activeSuppliers(),
            'productInput' => $productInput,
            'errors' => $errors,
            'attributeRows' => $this->attributeRows($attrs, $errors),
            'variantInput' => $variantInput,
            'variantInputs' => [],
            'variantErrors' => [],
            'newVariantInput' => [],
            'newVariantErrors' => [],
            'newTierInputs' => [],
            'newTierErrors' => [],
            'tierInputs' => [],
            'tierErrors' => [],
            'priceHistory' => [],
            'photoErrors' => [],
            'extensionTabs' => [],
            'canEdit' => $this->can($staff, $tenant, 'products', 'edit'),
        ], $status);
    }

    /** @param array<string, mixed> $state */
    private function renderEditForm(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        TenantServices $services,
        string $productId,
        array $state = [],
        int $status = 200,
    ): Response {
        $catalog = $services->catalog();
        $product = $catalog->getProduct($productId, ['tiers' => true, 'stock' => true])
            ?? throw HttpException::notFound('That product could not be found.');

        $storedAttrs = $product['attributes'] instanceof \stdClass ? [] : ExtensionAttributes::visible((array) $product['attributes']);
        $defaultProductInput = [
            'name' => (string) $product['name'],
            'description' => (string) ($product['description'] ?? ''),
            'brand_id' => (string) ($product['brand_id'] ?? ''),
            'category_id' => (string) ($product['category_id'] ?? ''),
            'supplier_id' => (string) ($product['supplier_id'] ?? ''),
            'attributes' => $storedAttrs,
        ];
        // A save that carried only some fields (an extension tab) draws the rest from the stored product.
        $productInput = ($state['productInput'] ?? []) + $defaultProductInput;
        $errors = $state['errors'] ?? [];
        $attrs = is_array($productInput['attributes'] ?? null) ? $productInput['attributes'] : [];

        $canEdit = $this->can($staff, $tenant, 'products', 'edit');
        $session = $request->attribute('session');
        // csrf, errors and input let an extension tab post its own form and show a rejected value again;
        // input is empty unless the page is drawn again after a refused save.
        $rawTabs = $services->hooks()->filter(Hooks::PRODUCT_FORM_TABS, [], [
            'product' => $product,
            'can_edit' => $canEdit,
            'admin_base' => $this->adminUrl($request, $tenant),
            'csrf' => $session instanceof Session ? $this->services->csrf($session)->token() : '',
            'errors' => $errors,
            'input' => is_array($state['extensionInput'] ?? null) ? $state['extensionInput'] : [],
        ]);
        $extensionTabs = $this->normalizeExtensionTabs(is_array($rawTabs) ? $rawTabs : []);

        $validTabs = array_merge(['details', 'variants', 'pricing', 'photos'], array_map(static fn (array $t): string => $t['id'], $extensionTabs));
        $requestedTab = (string) ($state['tab'] ?? 'details');
        $activeSectionTab = in_array($requestedTab, $validTabs, true) ? $requestedTab : 'details';

        return $this->render($request, $tenant, $staff, 'product-form', [
            'isNew' => false,
            'product' => $product,
            'activeSectionTab' => $activeSectionTab,
            'productsUrl' => $this->adminUrl($request, $tenant, '/products'),
            'brandsUrl' => $this->adminUrl($request, $tenant, '/brands'),
            'categoriesUrl' => $this->adminUrl($request, $tenant, '/categories'),
            'adminBase' => $this->adminUrl($request, $tenant),
            'mediaBase' => $request->basePath() . '/media/' . $tenant->organization->slug,
            'brands' => $catalog->activeBrands(),
            'categories' => $catalog->categoryTree(false),
            'suppliers' => $catalog->activeSuppliers(),
            'productInput' => $productInput,
            'errors' => $errors,
            'attributeRows' => $this->attributeRows($attrs, $errors),
            'variantInput' => [],
            'variantInputs' => $state['variantInputs'] ?? [],
            'variantErrors' => $state['variantErrors'] ?? [],
            'newVariantInput' => $state['newVariantInput'] ?? [],
            'newVariantErrors' => $state['newVariantErrors'] ?? [],
            'newTierInputs' => $state['newTierInputs'] ?? [],
            'newTierErrors' => $state['newTierErrors'] ?? [],
            'tierInputs' => $state['tierInputs'] ?? [],
            'tierErrors' => $state['tierErrors'] ?? [],
            'priceHistory' => $catalog->priceHistory($productId),
            'photoErrors' => $state['photoErrors'] ?? [],
            'extensionTabs' => $extensionTabs,
            'canEdit' => $canEdit,
        ], $status);
    }

    /**
     * Combines submitted existing attribute values (`attributes[key]`) with new rows (`attr_new_key[]` / `attr_new_val[]`).
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private function collectAttributes(array $post): array
    {
        $collected = [];
        if (is_array($post['attributes'] ?? null)) {
            foreach ($post['attributes'] as $key => $value) {
                $k = trim((string) $key);
                if ($k !== '' && !is_array($value)) {
                    $collected[$k] = trim((string) $value);
                }
            }
        }

        $newKeys = is_array($post['attr_new_key'] ?? null) ? $post['attr_new_key'] : [];
        $newVals = is_array($post['attr_new_val'] ?? null) ? $post['attr_new_val'] : [];
        foreach ($newKeys as $index => $rawKey) {
            $k = trim((string) $rawKey);
            $v = trim((string) ($newVals[$index] ?? ''));
            if ($k === '' && $v === '') {
                continue;
            }
            // Keep explicit keys even when blank so validation.attributes can reject missing required keys.
            $collected[$k] = $v;
        }

        return $collected;
    }

    /**
     * Pairs attribute keys/values with any `attributes.{key}` errors returned by CatalogService / validation.attributes.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, string> $errors
     * @return list<array{key: string, value: string, error: ?string}>
     */
    private function attributeRows(array $attributes, array $errors): array
    {
        $attrErrors = [];
        foreach ($errors as $field => $message) {
            if (str_starts_with($field, 'attributes.')) {
                $attrErrors[substr($field, 11)] = $message;
            }
        }

        $rows = [];
        $seen = [];
        foreach ($attributes as $key => $value) {
            $k = (string) $key;
            $seen[$k] = true;
            $val = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $rows[] = ['key' => $k, 'value' => $val, 'error' => $attrErrors[$k] ?? null];
        }
        foreach ($attrErrors as $key => $message) {
            if (!isset($seen[$key])) {
                $rows[] = ['key' => $key, 'value' => '', 'error' => $message];
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function variantPostData(Request $request): array
    {
        return [
            'sku' => $this->text($request, 'sku'),
            'barcode' => $this->text($request, 'barcode'),
            'name' => $this->text($request, 'name'),
            'unit' => $this->text($request, 'unit', 'pc'),
            'unit_size' => $this->text($request, 'unit_size'),
            'allow_decimal_qty' => $this->text($request, 'allow_decimal_qty', '0') === '1',
            'qty_step' => $this->text($request, 'qty_step', '1'),
            'min_qty' => $this->text($request, 'min_qty', '1'),
            'track_batches' => $this->text($request, 'track_batches', '0') === '1',
            'retail_price' => $this->text($request, 'retail_price'),
            'trade_price' => $this->text($request, 'trade_price'),
            'reorder_level' => $this->text($request, 'reorder_level', '0'),
            'status' => $this->text($request, 'status', 'active'),
        ];
    }

    /**
     * Extension fields posted on product forms for `product.form.save` hooks to inspect/consume.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function extraFormFields(array $post): array
    {
        $reserved = [
            '_csrf', 'name', 'status', 'description', 'brand_id', 'category_id', 'supplier_id',
            'attributes', 'attr_new_key', 'attr_new_val', 'sku', 'variant_name', 'retail_price',
            'trade_price', 'unit', 'reorder_level', 'tab',
        ];
        $extra = [];
        foreach ($post as $key => $value) {
            if (!in_array((string) $key, $reserved, true)) {
                $extra[(string) $key] = $value;
            }
        }

        return $extra;
    }

    /**
     * The tab to show after a save: the one the form came from when it names a tab (an extension tab posts its own id),
     * otherwise Details. The page checks the name against the tabs it really has, so a made-up one shows Details.
     *
     * @param array<string, mixed> $post
     */
    private function returnTab(array $post): string
    {
        $tab = is_string($post['tab'] ?? null) ? $post['tab'] : '';

        return preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/D', $tab) === 1 ? $tab : 'details';
    }

    /**
     * @param array<string, string> $attributes
     * @return array<string, string> field errors for attribute names that are reserved for extensions
     */
    private function reservedAttributeErrors(array $attributes): array
    {
        $errors = [];
        foreach ($attributes as $key => $value) {
            if (ExtensionAttributes::isExtensionKey((string) $key)) {
                $errors['attributes.' . $key] = 'Names that start with ' . ExtensionAttributes::PREFIX . ' are kept for extensions. Choose another name.';
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $rawTabs
     * @return list<array{id: string, label: string, contentHtml: string}>
     */
    private function normalizeExtensionTabs(array $rawTabs): array
    {
        $builtIn = ['details', 'variants', 'pricing', 'photos'];
        $out = [];
        foreach ($rawTabs as $index => $tab) {
            if (!is_array($tab)) {
                continue;
            }
            $label = trim((string) ($tab['label'] ?? ''));
            $contentHtml = (string) ($tab['contentHtml'] ?? $tab['content'] ?? '');
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) ($tab['id'] ?? 'ext-' . $index)) ?? ('ext-' . $index);
            if ($label === '' || $id === '' || in_array($id, $builtIn, true)) {
                continue;
            }
            $out[] = ['id' => $id, 'label' => $label, 'contentHtml' => $contentHtml];
        }

        return $out;
    }

    private function requireProductId(Request $request): string
    {
        $productId = $this->text($request, 'product_id');
        if (!Uuid::isValid($productId)) {
            throw HttpException::notFound('That product could not be found.');
        }

        return $productId;
    }

    /** @return array<string, string> */
    private function errors(HttpException $exception, string $fallbackField): array
    {
        return $exception->fields() !== [] ? $exception->fields() : [$fallbackField => $exception->getMessage()];
    }
}
