<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Tests\Support\ShopTestCase;

/**
 * Dispatches every Shop GET page through the real App + Router + core/routes.php + middleware stack, as a guest and
 * as a signed-in customer, with the ErrorHandler active. A page that returns anything but 200 (or the intended 404),
 * prints a PHP warning or shows error text fails the build, so an unregistered controller, a bad route or a
 * template error cannot slip through.
 */
final class ShopRouterSmokeTest extends ShopTestCase
{
    private const ERROR_TEXT = '/\b(?:Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught|Stack trace)\b|error-page__debug|Undefined (?:variable|array key|property|index)/i';

    public function testEveryShopGetPageLoadsForAGuestAndForASignedInCustomer(): void
    {
        $this->bootShop();
        $root = dirname(__DIR__, 2);
        $seed = require $root . '/database/seeds/demo/0001_development.php';
        $seed($this->org, $this->core, $this->app);
        $this->signedInCustomer();
        $slug = $this->slug;

        // The demo organization has no product photos, so every page below renders the inline placeholder.
        $this->assertSame(0, (int) $this->org->query('SELECT COUNT(*) FROM product_images')->fetchColumn(), 'The demo seed was expected to have no product photos.');

        $options = $this->catalog()->filterOptions([], null, true);
        $this->assertNotSame([], $options['brands'], 'The seed has no brand, so the brand filter would not be covered.');
        $this->assertNotSame([], $options['categories'], 'The seed has no category, so the category filter would not be covered.');
        $brandId = (string) $options['brands'][0]['id'];
        $categoryId = (string) $options['categories'][0]['id'];

        $productSlug = (string) $this->org->query(
            "SELECT p.slug FROM products p WHERE p.status = 'active' AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.status = 'active') ORDER BY p.created_at, p.id LIMIT 1",
        )->fetchColumn();
        $variantId = $this->variantIds((string) $this->org->query('SELECT id FROM products WHERE slug = ' . $this->org->quote($productSlug))->fetchColumn())[0];
        $this->assertNotSame('', $productSlug);

        /** @var list<array{0: string, 1: array<string, mixed>, 2: int}> $pages path, query, expected status */
        $pages = [
            ["/$slug/shop", [], 200],
            ["/$slug/shop/products", [], 200],
            ["/$slug/shop/products", ['q' => 'a'], 200],
            ["/$slug/shop/products", ['q' => 'zzzz-no-such-product'], 200],
            ["/$slug/shop/products", ['sort' => '-newest'], 200],
            ["/$slug/shop/products", ['sort' => 'price'], 200],
            ["/$slug/shop/products", ['sort' => '-price'], 200],
            ["/$slug/shop/products", ['sort' => '-name'], 200],
            ["/$slug/shop/products", ['brand_id' => [$brandId]], 200],
            ["/$slug/shop/products", ['category_id' => $categoryId], 200],
            ["/$slug/shop/products", ['min_price' => '10', 'max_price' => '99999'], 200],
            ["/$slug/shop/products", ['page' => '2', 'per_page' => '5'], 200],
            ["/$slug/shop/products", ['page' => '9999'], 200],
            ["/$slug/shop/products", ['category_id' => 'nope', 'brand_id' => 'nope', 'min_price' => 'abc', 'sort' => 'nope', 'page' => 'x'], 200],
            ["/$slug/shop/products/$productSlug", [], 200],
            ["/$slug/shop/products/$productSlug", ['variant' => $variantId], 200],
            ["/$slug/shop/products/$productSlug", ['variant' => 'not-a-variant'], 200],
            ["/$slug/shop/products/no-such-product", [], 404],
        ];

        $failures = [];
        foreach ([false, true] as $asCustomer) {
            $who = $asCustomer ? 'customer' : 'guest';
            foreach ($pages as [$path, $query, $expected]) {
                $label = sprintf('GET %s %s as %s', $path, $query === [] ? '' : json_encode($query), $who);
                $response = $this->get($path, $query, $asCustomer);
                $body = $response->body();

                if ($response->status() !== $expected) {
                    $debug = preg_match('/<div class="error-page__debug"[^>]*>(.*?)<\/div>/s', $body, $matches) === 1 ? ' | ' . $this->text($matches[1]) : '';
                    $failures[] = sprintf('%s returned HTTP %d, expected %d%s', $label, $response->status(), $expected, $debug);
                    continue;
                }
                if (preg_match(self::ERROR_TEXT, $this->text($body), $found) === 1) {
                    $failures[] = $label . ' shows error text: ' . $found[0];
                    continue;
                }
                if (!str_contains(strtolower($body), '<!doctype html>') || !str_contains($body, 'Skip to the main content') || !str_contains($body, 'shop-placeholder')) {
                    $failures[] = $label . ' did not render the Shop shell with the placeholder picture.';
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function testShopPagesAreAvailableWithoutAnyCatalogData(): void
    {
        $this->bootShop();

        foreach (["/{$this->slug}/shop", "/{$this->slug}/shop/products"] as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response->status(), $path);
            $this->assertDoesNotMatchRegularExpression(self::ERROR_TEXT, $this->text($response->body()), $path);
        }
        $this->assertStringContainsString('Products will appear here soon.', $this->get("/{$this->slug}/shop")->body());
        $this->assertStringContainsString('There are no products to show yet.', $this->get("/{$this->slug}/shop/products")->body());
    }

    public function testAnUnknownOrganizationAndUnknownShopPathsAreNotServerErrors(): void
    {
        $this->bootShop();

        $this->assertSame(404, $this->get('/no-such-org-' . bin2hex(random_bytes(3)) . '/shop')->status());
        $this->assertSame(404, $this->get("/{$this->slug}/shop/nothing-here")->status());
    }
}
