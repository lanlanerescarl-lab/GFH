<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\ShopBrowseService;
use Gfh\Core\Service\ShopNavigationService;
use Gfh\Core\Support\Format;
use Gfh\Core\View\AppView;
use Gfh\Core\View\AssetUrls;
use Gfh\Core\View\TemplateLocator;
use Gfh\Tests\Support\ShopTestCase;

/**
 * The public Shop pages (Batch 6.1): home, listing with filters, search, sorting and pagination, and product detail,
 * checked through the real router for a guest and a signed-in customer.
 */
final class ShopBrowseTest extends ShopTestCase
{
    private const SCRIPT = '<script>alert(1)</script>';

    // ---------------------------------------------------------------- helpers

    /** @return list<string> the product names shown in the cards of a page, in order */
    private function names(string $html): array
    {
        preg_match_all('/<h3 class="product-card__name">(.*?)<\/h3>/s', $html, $matches);

        return array_map(static fn (string $name): string => html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches[1]);
    }

    /** @param array<string, mixed> $query */
    private function listing(array $query = [], bool $asCustomer = false): string
    {
        $response = $this->get("/{$this->slug}/shop/products", $query, $asCustomer);
        $this->assertSame(200, $response->status(), 'GET listing ' . json_encode($query) . $this->debug($response));

        return $response->body();
    }

    private function detail(string $slug, array $query = [], bool $asCustomer = false): string
    {
        $response = $this->get("/{$this->slug}/shop/products/$slug", $query, $asCustomer);
        $this->assertSame(200, $response->status(), "GET detail $slug" . $this->debug($response));

        return $response->body();
    }

    /**
     * The catalog used by the listing tests: two brands, a two-level category tree and four products (one draft).
     *
     * @return array{nora: string, kuya: string, appliances: string, fryers: string, fans: string}
     */
    private function fixture(): array
    {
        $nora = $this->catalog()->createBrand('Nora', null, null)['id'];
        $kuya = $this->catalog()->createBrand('Kuya Mario', null, null)['id'];
        $appliances = $this->catalog()->createCategory(null, 'Appliances', null)['id'];
        $fryers = $this->catalog()->createCategory($appliances, 'Air Fryers', null)['id'];
        $fans = $this->catalog()->createCategory($appliances, 'Fans', null)['id'];

        $this->addProduct('Fryer Small', ['brand_id' => $nora, 'category_id' => $fryers, 'attributes' => ['wattage' => 800, 'colour' => 'white', 'inverter' => false], 'variants' => [['sku' => 'FRY-S', 'name' => 'Default', 'retail_price' => '2500.00']]]);
        $this->addProduct('Fryer Large', ['brand_id' => $nora, 'category_id' => $fryers, 'attributes' => ['wattage' => 1500, 'colour' => 'black', 'inverter' => true], 'variants' => [['sku' => 'FRY-L', 'name' => 'Default', 'retail_price' => '4500.00']]]);
        $this->addProduct('Stand Fan', ['brand_id' => $kuya, 'category_id' => $fans, 'attributes' => ['wattage' => 2200, 'colour' => 'white', 'inverter' => true], 'variants' => [['sku' => 'FAN-1', 'name' => 'Default', 'retail_price' => '1800.00']]]);
        $this->addProduct('Hidden Draft', ['status' => 'draft', 'brand_id' => $nora, 'category_id' => $fans]);

        return ['nora' => $nora, 'kuya' => $kuya, 'appliances' => $appliances, 'fryers' => $fryers, 'fans' => $fans];
    }

    /** The query string parameters of a link printed in a page (&amp; decoded). @return array<string, mixed> */
    private function linkQuery(string $href): array
    {
        parse_str((string) parse_url(html_entity_decode($href, ENT_QUOTES | ENT_HTML5), PHP_URL_QUERY), $query);

        return $query;
    }

    // ---------------------------------------------------------------- listing

    public function testListingFiltersByBrandCategoryPriceAndAttributes(): void
    {
        $this->bootShop();
        $f = $this->fixture();

        $this->assertSame(['Fryer Large', 'Fryer Small', 'Stand Fan'], $this->names($this->listing()), 'a draft never shows');
        $this->assertSame(['Fryer Large', 'Fryer Small'], $this->names($this->listing(['brand_id' => [$f['nora']]])), 'brand (a list, as the form sends it)');
        $this->assertSame(['Fryer Large', 'Fryer Small'], $this->names($this->listing(['brand_id' => $f['nora']])), 'brand (one id)');
        $this->assertSame(['Fryer Large', 'Fryer Small', 'Stand Fan'], $this->names($this->listing(['brand_id' => [$f['nora'], $f['kuya']]])), 'two brands');
        $this->assertSame(['Fryer Large', 'Fryer Small', 'Stand Fan'], $this->names($this->listing(['category_id' => $f['appliances']])), 'a top-level category includes its children');
        $this->assertSame(['Stand Fan'], $this->names($this->listing(['category_id' => $f['fans']])));
        $this->assertSame(['Fryer Small', 'Stand Fan'], $this->names($this->listing(['max_price' => '2500'])));
        $this->assertSame(['Fryer Large'], $this->names($this->listing(['min_price' => '3000.00'])));
        $this->assertSame(['Fryer Small', 'Stand Fan'], $this->names($this->listing(['attr' => ['colour' => 'white']])));
        $this->assertSame(['Fryer Large', 'Stand Fan'], $this->names($this->listing(['attr' => ['inverter' => 'true']])));
        $this->assertSame(['Fryer Large', 'Stand Fan'], $this->names($this->listing(['attr' => ['wattage' => ['min' => '1000']]])));
        $this->assertSame(['Fryer Large'], $this->names($this->listing(['attr' => ['wattage' => ['min' => '1000', 'max' => '2000']]])));
        $this->assertSame(['Fryer Large'], $this->names($this->listing(['brand_id' => [$f['nora']], 'attr' => ['colour' => 'black', 'inverter' => 'true']])), 'filters combine');
        $this->signedInCustomer();
        $this->assertSame($this->names($this->listing()), $this->names($this->listing([], true)), 'a signed-in customer sees the same products');
    }

