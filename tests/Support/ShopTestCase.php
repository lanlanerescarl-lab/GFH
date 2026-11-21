<?php

declare(strict_types=1);

namespace Gfh\Tests\Support;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Repository\CustomerAccountRepository;
use Gfh\Core\Service\CatalogService;
use Gfh\Core\Support\Services;

/**
 * Base class of the Shop page tests: boots the real App, router and middleware for a throwaway organization, and
 * loads Shop pages as a guest or as a signed-in customer, with the ErrorHandler active. Everything runs inside the
 * transaction DatabaseTestCase rolls back.
 */
abstract class ShopTestCase extends DatabaseTestCase
{
    protected App $app;
    protected string $slug;
    protected Services $guestServices;
    protected Services $customerServices;
    /** @var array<string, mixed> the organization customer row of the signed-in customer, once signedInCustomer() ran */
    protected array $customer = [];

    protected function bootShop(): void
    {
        $this->slug = 'shop-' . bin2hex(random_bytes(3));
        $this->kit->organization($this->slug);
        $this->app = App::boot(dirname(__DIR__, 2));
        $this->guestServices = new Services($this->app, false, $this->core, $this->org);
        $this->customerServices = $this->guestServices;
    }

    /**
     * Creates a customer account (core) linked to an organization customer with the given tier and type, and makes
     * get(..., asCustomer: true) load pages as that customer.
     */
    protected function signedInCustomer(?string $tier = null, ?string $type = null, ?string $email = null): void
    {
        $email ??= 'shopper-' . bin2hex(random_bytes(4)) . '@example.test';
        $coreId = (new CustomerAccountRepository($this->core))->create('Test Shopper', $email, null, 'not-a-real-hash');
        $this->customer = $this->shop->customer($email, $tier, $type);
        $this->org->prepare('UPDATE customers SET core_customer_id = ? WHERE id = ?')->execute([$coreId, $this->customer['id']]);

        $session = Session::inMemory('customer', $this->app->config(), ['user_id' => $coreId]);
        $this->customerServices = new Services($this->app, false, $this->core, $this->org, testSessions: ['customer' => $session]);
    }

    /** @param array<string, mixed> $query */
    protected function get(string $path, array $query = [], bool $asCustomer = false): Response
    {
        $services = $asCustomer ? $this->customerServices : $this->guestServices;
        // The optional customer session middleware only opens a session when the cookie is present.
        $cookies = $asCustomer ? ['gfh_customer' => 'test-session'] : [];

        return $this->app->handle(new Request('GET', $path, query: $query, cookies: $cookies), $services);
    }

    /** The error page's debug text (if the response is an error page), for a failure message. */
    protected function debug(Response $response): string
    {
        return preg_match('/<div class="error-page__debug"[^>]*>(.*?)<\/div>/s', $response->body(), $matches) === 1 ? ' | ' . $this->text($matches[1]) : '';
    }

    protected function catalog(): CatalogService
    {
        return $this->shop->services->catalog();
    }

    /**
     * Creates a product through the catalog service.
     *
     * @param array<string, mixed> $options description, brand_id, category_id, status, attributes, variants
     * @return array{id: string, slug: string}
     */
    protected function addProduct(string $name, array $options = []): array
    {
        return $this->catalog()->createProduct([
            'name' => $name,
            'status' => $options['status'] ?? 'active',
            'description' => $options['description'] ?? null,
            'brand_id' => $options['brand_id'] ?? null,
            'category_id' => $options['category_id'] ?? null,
            'attributes' => $options['attributes'] ?? [],
            'variants' => $options['variants'] ?? [['sku' => strtoupper(substr(md5($name), 0, 8)), 'name' => 'Default', 'retail_price' => '100.00']],
        ], null);
    }

    /** @return list<string> the variant ids of a product, oldest first */
    protected function variantIds(string $productId): array
    {
        $statement = $this->org->prepare('SELECT id FROM product_variants WHERE product_id = ? ORDER BY created_at, name');
        $statement->execute([$productId]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Gives a product a photo (a media row; the file itself is never read by a page, only linked). */
    protected function addPhoto(string $productId, bool $primary): string
    {
        $mediaId = $this->shop->scalar(
            "INSERT INTO media (path, original_name, mime, bytes) VALUES (?, 'photo.jpg', 'image/jpeg', 100) RETURNING id",
            ['test/' . bin2hex(random_bytes(4)) . '.jpg'],
        );
        $this->catalog()->attachImage($productId, (string) $mediaId, null, $primary);

        return (string) $mediaId;
    }

    /** The visible text of a page, for checking what a visitor would read. */
    protected function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
