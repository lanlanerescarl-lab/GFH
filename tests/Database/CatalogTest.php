<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\Request;
use Gfh\Core\Service\CatalogService;
use Gfh\Core\Support\ListQuery;
use Gfh\Tests\Support\DatabaseTestCase;

final class CatalogTest extends DatabaseTestCase
{
    private function catalog(): CatalogService
    {
        return $this->shop->services->catalog();
    }

    /** @param array<string, mixed> $query */
    private function listQuery(array $query = []): ListQuery
    {
        return ListQuery::fromRequest(
            new Request('GET', '/x', $query),
            ['name' => 'p.name', 'price' => 'price_from', 'newest' => 'p.created_at'],
            'name',
            ['brand_id', 'category_id', 'min_price', 'max_price', 'status', 'attr'],
        );
    }

    /** @return list<string> product names found by a public listing */
    private function names(array $query = []): array
    {
        $query += ['sort' => 'name'];
        $listing = $this->listQuery($query);
        $result = $this->catalog()->searchProducts(CatalogService::parseFilters($listing->filters), $listing, true);

        return array_map(static fn (array $row): string => (string) $row['name'], $result['items']);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{id: string, slug: string}
     */
    private function product(string $name, ?string $brand, ?string $category, array $attributes, string $price = '1000.00', string $status = 'active'): array
    {
        return $this->catalog()->createProduct([
            'name' => $name, 'status' => $status, 'brand_id' => $brand, 'category_id' => $category, 'attributes' => $attributes,
            'variants' => [['sku' => strtoupper(substr(md5($name), 0, 8)), 'name' => 'Default', 'retail_price' => $price]],
        ], null);
    }

    public function testBrandNamesMustNotStartWithGfhAndMustBeUnique(): void
    {
        $this->assertArrayHasKey('name', $this->assertHttpStatus(422, fn () => $this->catalog()->createBrand('GFH Kitchen', null, null))->fields());
        $this->assertArrayHasKey('name', $this->assertHttpStatus(422, fn () => $this->catalog()->createBrand('  gfh', null, null))->fields());

        $brand = $this->catalog()->createBrand('Nora Kitchen', null, null)['id'];
        $this->assertArrayHasKey('name', $this->assertHttpStatus(422, fn () => $this->catalog()->createBrand('NORA KITCHEN', null, null))->fields());
        $this->assertArrayHasKey('name', $this->assertHttpStatus(422, fn () => $this->catalog()->renameBrand($brand, 'GFH Nora', null))->fields());

        $this->catalog()->renameBrand($brand, 'Nora Home', null);
        $this->assertSame('GFH', (string) $this->shop->scalar('SELECT manufacturer FROM brands WHERE id = ?', [$brand]));
        $this->assertSame('Nora Home', (string) $this->shop->scalar('SELECT name FROM brands WHERE id = ?', [$brand]));
    }

    public function testABrandInUseCannotBeArchived(): void
    {
        $brand = $this->catalog()->createBrand('Nora Kitchen', null, null)['id'];
        $product = $this->product('Rice Cooker', $brand, null, []);

        $this->assertHttpStatus(409, fn () => $this->catalog()->archiveBrand($brand, null));

        $this->catalog()->setProductStatus($product['id'], 'discontinued', null);
        $this->catalog()->archiveBrand($brand, null);
        $this->assertSame('archived', $this->shop->scalar('SELECT status FROM brands WHERE id = ?', [$brand]));
        $this->catalog()->restoreBrand($brand, null);
        $this->assertSame('active', $this->shop->scalar('SELECT status FROM brands WHERE id = ?', [$brand]));
    }

    public function testCategoriesGoTwoLevelsDeepAndAreProtectedWhileInUse(): void
    {
        $top = $this->catalog()->createCategory(null, 'Beverages', null);
        $child = $this->catalog()->createCategory($top['id'], 'Coffee', null);

        $this->assertArrayHasKey('parent_id', $this->assertHttpStatus(422, fn () => $this->catalog()->createCategory($child['id'], 'Single Origin', null))->fields());
        $this->assertArrayHasKey('name', $this->assertHttpStatus(422, fn () => $this->catalog()->createCategory($top['id'], 'coffee', null))->fields());
        $this->assertSame('beverages', $top['slug']);

        $product = $this->product('Barako Beans', null, $child['id'], []);
        $this->assertHttpStatus(409, fn () => $this->catalog()->archiveCategory($child['id'], null));
        $this->assertHttpStatus(409, fn () => $this->catalog()->archiveCategory($top['id'], null));

        $this->catalog()->setProductStatus($product['id'], 'discontinued', null);
        $this->catalog()->archiveCategory($child['id'], null);
        $this->catalog()->archiveCategory($top['id'], null);

        $tree = $this->catalog()->categoryTree();
        $this->assertSame([], $tree);
    }

    public function testAttributeValidationHooksReturnFieldLevelErrors(): void
    {
        $this->shop->hooks->add(Hooks::VALIDATION_ATTRIBUTES, static function (array $errors, array $context): array {
            $watts = $context['attributes']['wattage'] ?? null;
            if ($watts !== null && ($watts < 1 || $watts > 5000)) {
                $errors['attributes.wattage'] = 'Wattage must be between 1 and 5000.';
            }

            return $errors;
        });

        $error = $this->assertHttpStatus(422, fn () => $this->product('Heater', null, null, ['wattage' => 9000, 'colour' => ['not', 'allowed'], 'Bad Key' => 1]));

        $this->assertSame('Wattage must be between 1 and 5000.', $error->fields()['attributes.wattage']);
        $this->assertArrayHasKey('attributes.colour', $error->fields());
        $this->assertArrayHasKey('attributes.Bad Key', $error->fields());
        $this->assertSame(0, (int) $this->shop->scalar('SELECT COUNT(*) FROM products'));

        $ok = $this->product('Heater', null, null, ['wattage' => 1500, 'inverter' => true, 'empty_one' => '']);
        $stored = $this->catalog()->getProduct($ok['id']);
        $this->assertEquals(['wattage' => 1500, 'inverter' => true], $stored['attributes']);
    }

    public function testAProductNeedsAnActiveVariantBeforeItCanBeActive(): void
    {
        $error = $this->assertHttpStatus(422, fn () => $this->catalog()->createProduct(['name' => 'Empty Product', 'status' => 'active'], null));
        $this->assertArrayHasKey('status', $error->fields());

        $draft = $this->catalog()->createProduct(['name' => 'Draft Product'], null);
        $this->assertHttpStatus(422, fn () => $this->catalog()->setProductStatus($draft['id'], 'active', null));
        $this->catalog()->createVariant($draft['id'], ['sku' => 'DRAFT-1', 'name' => 'One', 'retail_price' => '10.00'], null);
        $this->catalog()->setProductStatus($draft['id'], 'active', null);
        $this->assertSame(['Draft Product'], $this->names());
    }

    public function testVariantFieldsAreValidatedAndDecimalQuantitiesNeedPermissionFromTheVariant(): void
    {
        $product = $this->catalog()->createProduct(['name' => 'Coffee Beans'], null)['id'];

        $whole = $this->assertHttpStatus(422, fn () => $this->catalog()->createVariant($product, ['sku' => 'CB-1', 'name' => '250 g', 'retail_price' => '250.00', 'min_qty' => '0.5'], null));
        $this->assertArrayHasKey('min_qty', $whole->fields());

        $bad = $this->assertHttpStatus(422, fn () => $this->catalog()->createVariant($product, ['sku' => 'bad sku!', 'name' => '', 'retail_price' => '12.345'], null));
        $this->assertSame(['sku', 'name', 'retail_price'], array_keys($bad->fields()));

        $loose = $this->catalog()->createVariant($product, [
            'sku' => 'CB-LOOSE', 'name' => 'Loose', 'unit' => 'kg', 'retail_price' => '900.00', 'allow_decimal_qty' => true, 'qty_step' => '0.250', 'min_qty' => '0.250',
        ], null)['id'];
        $row = $this->catalog()->getProduct($product)['variants'][0];
        $this->assertSame($loose, $row['id']);
        $this->assertSame('0.250', $row['qty_step']);

        $this->assertArrayHasKey('sku', $this->assertHttpStatus(422, fn () => $this->catalog()->createVariant($product, ['sku' => 'cb-loose', 'name' => 'Again', 'retail_price' => '1.00'], null))->fields());
        $this->assertArrayHasKey('min_qty', $this->assertHttpStatus(422, fn () => $this->catalog()->updateVariant($loose, ['min_qty' => '0.300'], null))->fields());
    }

    public function testPriceChangesAreWrittenToHistoryAndTheAuditLog(): void
    {
        $product = $this->product('Blender', null, null, [], '2000.00');
        $variantId = (string) $this->shop->scalar('SELECT id FROM product_variants WHERE product_id = ?', [$product['id']]);

        $this->catalog()->updateVariant($variantId, ['retail_price' => '1800.00'], null);
        $this->catalog()->updateVariant($variantId, ['name' => 'Renamed'], null);

        $this->assertSame(2, (int) $this->shop->scalar('SELECT COUNT(*) FROM price_history WHERE variant_id = ?', [$variantId]), 'one at creation, one for the change; renaming adds none');
        $this->assertSame(1, (int) $this->shop->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'price.changed'"));
        $this->assertSame('Renamed', (string) $this->shop->scalar('SELECT name FROM product_variants WHERE id = ?', [$variantId]));
        $this->assertSame('1800.00', (string) $this->shop->scalar('SELECT retail_price FROM product_variants WHERE id = ?', [$variantId]), 'an update that omits a field keeps it');
    }

    public function testPriceTiersByQuantityAndCustomerTier(): void
    {
        $product = $this->product('Blender', null, null, [], '2000.00');
        $variantId = (string) $this->shop->scalar('SELECT id FROM product_variants WHERE product_id = ?', [$product['id']]);

        $quantity = $this->catalog()->saveTier($variantId, ['basis' => 'quantity', 'label' => '5 or more', 'min_qty' => '5', 'price' => '1700.00'], null, null)['id'];
        $this->catalog()->saveTier($variantId, ['basis' => 'customer_tier', 'tier_key' => 'Gold', 'label' => 'Gold member', 'price' => '1600.00'], null, null);

        $this->assertArrayHasKey('price', $this->assertHttpStatus(422, fn () => $this->catalog()->saveTier($variantId, ['basis' => 'quantity', 'label' => 'x', 'min_qty' => '2', 'price' => '2100.00'], null, null))->fields());
        $this->assertArrayHasKey('tier_key', $this->assertHttpStatus(422, fn () => $this->catalog()->saveTier($variantId, ['basis' => 'customer_tier', 'label' => 'x', 'price' => '100.00'], null, null))->fields());

        $this->catalog()->saveTier($variantId, ['label' => '6 or more', 'min_qty' => '6', 'price' => '1650.00'], $quantity, null);
        $this->assertSame('1650.00', (string) $this->shop->scalar('SELECT price FROM price_tiers WHERE id = ?', [$quantity]));
        $this->assertCount(2, $this->catalog()->getProduct($product['id'], ['tiers' => true])['variants'][0]['tiers']);

        $this->catalog()->deleteTier($quantity, null);
        $this->assertCount(1, $this->catalog()->getProduct($product['id'], ['tiers' => true])['variants'][0]['tiers']);
    }

    public function testListingsFilterByBrandCategoryAndAttributeValues(): void
    {
        $nora = $this->catalog()->createBrand('Nora', null, null)['id'];
        $kuya = $this->catalog()->createBrand('Kuya Mario', null, null)['id'];
        $appliances = $this->catalog()->createCategory(null, 'Appliances', null);
        $fryers = $this->catalog()->createCategory($appliances['id'], 'Air Fryers', null);
        $fans = $this->catalog()->createCategory($appliances['id'], 'Fans', null);

        $this->product('Fryer Small', $nora, $fryers['id'], ['wattage' => 800, 'colour' => 'white', 'inverter' => false], '2500.00');
        $this->product('Fryer Large', $nora, $fryers['id'], ['wattage' => 1500, 'colour' => 'black', 'inverter' => true], '4500.00');
        $this->product('Stand Fan', $kuya, $fans['id'], ['wattage' => 2200, 'colour' => 'white', 'inverter' => true], '1800.00');
        $this->product('Hidden Draft', $nora, $fans['id'], ['wattage' => 1000], '100.00', 'draft');

        $this->assertSame(['Fryer Large', 'Fryer Small', 'Stand Fan'], $this->names(), 'drafts never show in a public listing');
        $this->assertSame(['Fryer Large', 'Fryer Small'], $this->names(['brand_id' => $nora]));
        $this->assertSame(['Fryer Large', 'Fryer Small', 'Stand Fan'], $this->names(['category_id' => $appliances['id']]), 'a top-level category includes its children');
        $this->assertSame(['Stand Fan'], $this->names(['category_id' => $fans['id']]));
        $this->assertSame(['Fryer Large', 'Stand Fan'], $this->names(['attr' => ['wattage' => ['min' => '1000']]]));
        $this->assertSame(['Fryer Large'], $this->names(['attr' => ['wattage' => ['min' => '1000', 'max' => '2000']]]));
        $this->assertSame(['Fryer Small', 'Stand Fan'], $this->names(['attr' => ['colour' => 'white']]));
        $this->assertSame(['Fryer Large', 'Stand Fan'], $this->names(['attr' => ['inverter' => 'true']]));
        $this->assertSame(['Fryer Small'], $this->names(['attr' => ['inverter' => 'false']]));
        $this->assertSame(['Fryer Large'], $this->names(['attr' => ['colour' => 'black', 'inverter' => 'true'], 'brand_id' => $nora]));
        $this->assertSame(['Fryer Small', 'Stand Fan'], $this->names(['max_price' => '2500.00']));
        $this->assertSame(['Fryer Large'], $this->names(['min_price' => '3000']));
        $this->assertSame(['Stand Fan', 'Fryer Small', 'Fryer Large'], $this->names(['sort' => 'price']));
        $this->assertSame(['Fryer Large'], $this->names(['q' => 'large']));

        // Text filters never break numeric ones, even when one key holds a mix of types.
        $this->product('Odd One', null, null, ['wattage' => 'high']);
        $this->assertSame(['Fryer Large', 'Stand Fan'], $this->names(['attr' => ['wattage' => ['min' => '1000']]]));
    }

    public function testFilterOptionsDescribeWhatIsAvailable(): void
    {
        $nora = $this->catalog()->createBrand('Nora', null, null)['id'];
        $top = $this->catalog()->createCategory(null, 'Appliances', null);
        $kids = $this->catalog()->createCategory($top['id'], 'Fans', null);
        $this->product('Fan One', $nora, $kids['id'], ['wattage' => 40, 'colour' => 'white', 'inverter' => true], '1500.00');
        $this->product('Fan Two', $nora, $kids['id'], ['wattage' => 75, 'colour' => 'black', 'inverter' => false], '2500.00');

        $options = $this->catalog()->filterOptions([], null, true);

        $this->assertSame(['Nora'], array_column($options['brands'], 'name'));
        $this->assertSame(2, $options['brands'][0]['product_count']);
        $this->assertSame('Appliances', $options['categories'][0]['name']);
        $this->assertSame(2, $options['categories'][0]['product_count']);
        $this->assertSame('Fans', $options['categories'][0]['children'][0]['name']);
        $this->assertSame('1500.00', $options['price']['min']);
        $this->assertSame('2500.00', $options['price']['max']);

        $byKey = array_column($options['attributes'], null, 'key');
        $this->assertSame(40.0, $byKey['wattage']['min']);
        $this->assertSame(75.0, $byKey['wattage']['max']);
        $this->assertSame(['black', 'white'], array_column($byKey['colour']['values'], 'value'));
        $this->assertSame('boolean', $byKey['inverter']['type']);
    }

    public function testBadFilterValuesAreRefusedBeforeTheyReachTheDatabase(): void
    {
        foreach ([['brand_id' => 'nope'], ['category_id' => "' OR 1=1"], ['min_price' => 'cheap'], ['status' => 'maybe'], ['attr' => ['Bad-Key' => 'x']], ['attr' => ['wattage' => ['min' => 'abc']]]] as $query) {
            $this->assertHttpStatus(422, fn () => CatalogService::parseFilters($query));
        }
        $this->assertHttpStatus(422, fn () => $this->listQuery(['sort' => 'password']));
        $this->assertHttpStatus(422, fn () => $this->listQuery(['page' => '0']));
        $this->assertSame(100, $this->listQuery(['per_page' => '5000'])->perPage);
    }

    public function testPhotosHaveAPrimaryOneAndCanBeReorderedAndRemoved(): void
    {
        $product = $this->product('Kettle', null, null, [])['id'];
        $media = [];
        foreach (['a', 'b', 'c'] as $letter) {
            $media[$letter] = (string) $this->shop->scalar(
                "INSERT INTO media (path, original_name, mime, bytes) VALUES (?, ?, 'image/jpeg', 100) RETURNING id",
                ["products/$letter.jpg", "$letter.jpg"],
            );
        }

        $first = $this->catalog()->attachImage($product, $media['a'], null, false)['id'];
        $second = $this->catalog()->attachImage($product, $media['b'], null, false)['id'];
        $third = $this->catalog()->attachImage($product, $media['c'], null, true)['id'];

        $images = $this->catalog()->getProduct($product)['images'];
        $this->assertSame([$first, $second, $third], array_column($images, 'id'));
        $this->assertSame([$third], array_column(array_filter($images, static fn (array $i): bool => $i['is_primary']), 'id'), 'the newest was made primary on purpose');

        $this->catalog()->setPrimaryImage($second);
        $this->catalog()->reorderImages($product, [$third, $second, $first]);
        $images = $this->catalog()->getProduct($product)['images'];
        $this->assertSame([$third, $second, $first], array_column($images, 'id'));
        $this->assertSame([$second], array_values(array_column(array_filter($images, static fn (array $i): bool => $i['is_primary']), 'id')));

        $this->assertArrayHasKey('image_ids', $this->assertHttpStatus(422, fn () => $this->catalog()->reorderImages($product, [$third]))->fields());

        $this->catalog()->removeImage($second);
        $images = $this->catalog()->getProduct($product)['images'];
        $this->assertCount(2, $images);
        $this->assertSame(1, count(array_filter($images, static fn (array $i): bool => $i['is_primary'])), 'a new primary is chosen when the primary is removed');
        $this->assertArrayHasKey('media_id', $this->assertHttpStatus(422, fn () => $this->catalog()->attachImage($product, '3f2b8c1e-9d4a-4b7e-8a61-5c0d2e9f7a10', null, false))->fields());
    }

    public function testPublicProductDetailsHideCostsAndDraftsAndTiersOnlyShowWhenAsked(): void
    {
        $product = $this->product('Blender', null, null, [], '2000.00');
        $variantId = (string) $this->shop->scalar('SELECT id FROM product_variants WHERE product_id = ?', [$product['id']]);
        $this->catalog()->saveTier($variantId, ['basis' => 'quantity', 'label' => '5 or more', 'min_qty' => '5', 'price' => '1700.00'], null, null);
        $draft = $this->catalog()->createProduct(['name' => 'Secret Draft'], null);

        $public = $this->catalog()->getProduct($product['slug'], ['public' => true]);
        $this->assertArrayNotHasKey('avg_cost', $public['variants'][0]);
        $this->assertArrayNotHasKey('trade_price', $public['variants'][0]);
        $this->assertSame([], $public['variants'][0]['tiers']);

        $this->assertNull($this->catalog()->getProduct($draft['slug'], ['public' => true]));
        $this->assertNotNull($this->catalog()->getProduct($draft['id']), 'staff can still open a draft');
        $this->assertNull($this->catalog()->getProduct('no-such-product', ['public' => true]));
    }
}