    public function testSortOrders(): void
    {
        $this->bootShop();
        $this->fixture();
        foreach (['Stand Fan' => 1, 'Fryer Large' => 2, 'Fryer Small' => 3] as $name => $daysAgo) {
            $this->org->prepare("UPDATE products SET created_at = now() - (? || ' days')::interval WHERE name = ?")->execute([$daysAgo, $name]);
        }

        $this->assertSame(['Fryer Large', 'Fryer Small', 'Stand Fan'], $this->names($this->listing(['sort' => 'name'])));
        $this->assertSame(['Stand Fan', 'Fryer Small', 'Fryer Large'], $this->names($this->listing(['sort' => '-name'])));
        $this->assertSame(['Stand Fan', 'Fryer Small', 'Fryer Large'], $this->names($this->listing(['sort' => 'price'])));
        $this->assertSame(['Fryer Large', 'Fryer Small', 'Stand Fan'], $this->names($this->listing(['sort' => '-price'])));
        $this->assertSame(['Stand Fan', 'Fryer Large', 'Fryer Small'], $this->names($this->listing(['sort' => '-newest'])));
        $this->assertSame(['Fryer Small', 'Fryer Large', 'Stand Fan'], $this->names($this->listing(['sort' => 'newest'])));

        $html = $this->listing(['sort' => '-price']);
        $this->assertMatchesRegularExpression('/<option value="-price" selected>Price, high to low<\/option>/', $html, 'the chosen sort is selected in the box');
    }

    public function testSearchFindsProductsShowsTheCountAndKeepsTheTextInTheBox(): void
    {
        $this->bootShop();
        $this->fixture();

        $html = $this->listing(['q' => 'large']);
        $this->assertSame(['Fryer Large'], $this->names($html));
        $this->assertStringContainsString('1 product', $this->text($html));
        $this->assertStringContainsString('Search results', $html);
        $this->assertStringContainsString('Search: large', $html, 'the search is a removable chip');
        $this->assertStringContainsString('name="q" value="large"', $html, 'the header search box shows the search again');
        $this->assertSame(['Stand Fan'], $this->names($this->listing(['q' => 'FAN-1'])), 'a SKU finds its product');
    }

    public function testResultCountChipsAndTheEmptyState(): void
    {
        $this->bootShop();
        $f = $this->fixture();

        $html = $this->listing(['brand_id' => [$f['nora']], 'category_id' => $f['fryers'], 'min_price' => '3000']);
        $this->assertStringContainsString('1 product', $this->text($html));
        foreach (['Brand: Nora', 'Category: Air Fryers', 'From ' . Format::peso('3000.00')] as $chip) {
            $this->assertStringContainsString($chip, $this->text($html), "chip $chip");
        }

        // Each chip links to the same listing without that filter.
        preg_match('/<a class="chip" href="([^"]*)" aria-label="Remove filter: Brand: Nora"/', $html, $brand);
        $this->assertArrayHasKey(1, $brand, 'the brand chip has a remove link');
        $without = $this->linkQuery($brand[1]);
        $this->assertArrayNotHasKey('brand_id', $without);
        $this->assertSame($f['fryers'], $without['category_id']);
        $this->assertSame('3000.00', $without['min_price']);
        $this->assertStringContainsString('Clear all filters', $html);

        $empty = $this->listing(['min_price' => '999999']);
        $this->assertSame([], $this->names($empty));
        $this->assertSame(2, substr_count($this->text($empty), 'No products found'), 'in the count line and in the empty state');
        $this->assertStringContainsString('Show all products', $empty);
        $noMatch = $this->listing(['q' => 'zzzz']);
        $this->assertSame([], $this->names($noMatch));
        $this->assertStringContainsString('Search: zzzz', $noMatch, 'a search with no match still shows its chip');
        $this->assertStringContainsString('Try a different search, or remove some filters.', $noMatch);
    }

    public function testPaginationUsesTheSharedQueryParameters(): void
    {
        $this->bootShop();
        for ($i = 1; $i <= 25; $i++) {
            $this->addProduct(sprintf('Item %02d', $i));
        }

        $first = $this->listing();
        $this->assertCount(20, $this->names($first), 'the default page size is 20');
        $this->assertStringContainsString('25 products (showing 1 to 20)', $this->text($first));

        $page = $this->listing(['per_page' => '10', 'sort' => '-name']);
        $this->assertSame('Item 25', $this->names($page)[0]);
        $this->assertCount(10, $this->names($page));
        $this->assertStringContainsString('25 products (showing 1 to 10)', $this->text($page));
        preg_match('/<a class="pagination__link" href="([^"]*)" rel="next"/', $page, $next);
        $this->assertSame(['sort' => '-name', 'per_page' => '10', 'page' => '2'], $this->linkQuery($next[1]), 'the next page keeps the whole state');
        $this->assertStringNotContainsString('rel="prev"', $page);

        $last = $this->listing(['per_page' => '10', 'sort' => '-name', 'page' => '3']);
        $this->assertCount(5, $this->names($last));
        $this->assertStringContainsString('rel="prev"', $last);
        $this->assertStringNotContainsString('rel="next"', $last);

        $beyond = $this->listing(['per_page' => '10', 'sort' => '-name', 'page' => '999']);
        $this->assertSame($this->names($last), $this->names($beyond), 'a page past the end shows the last page');
        $this->assertMatchesRegularExpression('/aria-current="page"><span class="visually-hidden">Page <\/span>3</', $beyond);

        $bad = $this->listing(['page' => 'abc']);
        $this->assertCount(20, $this->names($bad));
        $this->assertStringContainsString('left out: Page', $this->text($bad));
    }

