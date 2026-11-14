<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\SqlWhere;

/** All SQL for the catalog in an organization database: brands, categories, products, variants, tiers, images. */
final class CatalogRepository
{
    private const PRODUCT_FROM = 'FROM products p LEFT JOIN brands b ON b.id = p.brand_id LEFT JOIN categories c ON c.id = p.category_id';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    // ------------------------------------------------------------------ generic helpers

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    // ------------------------------------------------------------------ brands

    /** @return array<string, mixed>|null */
    public function brandById(string $id): ?array
    {
        return $this->row('SELECT id, name, manufacturer, logo_media_id, status FROM brands WHERE id = ?', [$id]);
    }

    public function brandIdByName(string $name): ?string
    {
        $id = $this->scalar('SELECT id FROM brands WHERE lower(name) = lower(?)', [$name]);

        return $id === false ? null : (string) $id;
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function listBrands(ListQuery $query, bool $includeArchived): array
    {
        $where = new SqlWhere();
        if (!$includeArchived) {
            $where->add("b.status = 'active'");
        }
        if (($pattern = $query->likePattern()) !== null) {
            $where->add('b.name ILIKE ?', $pattern);
        }

        $total = (int) $this->scalar('SELECT COUNT(*) FROM brands b ' . $where->sql(), $where->params());
        $rows = $this->rows(
            'SELECT b.id, b.name, b.manufacturer, b.logo_media_id, b.status, '
            . "(SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id AND p.status <> 'discontinued') AS product_count "
            . 'FROM brands b ' . $where->sql() . ' ORDER BY ' . $query->orderBy
            . ' LIMIT ' . $query->perPage . ' OFFSET ' . $query->offset(),
            $where->params(),
        );

        return [$rows, $total];
    }

    public function createBrand(string $name, string $manufacturer): string
    {
        return (string) $this->scalar('INSERT INTO brands (name, manufacturer) VALUES (?, ?) RETURNING id', [$name, $manufacturer]);
    }

    public function renameBrand(string $id, string $name): void
    {
        $this->pdo->prepare('UPDATE brands SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    public function setBrandStatus(string $id, string $status): void
    {
        $this->pdo->prepare('UPDATE brands SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    public function setBrandLogo(string $id, ?string $mediaId): void
    {
        $this->pdo->prepare('UPDATE brands SET logo_media_id = ? WHERE id = ?')->execute([$mediaId, $id]);
    }

    /** Products that still use the brand (anything not discontinued). */
    public function brandUsage(string $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM products WHERE brand_id = ? AND status <> 'discontinued'", [$id]);
    }

    // ------------------------------------------------------------------ categories

    /** @return array<string, mixed>|null */
    public function categoryById(string $id): ?array
    {
        return $this->row('SELECT id, parent_id, name, slug, sort_order, status FROM categories WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function allCategories(bool $includeArchived): array
    {
        return $this->rows(
            'SELECT c.id, c.parent_id, c.name, c.slug, c.sort_order, c.status FROM categories c '
            . ($includeArchived ? '' : "WHERE c.status = 'active' ") . 'ORDER BY c.sort_order, c.name',
        );
    }

    public function categorySlugExists(string $slug): bool
    {
        return $this->scalar('SELECT 1 FROM categories WHERE slug = ?', [$slug]) !== false;
    }

    public function categoryNameExists(?string $parentId, string $name, ?string $exceptId = null): bool
    {
        return $this->scalar(
            'SELECT 1 FROM categories WHERE lower(name) = lower(?) AND parent_id IS NOT DISTINCT FROM CAST(? AS uuid) '
            . "AND status = 'active' AND (CAST(? AS uuid) IS NULL OR id <> CAST(? AS uuid))",
            [$name, $parentId, $exceptId, $exceptId],
        ) !== false;
    }

    public function createCategory(?string $parentId, string $name, string $slug, int $sortOrder): string
    {
        return (string) $this->scalar(
            'INSERT INTO categories (parent_id, name, slug, sort_order) VALUES (?, ?, ?, ?) RETURNING id',
            [$parentId, $name, $slug, $sortOrder],
        );
    }

    public function renameCategory(string $id, string $name): void
    {
        $this->pdo->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    public function setCategoryStatus(string $id, string $status): void
    {
        $this->pdo->prepare('UPDATE categories SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    public function categoryActiveChildren(string $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM categories WHERE parent_id = ? AND status = 'active'", [$id]);
    }

    public function categoryUsage(string $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM products WHERE category_id = ? AND status <> 'discontinued'", [$id]);
    }

    // ------------------------------------------------------------------ products

    /** @return array<string, mixed>|null */
    public function productById(string $id): ?array
    {
        return $this->row(
            'SELECT p.id, p.name, p.slug, p.description, p.brand_id, b.name AS brand_name, p.category_id, c.name AS category_name, '
            . 'p.status, p.discontinued_on, p.supplier_id, p.attributes::text AS attributes, p.created_at, p.updated_at '
            . self::PRODUCT_FROM . ' WHERE p.id = ?',
            [$id],
        );
    }

    /** @return array<string, mixed>|null */
    public function productBySlug(string $slug): ?array
    {
        $id = $this->scalar('SELECT id FROM products WHERE slug = ?', [$slug]);

        return $id === false ? null : $this->productById((string) $id);
    }

    public function productSlugExists(string $slug): bool
    {
        return $this->scalar('SELECT 1 FROM products WHERE slug = ?', [$slug]) !== false;
    }

    /** @param array<string, mixed> $attributes */
    public function createProduct(string $name, string $slug, ?string $description, ?string $brandId, ?string $categoryId, string $status, ?string $supplierId, array $attributes, ?string $createdBy): string
    {
        return (string) $this->scalar(
            'INSERT INTO products (name, slug, description, brand_id, category_id, status, supplier_id, attributes, created_by) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), ?) RETURNING id',
            [$name, $slug, $description, $brandId, $categoryId, $status, $supplierId, json_encode((object) $attributes, JSON_THROW_ON_ERROR), $createdBy],
        );
    }

    /** @param array<string, mixed> $attributes */
    public function updateProduct(string $id, string $name, ?string $description, ?string $brandId, ?string $categoryId, ?string $supplierId, array $attributes): void
    {
        $this->pdo->prepare(
            'UPDATE products SET name = ?, description = ?, brand_id = ?, category_id = ?, supplier_id = ?, attributes = CAST(? AS jsonb) WHERE id = ?',
        )->execute([$name, $description, $brandId, $categoryId, $supplierId, json_encode((object) $attributes, JSON_THROW_ON_ERROR), $id]);
    }

    public function setProductStatus(string $id, string $status): void
    {
        $this->pdo->prepare(
            "UPDATE products SET status = ?, discontinued_on = CASE WHEN ? = 'discontinued' THEN (now() AT TIME ZONE 'Asia/Manila')::date ELSE NULL END WHERE id = ?",
        )->execute([$status, $status, $id]);
    }

    /**
     * Filters accepted: q, brand_ids, category_id, min_price, max_price, status, visible_only, attrs.
     *
     * @param array<string, mixed> $filters
     */
    private function productWhere(array $filters, ?string $likePattern): SqlWhere
    {
        $where = new SqlWhere();

        if (!empty($filters['visible_only'])) {
            $where->add("p.status = 'active' AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.status = 'active')");
        }
        if (!empty($filters['status'])) {
            $where->add('p.status = ?', $filters['status']);
        }
        if ($likePattern !== null) {
            $where->add(
                'p.name ILIKE ? OR b.name ILIKE ? OR EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id '
                . 'AND (v.sku ILIKE ? OR v.name ILIKE ? OR v.barcode ILIKE ?))',
                $likePattern, $likePattern, $likePattern, $likePattern, $likePattern,
            );
        }
        if (!empty($filters['brand_ids'])) {
            $where->addIn('p.brand_id', $filters['brand_ids']);
        }
        if (!empty($filters['category_id'])) {
            $where->add('p.category_id = ? OR p.category_id IN (SELECT id FROM categories WHERE parent_id = ?)', $filters['category_id'], $filters['category_id']);
        }
        if (isset($filters['min_price']) || isset($filters['max_price'])) {
            $clause = "EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.status = 'active'";
            $params = [];
            if (isset($filters['min_price'])) {
                $clause .= ' AND v.retail_price >= ?';
                $params[] = $filters['min_price'];
            }
            if (isset($filters['max_price'])) {
                $clause .= ' AND v.retail_price <= ?';
                $params[] = $filters['max_price'];
            }
            $where->add($clause . ')', ...$params);
        }

        foreach ((array) ($filters['attrs'] ?? []) as $key => $rule) {
            if (isset($rule['eq'])) {
                $where->add('p.attributes ->> CAST(? AS text) = ?', $key, (string) $rule['eq']);
            }
            if (isset($rule['bool'])) {
                $where->add('p.attributes -> CAST(? AS text) = CAST(? AS jsonb)', $key, $rule['bool'] ? 'true' : 'false');
            }
            foreach (['min' => '>=', 'max' => '<='] as $bound => $operator) {
                if (isset($rule[$bound])) {
                    // The CASE keeps text values in the same key from ever being cast to a number.
                    $where->add(
                        "CASE WHEN jsonb_typeof(p.attributes -> CAST(? AS text)) = 'number' THEN (p.attributes ->> CAST(? AS text))::numeric END {$operator} CAST(? AS numeric)",
                        $key, $key, $rule[$bound],
                    );
                }
            }
        }

        return $where;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function searchProducts(array $filters, ListQuery $query): array
    {
        $where = $this->productWhere($filters, $query->likePattern());

        $total = (int) $this->scalar('SELECT COUNT(*) ' . self::PRODUCT_FROM . ' ' . $where->sql(), $where->params());

        $rows = $this->rows(
            'SELECT p.id, p.name, p.slug, p.status, p.brand_id, b.name AS brand_name, p.category_id, c.name AS category_name, p.created_at, '
            . "(SELECT MIN(v.retail_price) FROM product_variants v WHERE v.product_id = p.id AND v.status = 'active') AS price_from, "
            . "(SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.status <> 'discontinued') AS variant_count, "
            . '(SELECT pi.media_id FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary LIMIT 1) AS primary_media_id, '
            . "EXISTS (SELECT 1 FROM product_variants v JOIN variant_on_hand h ON h.variant_id = v.id WHERE v.product_id = p.id AND v.status = 'active' AND h.on_hand > 0) AS in_stock "
            . self::PRODUCT_FROM . ' ' . $where->sql() . ' ORDER BY ' . $query->orderBy
            . ' LIMIT ' . $query->perPage . ' OFFSET ' . $query->offset(),
            $where->params(),
        );

        return [$rows, $total];
    }

    /**
     * Options for a listing's filter panel, counted over the products the current filters match.
     *
     * @param array<string, mixed> $filters
     * @return array{brands: list<array<string, mixed>>, categories: list<array<string, mixed>>, price: array<string, mixed>, attributes: list<array<string, mixed>>}
     */
    public function facets(array $filters, ?string $likePattern): array
    {
        $where = $this->productWhere($filters, $likePattern);
        $sql = $where->sql();
        $params = $where->params();

        $brands = $this->rows(
            'SELECT b.id, b.name, COUNT(*) AS product_count ' . self::PRODUCT_FROM . ' ' . ($sql === '' ? 'WHERE b.id IS NOT NULL' : $sql . ' AND b.id IS NOT NULL')
            . ' GROUP BY b.id, b.name ORDER BY b.name',
            $params,
        );

        $categories = $this->rows(
            'SELECT c.id, c.parent_id, c.name, c.slug, COUNT(*) AS product_count ' . self::PRODUCT_FROM . ' ' . ($sql === '' ? 'WHERE c.id IS NOT NULL' : $sql . ' AND c.id IS NOT NULL')
            . ' GROUP BY c.id, c.parent_id, c.name, c.slug, c.sort_order ORDER BY c.sort_order, c.name',
            $params,
        );

        $price = $this->row(
            'SELECT MIN(v.retail_price) AS min, MAX(v.retail_price) AS max ' . self::PRODUCT_FROM
            . " JOIN product_variants v ON v.product_id = p.id AND v.status = 'active' " . $sql,
            $params,
        ) ?? ['min' => null, 'max' => null];

        $attributeRows = $this->rows(
            "SELECT a.key, jsonb_typeof(a.value) AS type, a.value #>> '{}' AS val, COUNT(*) AS n "
            . self::PRODUCT_FROM . ', LATERAL jsonb_each(p.attributes) AS a ' . $sql
            . ' GROUP BY a.key, jsonb_typeof(a.value), a.value #>> \'{}\' ORDER BY a.key, val',
            $params,
        );

        return ['brands' => $brands, 'categories' => $categories, 'price' => $price, 'attributes' => $attributeRows];
    }

    // ------------------------------------------------------------------ variants and tiers

    private const VARIANT_COLUMNS = 'id, product_id, sku, barcode, name, unit, unit_size, allow_decimal_qty, qty_step, min_qty, track_batches, '
        . 'retail_price, trade_price, avg_cost, cost_updated_at, reorder_level, status';

    /** @return list<array<string, mixed>> */
    public function variantsForProduct(string $productId, bool $activeOnly = false): array
    {
        return $this->rows(
            'SELECT ' . self::VARIANT_COLUMNS . ' FROM product_variants WHERE product_id = ?'
            . ($activeOnly ? " AND status = 'active'" : " AND status <> 'discontinued'") . ' ORDER BY created_at, name',
            [$productId],
        );
    }

    /** @return array<string, mixed>|null */
    public function variantById(string $id): ?array
    {
        return $this->row('SELECT ' . self::VARIANT_COLUMNS . ' FROM product_variants WHERE id = ?', [$id]);
    }

    /**
     * Variants with their product's name and status, for pricing a cart.
     *
     * @param list<string> $ids
     * @return array<string, array<string, mixed>> keyed by variant id
     */
    public function variantsWithProducts(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $rows = $this->rows(
            'SELECT v.id, v.product_id, v.sku, v.barcode, v.name, v.unit, v.allow_decimal_qty, v.qty_step, v.min_qty, v.track_batches, '
            . 'v.retail_price, v.avg_cost, v.cost_updated_at, v.status, p.name AS product_name, p.status AS product_status '
            . 'FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids,
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['id']] = $row;
        }

        return $map;
    }

    /** @return array<string, mixed>|null */
    public function discountRuleByKey(string $key): ?array
    {
        return $this->row('SELECT id, key, label, percent, vat_exempt, active FROM discount_rules WHERE key = ?', [$key]);
    }

    public function variantIdBySku(string $sku): ?string
    {
        $id = $this->scalar('SELECT id FROM product_variants WHERE lower(sku) = lower(?)', [$sku]);

        return $id === false ? null : (string) $id;
    }

    public function variantIdByBarcode(string $barcode): ?string
    {
        $id = $this->scalar('SELECT id FROM product_variants WHERE barcode = ?', [$barcode]);

        return $id === false ? null : (string) $id;
    }

    /** @param array<string, mixed> $v */
    public function createVariant(string $productId, array $v): string
    {
        return (string) $this->scalar(
            'INSERT INTO product_variants (product_id, sku, barcode, name, unit, unit_size, allow_decimal_qty, qty_step, min_qty, '
            . 'track_batches, retail_price, trade_price, reorder_level, status) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $productId, $v['sku'], $v['barcode'], $v['name'], $v['unit'], $v['unit_size'], $this->bool($v['allow_decimal_qty']),
                $v['qty_step'], $v['min_qty'], $this->bool($v['track_batches']), $v['retail_price'], $v['trade_price'], $v['reorder_level'], $v['status'],
            ],
        );
    }

    /** @param array<string, mixed> $v */
    public function updateVariant(string $id, array $v): void
    {
        $this->pdo->prepare(
            'UPDATE product_variants SET sku = ?, barcode = ?, name = ?, unit = ?, unit_size = ?, allow_decimal_qty = ?, qty_step = ?, min_qty = ?, '
            . 'track_batches = ?, retail_price = ?, trade_price = ?, reorder_level = ?, status = ? WHERE id = ?',
        )->execute([
            $v['sku'], $v['barcode'], $v['name'], $v['unit'], $v['unit_size'], $this->bool($v['allow_decimal_qty']),
            $v['qty_step'], $v['min_qty'], $this->bool($v['track_batches']), $v['retail_price'], $v['trade_price'], $v['reorder_level'], $v['status'], $id,
        ]);
    }

    public function addPriceHistory(string $variantId, string $retailPrice, ?string $tradePrice, ?string $cost, ?string $createdBy): void
    {
        $this->pdo->prepare(
            "INSERT INTO price_history (variant_id, effective_on, retail_price, trade_price, cost, created_by) "
            . "VALUES (?, (now() AT TIME ZONE 'Asia/Manila')::date, ?, ?, ?, ?)",
        )->execute([$variantId, $retailPrice, $tradePrice, $cost, $createdBy]);
    }

    /**
     * @param list<string> $variantIds
     * @return list<array<string, mixed>>
     */
    public function tiersForVariants(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return $this->rows(
            'SELECT id, variant_id, basis, tier_key, label, min_qty, price FROM price_tiers WHERE variant_id IN ('
            . implode(', ', array_fill(0, count($variantIds), '?')) . ') ORDER BY variant_id, basis, min_qty, price',
            array_values($variantIds),
        );
    }

    /** @return array<string, mixed>|null */
    public function tierById(string $id): ?array
    {
        return $this->row('SELECT id, variant_id, basis, tier_key, label, min_qty, price FROM price_tiers WHERE id = ?', [$id]);
    }

    public function createTier(string $variantId, string $basis, ?string $tierKey, string $label, string $minQty, string $price): string
    {
        return (string) $this->scalar(
            'INSERT INTO price_tiers (variant_id, basis, tier_key, label, min_qty, price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            [$variantId, $basis, $tierKey, $label, $minQty, $price],
        );
    }

    public function updateTier(string $id, ?string $tierKey, string $label, string $minQty, string $price): void
    {
        $this->pdo->prepare('UPDATE price_tiers SET tier_key = ?, label = ?, min_qty = ?, price = ? WHERE id = ?')
            ->execute([$tierKey, $label, $minQty, $price, $id]);
    }

    public function deleteTier(string $id): void
    {
        $this->pdo->prepare('DELETE FROM price_tiers WHERE id = ?')->execute([$id]);
    }

    // ------------------------------------------------------------------ images

    /** @return list<array<string, mixed>> */
    public function imagesForProduct(string $productId): array
    {
        return $this->rows(
            'SELECT id, product_id, variant_id, media_id, sort_order, is_primary FROM product_images WHERE product_id = ? ORDER BY sort_order, id',
            [$productId],
        );
    }

    /** @return array<string, mixed>|null */
    public function imageById(string $id): ?array
    {
        return $this->row('SELECT id, product_id, variant_id, media_id, sort_order, is_primary FROM product_images WHERE id = ?', [$id]);
    }

    public function addImage(string $productId, ?string $variantId, string $mediaId, int $sortOrder, bool $isPrimary): string
    {
        return (string) $this->scalar(
            'INSERT INTO product_images (product_id, variant_id, media_id, sort_order, is_primary) VALUES (?, ?, ?, ?, ?) RETURNING id',
            [$productId, $variantId, $mediaId, $sortOrder, $this->bool($isPrimary)],
        );
    }

    public function clearPrimary(string $productId): void
    {
        $this->pdo->prepare('UPDATE product_images SET is_primary = false WHERE product_id = ? AND is_primary')->execute([$productId]);
    }

    public function markPrimary(string $imageId): void
    {
        $this->pdo->prepare('UPDATE product_images SET is_primary = true WHERE id = ?')->execute([$imageId]);
    }

    public function setImageOrder(string $imageId, int $sortOrder): void
    {
        $this->pdo->prepare('UPDATE product_images SET sort_order = ? WHERE id = ?')->execute([$sortOrder, $imageId]);
    }

    public function deleteImage(string $imageId): void
    {
        $this->pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
    }

    public function nextImageOrder(string $productId): int
    {
        return (int) $this->scalar('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM product_images WHERE product_id = ?', [$productId]);
    }

    public function mediaExists(string $mediaId): bool
    {
        return $this->scalar('SELECT 1 FROM media WHERE id = ?', [$mediaId]) !== false;
    }

    /** Whether a row with this id exists. Only these tables can be asked about (the name goes into the SQL). */
    public function idExists(string $table, string $id): bool
    {
        if (!in_array($table, ['suppliers', 'brands', 'categories', 'media', 'products'], true)) {
            throw new \InvalidArgumentException('Unsupported table.');
        }

        return $this->scalar('SELECT 1 FROM ' . $table . ' WHERE id = ?', [$id]) !== false;
    }

    /**
     * Stock on hand across all branches, per variant.
     *
     * @param list<string> $variantIds
     * @return array<string, string>
     */
    public function onHandByVariant(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $rows = $this->rows(
            'SELECT variant_id, SUM(quantity_delta) AS on_hand FROM stock_movements WHERE variant_id IN ('
            . implode(', ', array_fill(0, count($variantIds), '?')) . ') GROUP BY variant_id',
            array_values($variantIds),
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['variant_id']] = (string) $row['on_hand'];
        }

        return $map;
    }

    public function variantCount(string $productId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND status = 'active'", [$productId]);
    }

    /** PostgreSQL wants the words true and false; PDO would send a PHP false as an empty string. */
    private function bool(mixed $value): string
    {
        return $value ? 'true' : 'false';
    }
}
