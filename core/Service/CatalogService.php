<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Database\Database;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\CatalogRepository;
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\ExtensionAttributes;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Support\Validation;

/**
 * Products, variants, brands, categories, attributes, price tiers and product photos.
 * Permission checks belong to the controller (view / edit / delete on Products); the rules below are the ones
 * that hold no matter who asks:
 *  - brand names must not start with GFH; a brand or category still in use cannot be archived;
 *  - categories go two levels deep;
 *  - a product cannot be active without an active variant;
 *  - SKUs and barcodes are unique; price changes are written to price history and the audit log.
 */
final class CatalogService
{
    public const PRODUCT_STATUSES = ['draft', 'active', 'discontinued'];
    public const VARIANT_STATUSES = ['active', 'inactive', 'discontinued'];

    /** Sort names of the public listing (name => SQL expression). The JSON API and the Shop use this one list. */
    public const PUBLIC_SORTS = ['name' => 'p.name', 'price' => 'price_from', 'newest' => 'p.created_at'];

    /** Query parameters the public listing accepts as filters. */
    public const PUBLIC_FILTER_KEYS = ['brand_id', 'category_id', 'min_price', 'max_price', 'attr'];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly CatalogRepository $repository,
        private readonly AuditService $audit,
        private readonly Hooks $hooks,
    ) {
    }

    // ------------------------------------------------------------------ brands

    /** @return list<array<string, mixed>> */
    public function activeBrands(): array
    {
        return $this->repository->activeBrands();
    }

    /** @return list<array<string, mixed>> */
    public function activeSuppliers(): array
    {
        return $this->repository->activeSuppliers();
    }

    /** @return list<array<string, mixed>> */
    public function priceHistory(string $productId): array
    {
        return Uuid::isValid($productId) ? $this->repository->priceHistoryForProduct($productId) : [];
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} */
    public function listBrands(ListQuery $query, bool $includeArchived): array
    {
        [$rows, $total] = $this->repository->listBrands($query, $includeArchived);

        return $query->result($rows, $total);
    }

    /** @return array{id: string} */
    public function createBrand(string $name, ?string $manufacturer, ?string $actorId): array
    {
        $clean = $this->brandName($name, null);
        $maker = trim((string) $manufacturer) === '' ? 'GFH' : mb_substr(trim((string) $manufacturer), 0, 80);

        $id = $this->repository->createBrand($clean, $maker);
        $this->audit->organization($this->pdo, 'brand.created', $actorId, 'brand', $id, ['name' => $clean]);

        return ['id' => $id];
    }

    public function renameBrand(string $id, string $name, ?string $actorId): void
    {
        $brand = $this->repository->brandById($id) ?? throw HttpException::notFound('That brand could not be found.');
        $clean = $this->brandName($name, $id);

        $this->repository->renameBrand($id, $clean);
        $this->audit->organization($this->pdo, 'brand.renamed', $actorId, 'brand', $id, ['from' => $brand['name'], 'to' => $clean]);
    }

    public function setBrandLogo(string $id, ?string $mediaId, ?string $actorId): void
    {
        $this->repository->brandById($id) ?? throw HttpException::notFound('That brand could not be found.');
        if ($mediaId !== null && !$this->repository->mediaExists($mediaId)) {
            throw HttpException::unprocessable(['logo_media_id' => 'That image does not exist.']);
        }

        $this->repository->setBrandLogo($id, $mediaId);
        $this->audit->organization($this->pdo, 'brand.logo_changed', $actorId, 'brand', $id, []);
    }

    /** Archiving is the only way a brand leaves use, and only when no product still carries it. */
    public function archiveBrand(string $id, ?string $actorId): void
    {
        $this->repository->brandById($id) ?? throw HttpException::notFound('That brand could not be found.');

        $inUse = $this->repository->brandUsage($id);
        if ($inUse > 0) {
            throw HttpException::conflict(sprintf('This brand is used by %d product(s). Move or discontinue them first.', $inUse));
        }

        $this->repository->setBrandStatus($id, 'archived');
        $this->audit->organization($this->pdo, 'brand.archived', $actorId, 'brand', $id, []);
    }

    public function restoreBrand(string $id, ?string $actorId): void
    {
        $this->repository->brandById($id) ?? throw HttpException::notFound('That brand could not be found.');
        $this->repository->setBrandStatus($id, 'active');
        $this->audit->organization($this->pdo, 'brand.restored', $actorId, 'brand', $id, []);
    }

    private function brandName(string $name, ?string $exceptId): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $name));
        $length = mb_strlen($clean);

        if ($length < 2 || $length > 60) {
            throw HttpException::unprocessable(['name' => 'Enter a brand name of 2 to 60 characters.']);
        }
        if (stripos($clean, 'gfh') === 0) {
            throw HttpException::unprocessable(['name' => 'Brand names must not start with GFH.']);
        }

        $existing = $this->repository->brandIdByName($clean);
        if ($existing !== null && $existing !== $exceptId) {
            throw HttpException::unprocessable(['name' => 'A brand with this name already exists (it may be archived).']);
        }

        return $clean;
    }

    // ------------------------------------------------------------------ categories

    /**
     * The two-level tree: top-level categories, each with its children.
     *
     * @return list<array<string, mixed>>
     */
    public function categoryTree(bool $includeArchived = false): array
    {
        $all = $this->repository->allCategories($includeArchived);
        $children = [];
        foreach ($all as $row) {
            if ($row['parent_id'] !== null) {
                $children[(string) $row['parent_id']][] = $row;
            }
        }

        $tree = [];
        foreach ($all as $row) {
            if ($row['parent_id'] === null) {
                $row['children'] = $children[(string) $row['id']] ?? [];
                $tree[] = $row;
            }
        }

        return $tree;
    }

    /** @return array{id: string, slug: string} */
    public function createCategory(?string $parentId, string $name, ?string $actorId): array
    {
        $clean = $this->categoryName($name);

        if ($parentId !== null && $parentId !== '') {
            $parent = Uuid::isValid($parentId) ? $this->repository->categoryById($parentId) : null;
            if ($parent === null || $parent['status'] !== 'active') {
                throw HttpException::unprocessable(['parent_id' => 'Choose an existing category as the parent.']);
            }
            if ($parent['parent_id'] !== null) {
                throw HttpException::unprocessable(['parent_id' => 'Categories go two levels deep: pick a top-level category.']);
            }
        } else {
            $parentId = null;
        }

        if ($this->repository->categoryNameExists($parentId, $clean)) {
            throw HttpException::unprocessable(['name' => 'A category with this name already exists here.']);
        }

        $base = Validation::slug($clean);
        $slug = $base;
        for ($n = 2; $this->repository->categorySlugExists($slug); $n++) {
            $slug = substr($base, 0, 55) . '-' . $n;
        }

        $id = $this->repository->createCategory($parentId, $clean, $slug, 0);
        $this->audit->organization($this->pdo, 'category.created', $actorId, 'category', $id, ['name' => $clean]);

        return ['id' => $id, 'slug' => $slug];
    }

    public function renameCategory(string $id, string $name, ?string $actorId): void
    {
        $category = $this->repository->categoryById($id) ?? throw HttpException::notFound('That category could not be found.');
        $clean = $this->categoryName($name);

        if ($this->repository->categoryNameExists($category['parent_id'] === null ? null : (string) $category['parent_id'], $clean, $id)) {
            throw HttpException::unprocessable(['name' => 'A category with this name already exists here.']);
        }

        $this->repository->renameCategory($id, $clean);
        $this->audit->organization($this->pdo, 'category.renamed', $actorId, 'category', $id, ['from' => $category['name'], 'to' => $clean]);
    }

    public function archiveCategory(string $id, ?string $actorId): void
    {
        $this->repository->categoryById($id) ?? throw HttpException::notFound('That category could not be found.');

        $products = $this->repository->categoryUsage($id);
        $children = $this->repository->categoryActiveChildren($id);
        if ($products > 0 || $children > 0) {
            throw HttpException::conflict(sprintf('This category is still in use (%d product(s), %d sub-categorie(s)). Move them first.', $products, $children));
        }

        $this->repository->setCategoryStatus($id, 'archived');
        $this->audit->organization($this->pdo, 'category.archived', $actorId, 'category', $id, []);
    }

    public function restoreCategory(string $id, ?string $actorId): void
    {
        $this->repository->categoryById($id) ?? throw HttpException::notFound('That category could not be found.');
        $this->repository->setCategoryStatus($id, 'active');
        $this->audit->organization($this->pdo, 'category.restored', $actorId, 'category', $id, []);
    }

    private function categoryName(string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($clean) < 2 || mb_strlen($clean) > 60) {
            throw HttpException::unprocessable(['name' => 'Enter a category name of 2 to 60 characters.']);
        }

        return $clean;
    }

    // ------------------------------------------------------------------ products

    /**
     * Creates a product and, optionally, its first variants in one step.
     *
     * @param array<string, mixed> $data name, description, brand_id, category_id, status, supplier_id, attributes, variants[]
     * @return array{id: string, slug: string}
     * @throws HttpException 422 with field errors (nested variant fields are named variants.0.sku and so on)
     */
    public function createProduct(array $data, ?string $actorId): array
    {
        return Database::transaction($this->pdo, function () use ($data, $actorId): array {
            $data = (array) $this->hooks->filter(Hooks::PRODUCT_FORM_SAVE, $data, ['product_id' => null, 'actor_id' => $actorId, 'current_attributes' => []]);
            $fields = $this->productFields($data, null);

            $variants = is_array($data['variants'] ?? null) ? array_values($data['variants']) : [];
            if ($fields['status'] === 'active' && $variants === []) {
                throw HttpException::unprocessable(['status' => 'Add at least one variant before making the product active.']);
            }

            $base = Validation::slug((string) ($data['slug'] ?? $fields['name']));
            $slug = $base;
            for ($n = 2; $this->repository->productSlugExists($slug); $n++) {
                $slug = substr($base, 0, 55) . '-' . $n;
            }

            $id = $this->repository->createProduct(
                $fields['name'], $slug, $fields['description'], $fields['brand_id'], $fields['category_id'],
                $fields['status'], $fields['supplier_id'], $fields['attributes'], $actorId,
            );

            foreach ($variants as $index => $variant) {
                try {
                    $this->createVariant($id, (array) $variant, $actorId);
                } catch (HttpException $exception) {
                    $prefixed = [];
                    foreach ($exception->fields() as $field => $message) {
                        $prefixed["variants.$index.$field"] = $message;
                    }
                    throw HttpException::unprocessable($prefixed === [] ? ['variants' => $exception->getMessage()] : $prefixed);
                }
            }

            $this->audit->organization($this->pdo, 'product.created', $actorId, 'product', $id, ['name' => $fields['name']]);

            return ['id' => $id, 'slug' => $slug];
        });
    }

    /** @param array<string, mixed> $data */
    public function updateProduct(string $id, array $data, ?string $actorId): void
    {
        Database::transaction($this->pdo, function () use ($id, $data, $actorId): void {
            $product = $this->repository->productById($id) ?? throw HttpException::notFound('That product could not be found.');
            $stored = json_decode((string) $product['attributes'], true) ?: [];
            // current_attributes lets an extension that owns an "ext_" attribute add its value to the complete set.
            $data = (array) $this->hooks->filter(Hooks::PRODUCT_FORM_SAVE, $data, ['product_id' => $id, 'actor_id' => $actorId, 'current_attributes' => $stored]);
            // Fields that were not sent keep their current value.
            $current = [
                'name' => $product['name'],
                'description' => $product['description'],
                'brand_id' => $product['brand_id'],
                'category_id' => $product['category_id'],
                'supplier_id' => $product['supplier_id'],
                'attributes' => $stored,
                'status' => $product['status'],
            ];
            $merged = $data + $current;
            // The Admin attribute list never shows extension-owned attributes, so a save of that list cannot carry them.
            // Keeping the stored ones here (a value the hook set wins) is what stops such a save from erasing them.
            if (is_array($merged['attributes'])) {
                $merged['attributes'] += ExtensionAttributes::hidden($stored);
            }
            $fields = $this->productFields($merged, $id);

            $this->repository->updateProduct($id, $fields['name'], $fields['description'], $fields['brand_id'], $fields['category_id'], $fields['supplier_id'], $fields['attributes']);
            $this->audit->organization($this->pdo, 'product.updated', $actorId, 'product', $id, ['name' => $fields['name']]);
        });
    }

    public function setProductStatus(string $id, string $status, ?string $actorId): void
    {
        $this->repository->productById($id) ?? throw HttpException::notFound('That product could not be found.');
        if (!in_array($status, self::PRODUCT_STATUSES, true)) {
            throw HttpException::unprocessable(['status' => 'Choose draft, active or discontinued.']);
        }
        if ($status === 'active' && $this->repository->variantCount($id) === 0) {
            throw HttpException::unprocessable(['status' => 'Add an active variant before making the product active.']);
        }

        $this->repository->setProductStatus($id, $status);
        $this->audit->organization($this->pdo, 'product.status_changed', $actorId, 'product', $id, ['status' => $status]);
    }

    /**
     * One product with its brand, category, variants, photos and (when asked) price tiers and stock.
     * Public callers get active products only, and never costs.
     *
     * @param array{public?: bool, tiers?: bool, stock?: bool} $options
     * @return array<string, mixed>|null
     */
    public function getProduct(string $idOrSlug, array $options = []): ?array
    {
        $product = Uuid::isValid($idOrSlug) ? $this->repository->productById($idOrSlug) : $this->repository->productBySlug($idOrSlug);
        $public = (bool) ($options['public'] ?? false);
        if ($product === null || ($public && $product['status'] !== 'active')) {
            return null;
        }

        $variants = $this->repository->variantsForProduct((string) $product['id'], $public);
        if ($public && $variants === []) {
            return null;
        }

        $ids = array_map(static fn (array $v): string => (string) $v['id'], $variants);
        $tiers = ($options['tiers'] ?? false) ? $this->repository->tiersForVariants($ids) : [];
        $onHand = $this->repository->onHandByVariant($ids);

        foreach ($variants as &$variant) {
            $variant['tiers'] = array_values(array_filter($tiers, static fn (array $t): bool => $t['variant_id'] === $variant['id']));
            $variant['in_stock'] = (Decimal::parse($onHand[(string) $variant['id']] ?? '0', 3) ?? 0) > 0;
            if (($options['stock'] ?? false)) {
                $variant['on_hand'] = $onHand[(string) $variant['id']] ?? '0.000';
            }
            if ($public) {
                unset($variant['avg_cost'], $variant['cost_updated_at'], $variant['trade_price']);
            }
        }
        unset($variant);

        $product['attributes'] = json_decode((string) $product['attributes'], true) ?: new \stdClass();
        if ($public) {
            unset($product['supplier_id']);
        }

        return $product + ['variants' => $variants, 'images' => $this->repository->imagesForProduct((string) $product['id'])];
    }

    /**
     * One product as the public Shop shows it: an active product with at least one active variant, no costs or trade
     * prices, and price tiers only for a signed-in customer, limited to the tiers that customer qualifies for.
     * The JSON API and the Shop pages both call this, so these visibility and tier rules exist in one place.
     *
     * @param array<string, mixed>|null $customer the organization's customer record (its tier and customer_type), or null for a guest
     * @return array<string, mixed>|null
     */
    public function getPublicProduct(string $slug, ?array $customer): ?array
    {
        $product = $this->getProduct($slug, ['public' => true, 'tiers' => $customer !== null]);
        if ($product === null) {
            return null;
        }

        foreach ($product['variants'] as &$variant) {
            $variant['tiers'] = array_values(array_filter($variant['tiers'], static fn (array $tier): bool => match ($tier['basis']) {
                'quantity' => true,
                'customer_tier' => $customer !== null && strcasecmp((string) $tier['tier_key'], (string) ($customer['tier'] ?? '')) === 0,
                'customer_type' => $customer !== null && strcasecmp((string) $tier['tier_key'], (string) ($customer['customer_type'] ?? '')) === 0,
                default => false,
            }));
        }
        unset($variant);

        return $product;
    }

    /**
     * Turns listing query parameters into repository filters, checking each one.
     *
     * @param array<string, mixed> $query brand_id, category_id, min_price, max_price, status, attr[...]
     * @return array<string, mixed>
     */
    public static function parseFilters(array $query): array
    {
        $filters = [];
        $errors = [];

        if (isset($query['brand_id']) && $query['brand_id'] !== '') {
            $ids = is_array($query['brand_id']) ? $query['brand_id'] : explode(',', (string) $query['brand_id']);
            foreach ($ids as $id) {
                if (!Uuid::isValid($id)) {
                    $errors['brand_id'] = 'Use brand ids.';
                }
            }
            $filters['brand_ids'] = array_values(array_map('strval', $ids));
        }
        if (isset($query['category_id']) && $query['category_id'] !== '') {
            if (!Uuid::isValid($query['category_id'])) {
                $errors['category_id'] = 'Use a category id.';
            }
            $filters['category_id'] = (string) $query['category_id'];
        }
        foreach (['min_price', 'max_price'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $value = Decimal::parse($query[$key], 2);
                if ($value === null || $value < 0) {
                    $errors[$key] = 'Enter an amount like 150.00.';
                } else {
                    $filters[$key] = Decimal::format($value, 2);
                }
            }
        }
        if (isset($query['status']) && $query['status'] !== '') {
            if (!in_array($query['status'], self::PRODUCT_STATUSES, true)) {
                $errors['status'] = 'Choose draft, active or discontinued.';
            } else {
                $filters['status'] = (string) $query['status'];
            }
        }

        if (isset($query['attr'])) {
            if (!is_array($query['attr']) || count($query['attr']) > 10) {
                $errors['attr'] = 'Use attr[name]=value, with at most 10 attribute filters.';
            } else {
                foreach ($query['attr'] as $key => $rule) {
                    $key = (string) $key;
                    if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) !== 1) {
                        $errors["attr.$key"] = 'Unknown attribute name.';
                        continue;
                    }
                    if (is_array($rule)) {
                        foreach (['min', 'max'] as $bound) {
                            if (isset($rule[$bound])) {
                                if (!is_numeric($rule[$bound])) {
                                    $errors["attr.$key.$bound"] = 'Use a number.';
                                } else {
                                    $filters['attrs'][$key][$bound] = (string) $rule[$bound];
                                }
                            }
                        }
                    } elseif ($rule === 'true' || $rule === 'false') {
                        $filters['attrs'][$key]['bool'] = $rule === 'true';
                    } elseif (is_string($rule) && $rule !== '' && mb_strlen($rule) <= 200) {
                        $filters['attrs'][$key]['eq'] = $rule;
                    }
                }
            }
        }

        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        return $filters;
    }

    /**
     * Lists products. Public callers see only active products with an active variant.
     *
     * @param array<string, mixed> $filters from parseFilters()
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string}
     */
    public function searchProducts(array $filters, ListQuery $query, bool $publicOnly): array
    {
        if ($publicOnly) {
            $filters['visible_only'] = true;
            unset($filters['status']);
        }

        [$rows, $total] = $this->repository->searchProducts($filters, $query);

        return $query->result($rows, $total);
    }

    /**
     * The filter options for a listing: brands, the two-level category tree with counts, the price range and
     * attribute values, counted over the products the current filters match.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function filterOptions(array $filters, ?string $likePattern, bool $publicOnly): array
    {
        if ($publicOnly) {
            $filters['visible_only'] = true;
            unset($filters['status']);
        }

        $facets = $this->repository->facets($filters, $likePattern);

        $counts = [];
        $children = [];
        foreach ($facets['categories'] as $row) {
            $counts[(string) $row['id']] = (int) $row['product_count'];
            if ($row['parent_id'] !== null) {
                $children[(string) $row['parent_id']][] = $row;
            }
        }
        $categories = [];
        foreach ($this->categoryTree() as $top) {
            $kids = [];
            $sum = $counts[(string) $top['id']] ?? 0;
            foreach ($children[(string) $top['id']] ?? [] as $child) {
                $kids[] = ['id' => $child['id'], 'name' => $child['name'], 'slug' => $child['slug'], 'product_count' => (int) $child['product_count']];
                $sum += (int) $child['product_count'];
            }
            if ($sum > 0) {
                $categories[] = ['id' => $top['id'], 'name' => $top['name'], 'slug' => $top['slug'], 'product_count' => $sum, 'children' => $kids];
            }
        }

        $attributes = [];
        foreach ($facets['attributes'] as $row) {
            $key = (string) $row['key'];
            if (ExtensionAttributes::isExtensionKey($key)) {
                continue; // an extension's own data is never a Shop filter
            }
            $attributes[$key] ??= ['key' => $key, 'type' => (string) $row['type'], 'values' => [], 'min' => null, 'max' => null];

            if ($row['type'] === 'number' && is_numeric($row['val'])) {
                $number = (float) $row['val'];
                $attributes[$key]['min'] = $attributes[$key]['min'] === null ? $number : min($attributes[$key]['min'], $number);
                $attributes[$key]['max'] = $attributes[$key]['max'] === null ? $number : max($attributes[$key]['max'], $number);
            } elseif (count($attributes[$key]['values']) < 50) {
                $attributes[$key]['values'][] = ['value' => $row['val'], 'count' => (int) $row['n']];
            }
        }

        return [
            'brands' => array_map(static fn (array $b): array => ['id' => $b['id'], 'name' => $b['name'], 'product_count' => (int) $b['product_count']], $facets['brands']),
            'categories' => $categories,
            'price' => ['min' => $facets['price']['min'], 'max' => $facets['price']['max']],
            'attributes' => array_values($attributes),
        ];
    }

    /**
     * Cleans and checks the product fields common to create and update.
     *
     * @param array<string, mixed> $data
     * @return array{name: string, description: ?string, brand_id: ?string, category_id: ?string, status: string, supplier_id: ?string, attributes: array<string, mixed>}
     */
    private function productFields(array $data, ?string $productId): array
    {
        $errors = [];

        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($data['name'] ?? '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $errors['name'] = 'Enter a product name of 2 to 150 characters.';
        }

        $description = isset($data['description']) ? trim((string) $data['description']) : null;
        if ($description !== null && mb_strlen($description) > 5000) {
            $errors['description'] = 'Keep the description under 5000 characters.';
        }
        $description = $description === '' ? null : $description;

        $status = (string) ($data['status'] ?? 'draft');
        if (!in_array($status, self::PRODUCT_STATUSES, true)) {
            $errors['status'] = 'Choose draft, active or discontinued.';
        }

        $links = [];
        foreach (['brand_id' => 'brands', 'category_id' => 'categories', 'supplier_id' => 'suppliers'] as $field => $table) {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '') {
                $links[$field] = null;
            } elseif (!Uuid::isValid($value) || !$this->repository->idExists($table, (string) $value)) {
                $errors[$field] = 'Choose an existing ' . rtrim($table, 's') . '.';
            } else {
                $links[$field] = (string) $value;
            }
        }
        if (($links['brand_id'] ?? null) !== null && ($this->repository->brandById($links['brand_id'])['status'] ?? '') !== 'active') {
            $errors['brand_id'] = 'That brand is archived.';
        }

        $attributes = $this->attributes($data['attributes'] ?? [], $errors, $links['category_id'] ?? null, $productId);

        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        return [
            'name' => $name,
            'description' => $description,
            'brand_id' => $links['brand_id'] ?? null,
            'category_id' => $links['category_id'] ?? null,
            'status' => $status,
            'supplier_id' => $links['supplier_id'] ?? null,
            'attributes' => $attributes,
        ];
    }

    /**
     * Product attributes are free-form scalars (text, numbers, yes/no). Core checks the shape; the
     * validation.attributes hook adds an organization's own rules and returns field-level errors,
     * named attributes.{key}, which the product form shows beside each field.
     *
     * @param array<string, string> $errors collected errors; this method adds to them
     * @return array<string, mixed>
     */
    private function attributes(mixed $raw, array &$errors, ?string $categoryId, ?string $productId): array
    {
        $clean = [];
        if (!is_array($raw)) {
            $errors['attributes'] = 'Attributes must be a set of name and value pairs.';

            return [];
        }
        if (count($raw) > 50) {
            $errors['attributes'] = 'A product can have at most 50 attributes.';

            return [];
        }

        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) !== 1) {
                $errors["attributes.$key"] = 'Use lower-case letters, digits and underscores for the attribute name.';
            } elseif ($value === null || $value === '') {
                continue;
            } elseif (is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
                $clean[$key] = $value;
            } elseif (is_string($value) && mb_strlen($value) <= 200) {
                $clean[$key] = $value;
            } else {
                $errors["attributes.$key"] = 'Use text (up to 200 characters), a number, or yes/no.';
            }
        }

        $hooked = $this->hooks->filter(Hooks::VALIDATION_ATTRIBUTES, $errors, [
            'attributes' => $clean,
            'category_id' => $categoryId,
            'product_id' => $productId,
        ]);
        $errors = is_array($hooked) ? array_map('strval', $hooked) : $errors;

        return $clean;
    }

    // ------------------------------------------------------------------ variants

    /**
     * @param array<string, mixed> $data
     * @return array{id: string}
     */
    public function createVariant(string $productId, array $data, ?string $actorId): array
    {
        $this->repository->productById($productId) ?? throw HttpException::notFound('That product could not be found.');

        return Database::transaction($this->pdo, function () use ($productId, $data, $actorId): array {
            $v = $this->variantFields($data, null);
            $id = $this->repository->createVariant($productId, $v);
            $this->repository->addPriceHistory($id, $v['retail_price'], $v['trade_price'], null, $actorId);
            $this->audit->organization($this->pdo, 'variant.created', $actorId, 'variant', $id, ['sku' => $v['sku'], 'retail_price' => $v['retail_price']]);

            return ['id' => $id];
        });
    }

    /** @param array<string, mixed> $data */
    public function updateVariant(string $id, array $data, ?string $actorId): void
    {
        $existing = $this->repository->variantById($id) ?? throw HttpException::notFound('That variant could not be found.');

        Database::transaction($this->pdo, function () use ($id, $data, $actorId, $existing): void {
            // Fields that were not sent keep their current value.
            $current = [];
            foreach (['sku', 'barcode', 'name', 'unit', 'unit_size', 'allow_decimal_qty', 'qty_step', 'min_qty', 'track_batches', 'retail_price', 'trade_price', 'reorder_level', 'status'] as $key) {
                $current[$key] = $existing[$key];
            }
            $v = $this->variantFields($data + $current, $id);
            $this->repository->updateVariant($id, $v);

            $oldRetail = Decimal::parse((string) $existing['retail_price'], 2);
            $oldTrade = $existing['trade_price'] === null ? null : Decimal::parse((string) $existing['trade_price'], 2);
            if ($oldRetail !== Decimal::parse($v['retail_price'], 2) || $oldTrade !== ($v['trade_price'] === null ? null : Decimal::parse($v['trade_price'], 2))) {
                $this->repository->addPriceHistory($id, $v['retail_price'], $v['trade_price'], null, $actorId);
                $this->audit->organization($this->pdo, 'price.changed', $actorId, 'variant', $id, [
                    'sku' => $v['sku'],
                    'retail_from' => $existing['retail_price'],
                    'retail_to' => $v['retail_price'],
                    'trade_from' => $existing['trade_price'],
                    'trade_to' => $v['trade_price'],
                ]);
            }
            $this->audit->organization($this->pdo, 'variant.updated', $actorId, 'variant', $id, ['sku' => $v['sku']]);
        });
    }

    /**
     * Cleans and checks a variant. Decimal quantities need allow_decimal_qty; whole-number variants always
     * have a step of 1 and a whole-number minimum.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function variantFields(array $data, ?string $variantId): array
    {
        $errors = [];

        $sku = trim((string) ($data['sku'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,39}$/', $sku) !== 1) {
            $errors['sku'] = 'Use 1 to 40 letters, digits, dots, dashes or underscores.';
        } else {
            $other = $this->repository->variantIdBySku($sku);
            if ($other !== null && $other !== $variantId) {
                $errors['sku'] = 'This SKU is already used.';
            }
        }

        $barcode = isset($data['barcode']) ? trim((string) $data['barcode']) : '';
        if ($barcode !== '') {
            if (preg_match('/^[A-Za-z0-9\-]{4,32}$/', $barcode) !== 1) {
                $errors['barcode'] = 'Use 4 to 32 letters, digits or dashes.';
            } else {
                $other = $this->repository->variantIdByBarcode($barcode);
                if ($other !== null && $other !== $variantId) {
                    $errors['barcode'] = 'This barcode is already used.';
                }
            }
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Enter a variant name (up to 100 characters).';
        }

        $unit = trim((string) ($data['unit'] ?? 'pc'));
        if ($unit === '' || mb_strlen($unit) > 20) {
            $errors['unit'] = 'Enter a unit such as pc, kg or L.';
        }

        $retail = Decimal::parse($data['retail_price'] ?? null, 2);
        if ($retail === null || $retail < 0) {
            $errors['retail_price'] = 'Enter a price like 249.00.';
        }

        $trade = null;
        if (isset($data['trade_price']) && $data['trade_price'] !== '') {
            $trade = Decimal::parse($data['trade_price'], 2);
            if ($trade === null || $trade < 0) {
                $errors['trade_price'] = 'Enter a price like 199.00, or leave it empty.';
            }
        }

        $unitSize = null;
        if (isset($data['unit_size']) && $data['unit_size'] !== '') {
            $unitSize = Decimal::parse($data['unit_size'], 3);
            if ($unitSize === null || $unitSize <= 0) {
                $errors['unit_size'] = 'Enter a size greater than zero.';
            }
        }

        $reorder = Decimal::parse($data['reorder_level'] ?? '0', 3);
        if ($reorder === null || $reorder < 0) {
            $errors['reorder_level'] = 'Enter a quantity of zero or more.';
        }

        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, self::VARIANT_STATUSES, true)) {
            $errors['status'] = 'Choose active, inactive or discontinued.';
        }

        $decimals = filter_var($data['allow_decimal_qty'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $step = Decimal::parse($data['qty_step'] ?? ($decimals ? '0.001' : '1'), 3);
        $minimum = Decimal::parse($data['min_qty'] ?? ($decimals ? '0.001' : '1'), 3);
        if ($decimals) {
            if ($step === null || $step <= 0) {
                $errors['qty_step'] = 'Enter a step greater than zero, such as 0.250.';
            }
            if ($minimum === null || $minimum <= 0) {
                $errors['min_qty'] = 'Enter a minimum greater than zero.';
            } elseif ($step !== null && $step > 0 && $minimum % $step !== 0) {
                $errors['min_qty'] = 'The minimum must be a multiple of the step.';
            }
        } else {
            $step = 1000;
            if ($minimum === null || $minimum < 1000 || $minimum % 1000 !== 0) {
                $errors['min_qty'] = 'Enter a whole number of 1 or more.';
            }
        }

        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        return [
            'sku' => $sku,
            'barcode' => $barcode === '' ? null : $barcode,
            'name' => $name,
            'unit' => $unit,
            'unit_size' => $unitSize === null ? null : Decimal::format($unitSize, 3),
            'allow_decimal_qty' => $decimals,
            'qty_step' => Decimal::format((int) $step, 3),
            'min_qty' => Decimal::format((int) $minimum, 3),
            'track_batches' => filter_var($data['track_batches'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'retail_price' => Decimal::format((int) $retail, 2),
            'trade_price' => $trade === null ? null : Decimal::format($trade, 2),
            'reorder_level' => Decimal::format((int) $reorder, 3),
            'status' => $status,
        ];
    }

    // ------------------------------------------------------------------ price tiers

    /**
     * Creates a price tier, or updates one when $tierId is given. basis is "quantity" (applies from min_qty),
     * "customer_tier" or "customer_type" (applies to matching customers, tier_key names the tier or type).
     *
     * @param array<string, mixed> $data
     * @return array{id: string}
     */
    public function saveTier(string $variantId, array $data, ?string $tierId, ?string $actorId): array
    {
        $variant = $this->repository->variantById($variantId) ?? throw HttpException::notFound('That variant could not be found.');
        $existing = $tierId === null ? null : $this->repository->tierById($tierId);
        if ($tierId !== null && ($existing === null || $existing['variant_id'] !== $variantId)) {
            throw HttpException::notFound('That price tier could not be found.');
        }

        $errors = [];
        $basis = (string) ($existing['basis'] ?? $data['basis'] ?? '');
        if (!in_array($basis, ['quantity', 'customer_tier', 'customer_type'], true)) {
            $errors['basis'] = 'Choose quantity or customer tier.';
        }

        $tierKey = trim((string) ($data['tier_key'] ?? ''));
        if ($basis === 'quantity') {
            $tierKey = '';
        } elseif ($basis !== '' && ($tierKey === '' || mb_strlen($tierKey) > 40)) {
            $errors['tier_key'] = 'Enter the customer tier, up to 40 characters.';
        }

        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 60) {
            $errors['label'] = 'Enter a label (up to 60 characters).';
        }

        $minQty = Decimal::parse($data['min_qty'] ?? '1', 3);
        if ($minQty === null || $minQty <= 0) {
            $errors['min_qty'] = 'Enter a minimum quantity greater than zero.';
        }

        $price = Decimal::parse($data['price'] ?? null, 2);
        $retail = Decimal::parse((string) $variant['retail_price'], 2) ?? 0;
        if ($price === null || $price < 0) {
            $errors['price'] = 'Enter a price like 199.00.';
        } elseif ($price > $retail) {
            $errors['price'] = 'A tier price cannot be higher than the retail price.';
        }

        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        return Database::transaction($this->pdo, function () use ($variantId, $variant, $tierId, $basis, $tierKey, $label, $minQty, $price, $actorId): array {
            if ($tierId === null) {
                $id = $this->repository->createTier($variantId, $basis, $tierKey === '' ? null : $tierKey, $label, Decimal::format((int) $minQty, 3), Decimal::format((int) $price, 2));
            } else {
                $id = $tierId;
                $this->repository->updateTier($id, $tierKey === '' ? null : $tierKey, $label, Decimal::format((int) $minQty, 3), Decimal::format((int) $price, 2));
            }
            $this->audit->organization($this->pdo, 'price.tier_saved', $actorId, 'variant', $variantId, [
                'sku' => $variant['sku'], 'basis' => $basis, 'tier_key' => $tierKey, 'price' => Decimal::format((int) $price, 2),
            ]);

            return ['id' => $id];
        });
    }

    public function deleteTier(string $tierId, ?string $actorId): void
    {
        $tier = $this->repository->tierById($tierId) ?? throw HttpException::notFound('That price tier could not be found.');
        $this->repository->deleteTier($tierId);
        $this->audit->organization($this->pdo, 'price.tier_deleted', $actorId, 'variant', (string) $tier['variant_id'], ['label' => $tier['label']]);
    }

    // ------------------------------------------------------------------ photos

    /** Links an uploaded photo to a product. The first photo becomes the primary one. */
    public function attachImage(string $productId, string $mediaId, ?string $variantId, bool $makePrimary): array
    {
        $this->repository->productById($productId) ?? throw HttpException::notFound('That product could not be found.');
        if (!Uuid::isValid($mediaId) || !$this->repository->mediaExists($mediaId)) {
            throw HttpException::unprocessable(['media_id' => 'That image does not exist. Upload it first.']);
        }
        if ($variantId !== null && $variantId !== '') {
            $variant = Uuid::isValid($variantId) ? $this->repository->variantById($variantId) : null;
            if ($variant === null || $variant['product_id'] !== $productId) {
                throw HttpException::unprocessable(['variant_id' => 'That variant does not belong to this product.']);
            }
        } else {
            $variantId = null;
        }

        return Database::transaction($this->pdo, function () use ($productId, $mediaId, $variantId, $makePrimary): array {
            $existing = $this->repository->imagesForProduct($productId);
            $primary = $makePrimary || $existing === [];
            if ($primary) {
                $this->repository->clearPrimary($productId);
            }
            $id = $this->repository->addImage($productId, $variantId, $mediaId, $this->repository->nextImageOrder($productId), $primary);

            return ['id' => $id];
        });
    }

    public function setPrimaryImage(string $imageId): void
    {
        $image = $this->repository->imageById($imageId) ?? throw HttpException::notFound('That photo could not be found.');

        Database::transaction($this->pdo, function () use ($image, $imageId): void {
            $this->repository->clearPrimary((string) $image['product_id']);
            $this->repository->markPrimary($imageId);
        });
    }

    /**
     * Sets the order of a product's photos. $orderedImageIds must list every photo of the product once.
     *
     * @param list<string> $orderedImageIds
     */
    public function reorderImages(string $productId, array $orderedImageIds): void
    {
        $current = array_map(static fn (array $i): string => (string) $i['id'], $this->repository->imagesForProduct($productId));
        $given = array_values(array_map('strval', $orderedImageIds));
        $sortedCurrent = $current;
        $sortedGiven = $given;
        sort($sortedCurrent);
        sort($sortedGiven);
        if ($sortedCurrent !== $sortedGiven) {
            throw HttpException::unprocessable(['image_ids' => 'List every photo of the product exactly once.']);
        }

        Database::transaction($this->pdo, function () use ($given): void {
            foreach ($given as $position => $imageId) {
                $this->repository->setImageOrder($imageId, $position + 1);
            }
        });
    }

    /** Removes the photo link (the file stays in the media library). If it was primary, the next photo becomes primary. */
    public function removeImage(string $imageId): void
    {
        $image = $this->repository->imageById($imageId) ?? throw HttpException::notFound('That photo could not be found.');

        Database::transaction($this->pdo, function () use ($image, $imageId): void {
            $this->repository->deleteImage($imageId);
            if ((bool) $image['is_primary']) {
                $remaining = $this->repository->imagesForProduct((string) $image['product_id']);
                if ($remaining !== []) {
                    $this->repository->markPrimary((string) $remaining[0]['id']);
                }
            }
        });
    }
}