    public function testUnknownAndMalformedFilterValuesAreIgnoredOrShownAsFieldErrorsNeverAServerError(): void
    {
        $this->bootShop();
        $this->fixture();

        $queries = [
            ['category_id' => 'not-a-uuid'],
            ['category_id' => ['x']],
            ['brand_id' => 'nope'],
            ['brand_id' => [['nested']]],
            ['brand_id' => ['00000000-0000-0000-0000-000000000000']],
            ['min_price' => 'abc'],
            ['max_price' => ['1']],
            ['min_price' => '-5'],
            ['min_price' => '99999999999999999999'],
            ['min_price' => '500', 'max_price' => '100'],
            ['attr' => 'string'],
            ['attr' => ['bad key' => 'x', 'UPPER' => 'y']],
            ['attr' => ['colour' => ['min' => 'abc']]],
            ['attr' => ['colour' => ['nested' => ['deep']]]],
            ['attr' => ['no_such_attribute' => 'x']],
            ['sort' => 'bogus'],
            ['sort' => ['name']],
            ['page' => '-1'],
            ['page' => '0'],
            ['page' => ['1']],
            ['per_page' => '0'],
            ['per_page' => '100000'],
            ['q' => ['x']],
            ['q' => str_repeat('long ', 200)],
            ['variant' => 'x', 'unknown' => 'param'],
        ];
        foreach ([false, true] as $asCustomer) {
            $this->signedInCustomer();
            foreach ($queries as $query) {
                $html = $this->listing($query, $asCustomer);
                $this->assertDoesNotMatchRegularExpression('/\b(?:Warning|Notice|Fatal error|Uncaught|Stack trace)\b/', $this->text($html), json_encode($query));
            }
        }

        $price = $this->listing(['min_price' => 'abc']);
        $this->assertStringContainsString('Enter an amount like 150 or 150.50.', $price, 'a bad price is a field error');
        $this->assertStringContainsString('aria-invalid="true"', $price);
        $this->assertStringContainsString('value="abc"', $price, 'the box shows what was typed');
        $this->assertCount(3, $this->names($price), 'the bad price is not applied');
        $this->assertStringContainsString('left out: Category', $this->text($this->listing(['category_id' => 'not-a-uuid'])));
        $this->assertStringContainsString('left out: Sort order', $this->text($this->listing(['sort' => 'bogus'])));
        $this->assertStringContainsString('The highest price is lower than the lowest price.', $this->listing(['min_price' => '500', 'max_price' => '100']));
    }

    public function testFilterOptionsShowCountsAndStayWideWhenOneIsChosen(): void
    {
        $this->bootShop();
        $f = $this->fixture();

        $html = $this->listing(['brand_id' => [$f['nora']]]);
        $this->assertMatchesRegularExpression('/<span class="choice__label">Nora<\/span><span class="choice__count">2<\/span>/', $html, 'brands show counts');
        $this->assertMatchesRegularExpression('/<span class="choice__label">Kuya Mario<\/span><span class="choice__count">1<\/span>/', $html, 'the other brand stays listed, so the choice can be widened');
        $this->assertMatchesRegularExpression('/name="brand_id\[\]" value="' . $f['nora'] . '" checked/', $html);

        $html = $this->listing(['category_id' => $f['fans']]);
        $this->assertMatchesRegularExpression('/<span class="choice__label">Appliances<\/span><span class="choice__count">3<\/span>/', $html, 'the two-level tree shows the top level with its total');
        $this->assertMatchesRegularExpression('/<span class="choice__label">Air Fryers<\/span><span class="choice__count">2<\/span>/', $html, 'a sibling category stays listed');
        $this->assertMatchesRegularExpression('/name="category_id" value="' . $f['fans'] . '" checked/', $html);

        $html = $this->listing();
        $this->assertStringContainsString('name="attr[colour]"', $html, 'a text attribute is a select');
        $this->assertStringContainsString('white (2)', $html);
        $this->assertStringContainsString('name="attr[wattage][min]"', $html, 'a number attribute is a range');
        $this->assertStringContainsString('name="attr[inverter]"', $html);
        $this->assertStringContainsString('placeholder="' . Format::peso('1800.00') . '"', $html, 'the price boxes hint at the range');
    }

    // ---------------------------------------------------------------- detail

    public function testDetailRendersTheDefaultVariantAndTheDataOfEveryVariant(): void
    {
        $this->bootShop();
        $category = $this->catalog()->createCategory(null, 'Kitchen', null)['id'];
        $product = $this->addProduct('Coffee Beans', [
            'category_id' => $category,
            'description' => "Whole beans, roasted weekly.\nSmall batches.\n\nStore in a cool place.",
            'attributes' => ['roast_level' => 'medium', 'fair_trade' => true, 'altitude_m' => 1500],
            'variants' => [
                ['sku' => 'CB-250', 'name' => '250 g', 'retail_price' => '250.00', 'unit' => 'bag'],
                ['sku' => 'CB-1K', 'name' => '1 kg', 'retail_price' => '900.00', 'unit' => 'bag'],
                ['sku' => 'CB-BULK', 'name' => 'By weight', 'retail_price' => '1.50', 'unit' => 'kg', 'allow_decimal_qty' => true, 'qty_step' => '0.5', 'min_qty' => '0.5'],
            ],
        ]);
        $ids = $this->variantIds($product['id']);
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = $this->org->query('SELECT id, sku, retail_price, allow_decimal_qty, qty_step, min_qty FROM product_variants WHERE id = ' . $this->org->quote($id))->fetch();
        }
        $branch = $this->shop->branch();
        $this->shop->stock($branch, $ids[0], '5', '40.00');

        $html = $this->detail($product['slug']);

        // The default (first) variant is rendered by the server.
        $default = $rows[$ids[0]];
        $this->assertStringContainsString('data-variant-price>' . Format::peso((string) $default['retail_price']) . '<', $html);
        $this->assertStringContainsString('data-variant-sku>' . $default['sku'] . '<', $html);
        $this->assertStringContainsString('data-variant-availability>In stock<', $html);
        $this->assertSame(1, substr_count($html, 'Prices include VAT.'), 'the VAT note appears once');
        $this->assertStringNotContainsString('Prices include VAT.', $this->listing());

        // Variant data for every variant, as data attributes the script reads.
        foreach ($rows as $id => $row) {
            $this->assertMatchesRegularExpression(
                '/<input type="radio" name="variant" value="' . $id . '"[^>]*data-price="' . preg_quote(Format::peso((string) $row['retail_price']), '/') . '"[^>]*data-sku="' . preg_quote((string) $row['sku'], '/') . '"/s',
                $html,
                'variant data for ' . $row['sku'],
            );
        }
        $this->assertMatchesRegularExpression('/value="' . $ids[0] . '" checked/', $html);
        $this->assertStringContainsString('class="button button--secondary no-js-only" type="submit">Show this option', $html, 'without JavaScript a button picks a variant');

        // ?variant= renders another variant on the server, so the page is complete without JavaScript.
        $bulk = $ids[2];
        $other = $this->detail($product['slug'], ['variant' => $bulk]);
        $this->assertStringContainsString('data-variant-price>' . Format::peso('1.50') . '<', $other);
        $this->assertStringContainsString('data-variant-sku>CB-BULK<', $other);
        $this->assertStringContainsString('data-variant-availability>Out of stock<', $other);
        $this->assertMatchesRegularExpression('/value="' . $bulk . '" checked/', $other);
        $this->assertStringContainsString('step="0.5" min="0.5" value="0.5"', $other, 'a decimal variant takes its own step and minimum');
        $this->assertStringContainsString('Steps of 0.5. Minimum 0.5.', $other);
        $this->assertStringContainsString('step="1" min="1" value="1"', $html, 'a whole-number variant takes whole numbers');
        $this->assertSame($html, $this->detail($product['slug'], ['variant' => 'not-a-variant']), 'an unknown variant falls back to the default');

        // The disabled Add to cart form and its note.
        $this->assertStringContainsString('<fieldset class="add-to-cart__fields" disabled>', $html);
        $this->assertStringContainsString('<button class="button add-to-cart__button" type="submit" disabled>Add to cart</button>', $html);
        $this->assertStringContainsString('data-add-to-cart', $html);
        $this->assertStringContainsString('The cart opens soon.', $html);

        // Description paragraphs, the attribute table and the breadcrumb.
        $this->assertSame(1, substr_count($html, '<div class="product-section__body product-description">'));
        $this->assertSame(1, substr_count($html, '<p>Store in a cool place.</p>'), 'a blank line starts a new paragraph');
        $this->assertStringContainsString('<p>Whole beans, roasted weekly.<br>' . "\n" . 'Small batches.</p>', $html);
        $this->assertStringContainsString('<th scope="row">Roast level</th>', $html);
        $this->assertStringContainsString('<td>medium</td>', $html);
        $this->assertStringContainsString('<th scope="row">Fair trade</th>', $html);
        $this->assertStringContainsString('<td>Yes</td>', $html);
        $this->assertStringContainsString('<td>1500</td>', $html);
        $this->assertStringContainsString('<li><a href="/' . $this->slug . '/shop/products?category_id=' . $category . '">Kitchen</a></li>', $html);

        // A product with one variant shows no variant choice.
        $single = $this->addProduct('Single Thing');
        $this->assertStringNotContainsString('name="variant"', $this->detail($single['slug']));
    }

    public function testAnUnknownOrInactiveSlugIsA404Page(): void
    {
        $this->bootShop();
        $draft = $this->addProduct('Draft Thing', ['status' => 'draft']);
        $archived = $this->addProduct('Archived Thing');
        $this->org->prepare("UPDATE products SET status = 'discontinued' WHERE id = ?")->execute([$archived['id']]);
        $inactive = $this->addProduct('Inactive Variant Thing');
        $this->org->prepare("UPDATE product_variants SET status = 'inactive' WHERE product_id = ?")->execute([$inactive['id']]);
        $live = $this->addProduct('Live Thing');

        foreach (['no-such-product', $draft['slug'], $archived['slug'], $inactive['slug'], 'a%20b', str_repeat('x', 300), '..', $draft['id']] as $slug) {
            foreach ([false, true] as $asCustomer) {
                $this->signedInCustomer();
                $response = $this->get("/{$this->slug}/shop/products/$slug", [], $asCustomer);
                $this->assertSame(404, $response->status(), "GET $slug");
                $this->assertStringContainsString('We could not find that', $response->body());
                $this->assertStringContainsString('class="skip-link"', $response->body(), 'the 404 page is inside the Shop layout');
                $this->assertStringContainsString('Browse all products', $response->body());
            }
        }
        $this->assertSame(200, $this->get("/{$this->slug}/shop/products/{$live['slug']}")->status());
        $this->assertNotContains('Draft Thing', $this->names($this->listing()));
    }

    public function testProductsWithoutPhotosShowThePlaceholderAndPhotosAreLinkedSafely(): void
    {
        $this->bootShop();
        $bare = $this->addProduct('Bare Thing');
        $photo = $this->addProduct('Photo Thing');
        $first = $this->addPhoto($photo['id'], false);
        $second = $this->addPhoto($photo['id'], true);

        // No photo: the inline placeholder everywhere, and no picture file is linked.
        foreach ([$this->detail($bare['slug']), $this->listing(['q' => 'Bare']), $this->get("/{$this->slug}/shop")->body()] as $html) {
            $this->assertStringContainsString('<svg class="shop-placeholder"', $html);
        }
        $detail = $this->detail($bare['slug']);
        $this->assertStringNotContainsString('/media/', $detail);
        $this->assertStringContainsString('This product has no photo yet.', $detail);

        // With photos: the card links the 400 px thumbnail of the primary photo, with size and lazy loading.
        $card = $this->listing(['q' => 'Photo']);
        $this->assertStringContainsString('<img src="/media/' . $this->slug . '/' . $second . '?thumb=1" width="400" height="500" alt="Photo Thing" loading="lazy" decoding="async">', $card);
        $this->assertStringNotContainsString($first, $card);

        // The detail page lists every photo (primary first), visible without JavaScript, plus thumbnails.
        $html = $this->detail($photo['slug']);
        $this->assertLessThan(strpos($html, '/media/' . $this->slug . '/' . $first), strpos($html, '/media/' . $this->slug . '/' . $second), 'the primary photo comes first');
        $this->assertSame(2, preg_match_all('/<li class="gallery__slide" id="photo-\d"/', $html), 'every photo has a slide');
        $this->assertSame(2, substr_count($html, 'data-gallery-thumb'), 'and a thumbnail link');
        $this->assertStringContainsString('href="#photo-2"', $html);
        $this->assertStringContainsString('aria-label="Show photo 2 of 2"', $html);
        $this->assertStringContainsString('width="800" height="1000" alt="Photo Thing"', $html);
    }

    // ---------------------------------------------------------------- tier prices, leaks, escaping, hosts

    public function testTierPricesAppearOnlyForASignedInCustomerWhoQualifies(): void
    {
        $this->bootShop();
        $product = $this->addProduct('Blender', ['variants' => [['sku' => 'BL-1', 'name' => 'Default', 'retail_price' => '2000.00']]]);
        $variant = $this->variantIds($product['id'])[0];
        $this->catalog()->saveTier($variant, ['basis' => 'quantity', 'label' => 'Buy five', 'min_qty' => '5', 'price' => '1700.00'], null, null);
        $this->catalog()->saveTier($variant, ['basis' => 'customer_tier', 'tier_key' => 'Gold', 'label' => 'Gold member', 'price' => '1600.00'], null, null);
        $this->catalog()->saveTier($variant, ['basis' => 'customer_type', 'tier_key' => 'wholesale', 'label' => 'Trade buyer', 'price' => '1500.00'], null, null);
        $slug = $product['slug'];

        $guest = $this->detail($slug);
        $this->assertStringNotContainsString('Your prices', $guest);
        foreach (['1,700.00', '1,600.00', '1,500.00', 'Buy five', 'Gold member', 'Trade buyer'] as $leak) {
            $this->assertStringNotContainsString($leak, $guest, "a guest never sees $leak");
        }

        $this->signedInCustomer(null, null, 'plain@example.test');
        $plain = $this->detail($slug, [], true);
        $this->assertStringContainsString('Your prices', $plain);
        $this->assertStringContainsString('Buy five', $plain);
        $this->assertStringContainsString('1,700.00', $plain, 'a quantity tier is for every signed-in customer');
        $this->assertStringNotContainsString('Gold member', $plain);
        $this->assertStringNotContainsString('Trade buyer', $plain);

        $this->signedInCustomer('gold', null, 'gold@example.test');
        $gold = $this->detail($slug, [], true);
        $this->assertStringContainsString('Gold member', $gold, 'the tier name matches whatever its case');
        $this->assertStringContainsString('1,600.00', $gold);
        $this->assertStringNotContainsString('Trade buyer', $gold);

        $this->signedInCustomer(null, 'wholesale', 'trade@example.test');
        $trade = $this->detail($slug, [], true);
        $this->assertStringContainsString('Trade buyer', $trade);
        $this->assertStringContainsString('1,500.00', $trade);
        $this->assertStringNotContainsString('Gold member', $trade);

        // The same visitors on the same page without a session cookie are guests again.
        $this->assertStringNotContainsString('Your prices', $this->detail($slug));
        // The listing shows the shelf price to everyone.
        $this->assertStringContainsString(Format::peso('2000.00'), $this->listing([], true));
    }

    public function testNoCostTradePriceOrExactStockFigureAppearsInAnyPage(): void
    {
        $this->bootShop();
        $product = $this->addProduct('Leaky Thing', ['variants' => [['sku' => 'LEAK-1', 'name' => 'Default', 'retail_price' => '500.00', 'trade_price' => '88.77', 'reorder_level' => '7771']]]);
        $variant = $this->variantIds($product['id'])[0];
        $this->shop->stock($this->shop->branch(), $variant, '4321', '37.31');
        $this->catalog()->saveTier($variant, ['basis' => 'quantity', 'label' => 'Bulk', 'min_qty' => '10', 'price' => '450.00'], null, null);
        $this->signedInCustomer();

        $pages = [];
        foreach ([false, true] as $asCustomer) {
            $pages[] = $this->get("/{$this->slug}/shop", [], $asCustomer)->body();
            $pages[] = $this->listing([], $asCustomer);
            $pages[] = $this->listing(['q' => 'Leaky'], $asCustomer);
            $pages[] = $this->detail($product['slug'], [], $asCustomer);
        }
        $this->assertStringContainsString('In stock', $pages[3], 'availability is shown as a word');

        foreach ($pages as $index => $html) {
            foreach (['37.31', '88.77', '4321', '7771'] as $figure) {
                $this->assertDoesNotMatchRegularExpression('/(?<![0-9A-Za-z-])' . preg_quote($figure, '/') . '(?![0-9A-Za-z-])/', $html, "page $index shows $figure");
            }
            foreach (['avg_cost', 'trade_price', 'reorder_level', 'on_hand', 'data-stock', 'data-cost'] as $word) {
                $this->assertStringNotContainsString($word, $html, "page $index mentions $word");
            }
        }
    }

    public function testHtmlInProductDataIsEscapedOnEveryPage(): void
    {
        $this->bootShop();
        $brand = $this->catalog()->createBrand('<b>Bold</b> Brand', null, null)['id'];
        $category = $this->catalog()->createCategory(null, '<i>Slanted</i> Things', null)['id'];
        $product = $this->addProduct(self::SCRIPT . ' Lamp', [
            'brand_id' => $brand,
            'category_id' => $category,
            'description' => '<img src=x onerror=alert(1)> described',
            'attributes' => ['finish' => '<u>matte</u>', 'size' => '"quoted" & more'],
            'variants' => [
                ['sku' => 'XSS-1', 'name' => '<em>Small</em>', 'retail_price' => '10.00'],
                ['sku' => 'XSS-2', 'name' => '<em>Large</em>', 'retail_price' => '20.00'],
            ],
        ]);
        $this->addPhoto($product['id'], true);
        $this->signedInCustomer();

        $pages = ['home' => "/{$this->slug}/shop", 'listing' => "/{$this->slug}/shop/products", 'detail' => "/{$this->slug}/shop/products/{$product['slug']}"];
        $queries = [
            'home' => [],
            'listing' => ['brand_id' => [$brand], 'category_id' => $category, 'q' => self::SCRIPT, 'attr' => ['finish' => '<u>matte</u>']],
            'detail' => [],
        ];
        foreach ($pages as $name => $path) {
            foreach ([false, true] as $asCustomer) {
                $html = $this->get($path, $queries[$name], $asCustomer)->body();
                foreach (['<script>alert(1)', '<b>Bold', '<i>Slanted', '<img src=x', '<u>matte', '<em>Small', '<em>Large'] as $raw) {
                    $this->assertStringNotContainsString($raw, $html, "$name shows $raw unescaped");
                }
            }
        }

        $listing = $this->get($pages['listing'], ['brand_id' => [$brand], 'q' => self::SCRIPT, 'category_id' => $category], false)->body();
        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt; Brand', $listing, 'brand names are plain text in the filters and chips');
        $this->assertStringContainsString('&lt;i&gt;Slanted&lt;/i&gt; Things', $listing);
        $this->assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $listing, 'the search text is escaped in the box');
        $detail = $this->get($pages['detail'])->body();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Lamp', $detail);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; described', $detail);
        $this->assertStringContainsString('&lt;em&gt;Small&lt;/em&gt;', $detail);
        $this->assertStringContainsString('alt="&lt;script&gt;alert(1)&lt;/script&gt; Lamp"', $detail, 'alt text is escaped');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Lamp', $this->get($pages['home'])->body());
    }

    public function testNoPageContainsAUrlToAnotherHost(): void
    {
        $this->bootShop();
        $product = $this->addProduct('Plain Thing', ['variants' => [['sku' => 'P-1', 'name' => 'One', 'retail_price' => '10.00'], ['sku' => 'P-2', 'name' => 'Two', 'retail_price' => '12.00']]]);
        $this->addPhoto($product['id'], true);
        $this->addPhoto($product['id'], false);
        $this->signedInCustomer();

        $paths = [["/{$this->slug}/shop", []], ["/{$this->slug}/shop/products", ['q' => 'thing', 'sort' => 'price']], ["/{$this->slug}/shop/products/{$product['slug']}", []], ["/{$this->slug}/shop/products/none", []]];
        foreach ($paths as [$path, $query]) {
            foreach ([false, true] as $asCustomer) {
                $html = $this->get($path, $query, $asCustomer)->body();
                $this->assertDoesNotMatchRegularExpression('#https?://#i', $html, "$path has an absolute URL");
                $this->assertDoesNotMatchRegularExpression('#\b(?:href|src|action|srcset|poster|data)\s*=\s*["\']\s*//#i', $html, "$path has a protocol-relative URL");
                $this->assertDoesNotMatchRegularExpression('#@import|url\(\s*["\']?(?:https?:)?//#i', $html, "$path imports from another host");
            }
        }
    }

    // ---------------------------------------------------------------- accessibility

    public function testAltTextLabelsSkipLinkAndHeadingOrder(): void
    {
        $this->bootShop();
        $f = $this->fixture();
        $withPhotos = $this->addProduct('Photographed', ['brand_id' => $f['nora'], 'category_id' => $f['fryers'], 'variants' => [['sku' => 'PH-1', 'name' => 'One', 'retail_price' => '10.00'], ['sku' => 'PH-2', 'name' => 'Two', 'retail_price' => '12.00']]]);
        $this->addPhoto($withPhotos['id'], true);
        $this->addPhoto($withPhotos['id'], false);

        $pages = [
            'home' => $this->get("/{$this->slug}/shop")->body(),
            'listing' => $this->listing(),
            'filtered listing' => $this->listing(['brand_id' => [$f['nora']], 'q' => 'fry', 'min_price' => 'abc']),
            'empty listing' => $this->listing(['q' => 'zzzz']),
            'detail' => $this->detail($withPhotos['slug']),
            'not found' => $this->get("/{$this->slug}/shop/products/none")->body(),
        ];

        foreach ($pages as $name => $html) {
            $this->assertStringContainsString('<a class="skip-link" href="#main">', $html, "$name: skip link");
            $this->assertStringContainsString('<main class="shell-main" id="main"', $html, "$name: skip link target");
            $this->assertStringContainsString('<html lang="en">', $html);

            $document = new \DOMDocument();
            libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
            libxml_clear_errors();
            $xpath = new \DOMXPath($document);

            // Alt text: every picture has some, and a product picture's is the product name.
            foreach ($xpath->query('//img') as $image) {
                /** @var \DOMElement $image */
                $this->assertNotSame('', trim($image->getAttribute('alt')), "$name: an image has no alt text");
                $this->assertTrue($image->hasAttribute('width') && $image->hasAttribute('height'), "$name: an image has no size");
            }

            // Labels: every field is named by a label, an aria-label, or the label it sits inside.
            foreach ($xpath->query('//input[not(@type="hidden" or @type="submit" or @type="button")]|//select|//textarea') as $field) {
                /** @var \DOMElement $field */
                $id = $field->getAttribute('id');
                $named = $field->hasAttribute('aria-label')
                    || ($id !== '' && $xpath->query('//label[@for="' . $id . '"]')->length > 0)
                    || $xpath->query('ancestor::label', $field)->length > 0;
                $this->assertTrue($named, sprintf('%s: the %s "%s" has no label', $name, $field->nodeName, $field->getAttribute('name')));
            }

            // Headings: one h1, first, and no level skipped on the way down.
            $levels = [];
            foreach ($xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') as $heading) {
                $levels[] = (int) substr($heading->nodeName, 1);
            }
            $this->assertSame(1, $levels[0] ?? null, "$name: the first heading is the h1");
            $this->assertSame(1, count(array_filter($levels, static fn (int $level): bool => $level === 1)), "$name: exactly one h1");
            foreach ($levels as $index => $level) {
                $this->assertLessThanOrEqual(($levels[$index - 1] ?? 0) + 1, $level, "$name: heading order jumps at heading $index (levels " . implode(',', $levels) . ')');
            }
        }

        // A product picture's alt text is the product name; the filter and sort controls have visible labels.
        $this->assertStringContainsString('alt="Photographed"', $pages['detail']);
        $this->assertStringContainsString('<label for="shop-sort">Sort by</label>', $pages['listing']);
        $this->assertStringContainsString('<legend>Brand</legend>', $pages['listing']);
        $this->assertStringContainsString('role="search"', $pages['home']);
    }

    // ---------------------------------------------------------------- home, hooks and overrides

    public function testHomeShowsCategoriesNewestProductsAndTheOrganizationsOwnLine(): void
    {
        $this->bootShop();
        $f = $this->fixture();

        $plain = $this->get("/{$this->slug}/shop")->body();
        $this->assertStringContainsString('<h1 class="shop-hero__title" id="shop-hero-title">Test ' . $this->slug . '</h1>', $plain);
        $this->assertStringContainsString('Browse the full range and find what you need.', $plain, 'a neutral line when the organization sets none');
        $this->assertStringContainsString('href="/' . $this->slug . '/shop/products?category_id=' . $f['appliances'] . '"', $plain, 'top-level category tiles');
        $this->assertStringNotContainsString('category_id=' . $f['fryers'], $plain, 'only top-level categories are tiles');
        $this->assertStringContainsString('3 products', $this->text($plain));
        foreach (['Stand Fan' => 1, 'Fryer Large' => 2, 'Fryer Small' => 3] as $name => $daysAgo) {
            $this->org->prepare("UPDATE products SET created_at = now() - (? || ' days')::interval WHERE name = ?")->execute([$daysAgo, $name]);
        }
        $this->assertSame(['Stand Fan', 'Fryer Large', 'Fryer Small'], $this->names($this->get("/{$this->slug}/shop")->body()), 'New arrivals lists the newest first');

        $this->core->prepare('UPDATE organizations SET settings = ?::jsonb WHERE slug = ?')->execute([json_encode(['shop_tagline' => "Fresh & <b>local</b>\n goods"]), $this->slug]);
        $line = $this->get("/{$this->slug}/shop")->body();
        $this->assertStringContainsString('Fresh &amp; &lt;b&gt;local&lt;/b&gt; goods', $line, 'the organization\'s own line, as plain text');
    }

    public function testAnOrganizationCanReplaceTheShopHomePage(): void
    {
        $this->bootShop();
        $this->addProduct('Anything');
        $directory = dirname(__DIR__, 2) . '/orgs/' . $this->slug . '/shop';

        $this->assertStringContainsString('shop-hero', $this->get("/{$this->slug}/shop")->body(), 'the built-in home page is used first');

        try {
            mkdir($directory, 0775, true);
            file_put_contents($directory . '/home.php', '<?php $this->layout("layout"); $this->start("title"); echo "Custom"; $this->end(); ?><h1>Custom home of <?= $this->e($organizationName) ?></h1>');

            $html = $this->get("/{$this->slug}/shop")->body();
            $this->assertStringContainsString('<h1>Custom home of Test ' . $this->slug . '</h1>', $html);
            $this->assertStringNotContainsString('shop-hero', $html, 'the override replaces the built-in page');
            $this->assertStringContainsString('class="skip-link"', $html, 'but still sits in the Shop layout');
            $this->assertStringContainsString('shop-hero', $this->get('/' . $this->kit->organization('other-' . bin2hex(random_bytes(3)))->slug . '/shop')->body(), 'another organization keeps the built-in page');
        } finally {
            @unlink($directory . '/home.php');
            @rmdir($directory);
            @rmdir(dirname($directory));
        }
        $this->assertDirectoryDoesNotExist(dirname($directory));
    }

    public function testNavigationHookItemsAreCleanedAndTheShopLinkIsFirst(): void
    {
        $hooks = new Hooks();
        $received = [];
        $hooks->add(Hooks::NAVIGATION_SHOP, static function (array $items, array $context) use (&$received): array {
            $received = $context;

            return [...$items,
                ['label' => 'Deals', 'url' => $context['base_url'] . '/products?sort=price'],
                ['label' => 'Away', 'url' => 'https://evil.test/x'],
                ['label' => 'Away too', 'url' => '//evil.test/x'],
                ['label' => 'Script', 'url' => 'javascript:alert(1)'],
                ['label' => '', 'url' => '/blank'],
                'junk',
            ];
        });

        $items = (new ShopNavigationService($hooks))->items('', 'acme', '/acme/shop/products');

        $this->assertSame(['Shop', 'Deals'], array_column($items, 'label'));
        $this->assertSame('/acme/shop/products', $items[0]['url']);
        $this->assertTrue($items[0]['current'], 'the Shop link is current on the listing');
        $this->assertFalse($items[1]['current']);
        $this->assertSame(['organization' => 'acme', 'base_url' => '/acme/shop'], $received, 'an extension receives text only');
    }

    public function testProductSectionsHookIsCleanedGetsOnlyPublicFieldsAndIsPrintedByTheTemplate(): void
    {
        $product = $this->addProductForHooks();
        $hooks = new Hooks();
        $context = [];
        $hooks->add(Hooks::PRODUCT_DETAIL_SECTIONS, static function (array $sections, array $given) use (&$context): array {
            $context = $given;

            return [
                ['id' => 'Care Guide!', 'title' => 'Care <guide>', 'bodyHtml' => '<p class="from-extension">Wash cold</p>'],
                ['id' => '', 'title' => 'Second', 'contentHtml' => '<p>Alt key</p>'],
                ['id' => 'untitled', 'title' => '  ', 'bodyHtml' => 'dropped'],
                'junk',
            ];
        });
        $service = new ShopBrowseService($this->catalog(), $hooks);

        $page = $service->product($product['slug'], null, null);
        $this->assertNotNull($page);
        $this->assertSame(['care-guide', 'section-2'], array_column($page['sections'], 'id'));
        $this->assertSame('Care <guide>', $page['sections'][0]['title']);
        $this->assertFalse($context['signed_in']);
        foreach ($context['product']['variants'] as $variant) {
            foreach (['avg_cost', 'cost_updated_at', 'trade_price', 'reorder_level', 'barcode', 'track_batches', 'on_hand'] as $private) {
                $this->assertArrayNotHasKey($private, $variant, "an extension never receives $private");
            }
            $this->assertArrayHasKey('retail_price', $variant);
            $this->assertArrayHasKey('in_stock', $variant);
        }
        $this->assertArrayNotHasKey('supplier_id', $context['product']);

        // The template prints the title as text and the body as the extension wrote it.
        $root = dirname(__DIR__, 2);
        $view = new AppView(new TemplateLocator($root), 'demo', 'shop', [
            'organizationName' => 'Demo', 'homeUrl' => '/demo/shop', 'productsUrl' => '/demo/shop/products', 'mediaUrl' => '/media/demo',
            'assets' => new AssetUrls($root . '/public', '', 'demo', 'shop'), 'navigation' => [], 'searchText' => '',
        ]);
        $html = $view->render('product', $page);
        $this->assertStringContainsString('<h2 class="product-section__title" id="care-guide-title">Care &lt;guide&gt;</h2>', $html);
        $this->assertStringContainsString('<p class="from-extension">Wash cold</p>', $html);
        $this->assertStringContainsString('id="section-2"', $html);
        $this->assertStringNotContainsString('dropped', $html);
    }

    /** @return array{id: string, slug: string} */
    private function addProductForHooks(): array
    {
        $this->bootShop();
        $product = $this->addProduct('Hooked Thing', ['variants' => [['sku' => 'HK-1', 'name' => 'One', 'retail_price' => '10.00', 'trade_price' => '5.00', 'reorder_level' => '3']]]);
        $this->shop->stock($this->shop->branch(), $this->variantIds($product['id'])[0], '2', '1.00');

        return $product;
    }
}
