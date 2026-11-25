<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Auth\Session;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\ShopOrderService;
use Gfh\Core\Support\Services;
use Gfh\Tests\Support\ShopTestCase;
use Gfh\Tests\Support\TestKit;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Batch 6.2: the Shop's customer pages through the real router, middleware and services: sign-in, registration, the
 * guards on checkout and the order pages, the customer's own orders and receipt, the reset-password page, the header,
 * and one whole purchase from registration to the Admin order list.
 */
final class ShopAccountFlowTest extends ShopTestCase
{
    private const IP = '203.0.113.9';
    /** A cost that must never appear on any customer page. */
    private const SECRET_COST = '37.31';

    private string $pickup;
    private string $origin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootShop();
        $this->shop->steps();
        $this->pickup = $this->shop->branch('B1', true, false);
        $this->origin = $this->shop->branch('B2', false, true);
    }

    protected function tearDown(): void
    {
        $folder = dirname(__DIR__, 2) . '/orgs/' . $this->slug;
        if (is_dir($folder)) {
            foreach ([$folder . '/shop/order-receipt.php', $folder . '/shop'] as $path) {
                is_file($path) ? unlink($path) : (is_dir($path) ? rmdir($path) : null);
            }
            @rmdir($folder);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function account(string $email, string $name = 'Ana Reyes'): CustomerAccount
    {
        return $this->kit->auth->registerCustomer($name, $email, TestKit::PASSWORD, null, $this->kit->orgCustomers);
    }

    /** @return array{services: Services, session: Session} one browser, signed in as $account or signed out */
    private function browser(?CustomerAccount $account = null, ?string $staffId = null): array
    {
        $session = Session::inMemory('customer', $this->app->config(), $account === null ? [] : ['user_id' => $account->id]);
        $sessions = ['customer' => $session];
        if ($staffId !== null) {
            $sessions['staff'] = Session::inMemory('staff', $this->app->config(), ['user_id' => $staffId]);
        }

        return ['services' => new Services($this->app, false, $this->core, $this->org, testSessions: $sessions), 'session' => $session];
    }

    private function token(array $browser): string
    {
        return $browser['services']->csrf($browser['session'])->token();
    }

    /**
     * A Shop page request. A POST carries the session's CSRF token as the _csrf field unless $csrf is false (none) or a string.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $query
     */
    private function page(array $browser, string $method, string $path, array $form = [], array $query = [], bool|string $csrf = true): Response
    {
        if ($method === 'GET' && $query === []) {
            [$query, $form] = [$form, []];
        }
        if ($method === 'POST' && $csrf !== false) {
            $form['_csrf'] = $csrf === true ? $this->token($browser) : $csrf;
        }

        return $this->app->handle(
            new Request($method, '/' . $this->slug . '/shop' . $path, query: $query, post: $form, cookies: ['gfh_customer' => 'test-session'], server: ['REMOTE_ADDR' => self::IP]),
            $browser['services'],
        );
    }

    /** @param array<string, mixed>|null $payload */
    private function api(array $browser, string $method, string $path, ?array $payload = null): Response
    {
        $server = ['REMOTE_ADDR' => self::IP, 'HTTP_X_CSRF_TOKEN' => $this->token($browser)];
        $reader = null;
        if ($payload !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $reader = static fn (): string => $json;
        }

        return $this->app->handle(new Request($method, '/' . $this->slug . '/api/v1' . $path, server: $server, bodyReader: $reader), $browser['services']);
    }

    /** @return array<string, mixed> */
    private function data(Response $response, int $status = 200): array
    {
        $body = json_decode($response->body(), true);
        $this->assertIsArray($body, $response->body());
        $this->assertSame($status, $response->status(), $response->body());
        $this->assertTrue($body['ok'] ?? null, $response->body());

        return $body['data'];
    }

    /** @return array<string, mixed> */
    private function failure(Response $response, int $status): array
    {
        $body = json_decode($response->body(), true);
        $this->assertIsArray($body, $response->body());
        $this->assertSame($status, $response->status(), $response->body());
        $this->assertFalse($body['ok'] ?? null);

        return $body['error'];
    }

    /** Visible text with a space between tags, so "<dt>Total</dt><dd>₱1</dd>" reads "Total ₱1". */
    private function spaced(string $html): string
    {
        return $this->text(str_replace('><', '> <', $html));
    }

    private function url(string $path): string
    {
        return '/' . $this->slug . '/shop' . $path;
    }

    private function location(Response $response): string
    {
        $this->assertContains($response->status(), [301, 302, 303], 'Expected a redirect, got ' . $response->status() . $this->debug($response));

        return (string) $response->header('Location');
    }

    /** Stocks both branches and returns a sellable variant (price 112.00, cost SECRET_COST). */
    private function stockedVariant(string $quantity = '20', string $name = 'Test Product', array $options = []): string
    {
        $variant = $this->shop->variant($options + ['name' => $name, 'price' => '112.00']);
        $this->shop->stock($this->pickup, $variant, $quantity, self::SECRET_COST);
        $this->shop->stock($this->origin, $variant, $quantity, self::SECRET_COST);

        return $variant;
    }

    private function sku(string $variantId): string
    {
        return (string) $this->shop->scalar('SELECT sku FROM product_variants WHERE id = ?', [$variantId]);
    }

    /** @return list<string> the order ids of an organization customer, newest first */
    private function orderIds(): array
    {
        return array_map('strval', $this->org->query('SELECT id FROM orders ORDER BY placed_at DESC, id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function reserved(string $branchId, string $variantId): string
    {
        return number_format((float) $this->shop->scalar("SELECT COALESCE(SUM(quantity), 0) FROM stock_reservations WHERE branch_id = ? AND variant_id = ? AND status = 'active'", [$branchId, $variantId]), 3, '.', '');
    }

    /** The error summary's sentences on a form page. @return list<string> */
    private function formErrors(Response $response): array
    {
        if (preg_match('/<div class="alert alert--error form-errors"[^>]*>(.*?)<\/ul>/s', $response->body(), $block) !== 1) {
            return [];
        }
        preg_match_all('/<li>(.*?)<\/li>/s', $block[1], $items);

        return array_map(fn (string $html): string => $this->text($html), $items[1]);
    }

    private function resetRequest(string $token): Request
    {
        return new Request('POST', '/' . $this->slug . '/reset-password', post: ['token' => $token, 'password' => 'a-fresh-passphrase-1', 'password_confirm' => 'a-fresh-passphrase-1'], server: ['REMOTE_ADDR' => self::IP]);
    }

    private function deliveryAddress(array $override = []): array
    {
        return $override + ['method' => 'delivery', 'line1' => '12 Rizal Street', 'barangay' => 'Barangay 5', 'city' => 'Bacolod', 'province' => 'Negros Occidental', 'postal_code' => '6100', 'recipient' => 'Ana Reyes', 'phone' => '0917 111 2222'];
    }

    // ------------------------------------------------------------------ sign-in

    public function testSignInSignsTheCustomerInChangesTheSessionIdAndGoesToNextOrTheShop(): void
    {
        $this->account('ana@shop.test');
        $browser = $this->browser();

        $form = $this->page($browser, 'GET', '/sign-in');
        $this->assertSame(200, $form->status());
        $this->assertStringContainsString('name="_csrf"', $form->body());
        $this->assertStringContainsString('name="email"', $form->body());

        $before = $browser['session']->id();
        $response = $this->page($browser, 'POST', '/sign-in', ['email' => 'ana@shop.test', 'password' => TestKit::PASSWORD, 'next' => $this->url('/orders')]);
        $this->assertSame($this->url('/orders'), $this->location($response));
        $this->assertIsString($browser['session']->get('user_id'));
        $this->assertNotSame($before, $browser['session']->id(), 'a new session id after sign-in');

        $other = $this->browser();
        $this->assertSame($this->url(''), $this->location($this->page($other, 'POST', '/sign-in', ['email' => 'ANA@shop.test', 'password' => TestKit::PASSWORD])), 'no next: the Shop home');
    }

    public function testAWrongPasswordAndAnUnknownEmailGiveTheSameMessage(): void
    {
        $this->account('ana@shop.test');

        $wrong = $this->page($this->browser(), 'POST', '/sign-in', ['email' => 'ana@shop.test', 'password' => 'not-the-password']);
        $unknown = $this->page($this->browser(), 'POST', '/sign-in', ['email' => 'nobody@shop.test', 'password' => 'not-the-password']);

        $this->assertSame(401, $wrong->status());
        $this->assertSame(401, $unknown->status());
        $this->assertNotSame([], $this->formErrors($wrong));
        $this->assertSame($this->formErrors($wrong), $this->formErrors($unknown));
        $this->assertStringContainsString('value="ana@shop.test"', $wrong->body(), 'the email is kept, the password never is');
        $this->assertStringNotContainsString('not-the-password', $wrong->body());
    }

    public function testTooManyWrongPasswordsLockSignInWithTheExistingLimit(): void
    {
        $this->account('ana@shop.test');
        $limit = max(1, $this->app->config()->int('LOGIN_MAX_ATTEMPTS', 5));

        for ($attempt = 0; $attempt < $limit; ++$attempt) {
            $this->assertSame(401, $this->page($this->browser(), 'POST', '/sign-in', ['email' => 'ana@shop.test', 'password' => 'wrong-' . $attempt])->status());
        }

        $locked = $this->page($this->browser(), 'POST', '/sign-in', ['email' => 'ana@shop.test', 'password' => TestKit::PASSWORD]);
        $this->assertSame(429, $locked->status(), 'locked out even with the right password');
        $this->assertNotNull($locked->header('Retry-After'));
    }

    public function testSignInWithoutACsrfTokenIsRefusedAndSignsNobodyIn(): void
    {
        $this->account('ana@shop.test');
        $browser = $this->browser();

        foreach ([false, 'a-wrong-token'] as $csrf) {
            $response = $this->page($browser, 'POST', '/sign-in', ['email' => 'ana@shop.test', 'password' => TestKit::PASSWORD], csrf: $csrf);
            $this->assertSame(403, $response->status());
            $this->assertNull($browser['session']->get('user_id'));
        }
    }

    public function testSignOutNeedsCsrfAndClearsTheSession(): void
    {
        $browser = $this->browser($this->account('ana@shop.test'));

        $this->assertSame(403, $this->page($browser, 'POST', '/sign-out', csrf: false)->status());
        $this->assertIsString($browser['session']->get('user_id'), 'a refused sign-out leaves the customer signed in');

        $response = $this->page($browser, 'POST', '/sign-out');
        $this->assertSame($this->url(''), $this->location($response));
        $this->assertNull($browser['session']->get('user_id'));
        $this->assertSame(302, $this->page($browser, 'GET', '/orders')->status(), 'signed out: the order list asks for sign-in again');
        $this->assertSame(405, $this->page($browser, 'GET', '/sign-out')->status(), 'sign-out is never a link');
    }

    public function testASignedInCustomerVisitingSignInIsSentOn(): void
    {
        $browser = $this->browser($this->account('ana@shop.test'));

        $this->assertSame($this->url(''), $this->location($this->page($browser, 'GET', '/sign-in')));
        $this->assertSame($this->url('/orders'), $this->location($this->page($browser, 'GET', '/sign-in', ['next' => $this->url('/orders')])));
        $this->assertSame($this->url(''), $this->location($this->page($browser, 'GET', '/sign-in', ['next' => $this->url('/sign-in')])), 'never back to the sign-in page itself');
    }

    // ------------------------------------------------------------------ registration

    public function testRegistrationShowsInlineErrorsAndKeepsWhatWasTyped(): void
    {
        $browser = $this->browser();

        $empty = $this->page($browser, 'POST', '/register', ['full_name' => '', 'email' => 'not-an-email', 'password' => 'x', 'password_confirmation' => 'x', 'phone' => '12']);
        $this->assertSame(422, $empty->status());
        $body = $empty->body();
        $this->assertStringContainsString('id="field-full_name-error"', $body);
        $this->assertStringContainsString('id="field-email-error"', $body);
        $this->assertStringContainsString('id="field-phone-error"', $body);
        $this->assertStringContainsString('aria-describedby="field-email-error"', $body);
        $this->assertStringContainsString('aria-invalid="true"', $body);
        $this->assertStringContainsString('data-focus-on-load', $body, 'the error summary takes focus');
        $this->assertStringContainsString('value="not-an-email"', $body);

        $mismatch = $this->page($browser, 'POST', '/register', ['full_name' => 'Ben Santos', 'email' => 'ben@shop.test', 'password' => 'a-long-enough-password', 'password_confirmation' => 'something-else-entirely']);
        $this->assertSame(422, $mismatch->status());
        $this->assertStringContainsString('The two passwords do not match.', $mismatch->body());
        $this->assertStringContainsString('id="field-password_confirmation-error"', $mismatch->body());

        $weak = $this->page($browser, 'POST', '/register', ['full_name' => 'Ben Santos', 'email' => 'ben@shop.test', 'password' => 'short', 'password_confirmation' => 'short']);
        $this->assertSame(422, $weak->status());
        $this->assertStringContainsString('id="field-password-error"', $weak->body());
        $this->assertNull($browser['session']->get('user_id'));
        $this->assertSame(0, (int) $this->core->query("SELECT COUNT(*) FROM customers WHERE email = 'ben@shop.test'")->fetchColumn());
    }

    public function testRegistrationCreatesTheAccountAndSignsTheCustomerIn(): void
    {
        $browser = $this->browser();
        $before = $browser['session']->id();

        $response = $this->page($browser, 'POST', '/register', [
            'full_name' => 'Ben Santos', 'email' => 'Ben@Shop.Test', 'phone' => '0918 333 4444',
            'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password', 'next' => $this->url('/checkout'),
        ]);

        $this->assertSame($this->url('/checkout'), $this->location($response));
        $this->assertIsString($browser['session']->get('user_id'));
        $this->assertNotSame($before, $browser['session']->id());
        $this->assertSame(1, (int) $this->core->query("SELECT COUNT(*) FROM customers WHERE email = 'ben@shop.test'")->fetchColumn());
        $this->assertSame(1, (int) $this->org->query("SELECT COUNT(*) FROM customers WHERE email = 'ben@shop.test'")->fetchColumn(), 'linked to the organization customer record');
        $this->assertSame(200, $this->page($browser, 'GET', '/orders')->status(), 'signed in straight away');
    }

    public function testRegisteringAnExistingEmailKeepsTheDocumentedBehaviourAndRevealsOnlyThat(): void
    {
        $this->account('ana@shop.test');
        $browser = $this->browser();

        $response = $this->page($browser, 'POST', '/register', ['full_name' => 'Someone Else', 'email' => 'ana@shop.test', 'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password']);

        // The documented behaviour (docs/api.md): a duplicate email is a 409 that says so, in these words.
        $this->assertSame(409, $response->status());
        $this->assertStringContainsString('An account with this email already exists. Sign in, or ask the shop for a password reset link.', $this->text($response->body()));
        $this->assertStringContainsString('This email already has an account.', $this->text($response->body()));
        $this->assertStringContainsString('Sign in with this email instead', $response->body());
        $this->assertNull($browser['session']->get('user_id'), 'not signed in as the existing account');
        $this->assertSame(1, (int) $this->core->query("SELECT COUNT(*) FROM customers WHERE email = 'ana@shop.test'")->fetchColumn());
        $this->assertStringNotContainsString('Reyes', $response->body(), 'nothing else about the existing account is shown');
    }

    // ------------------------------------------------------------------ next

    /** @return array<string, array{0: string, 1: bool}> */
    public static function nextCases(): array
    {
        return [
            'a Shop page' => ['/SLUG/shop/orders', true],
            'the checkout page with a query' => ['/SLUG/shop/orders?progress_key=ready&page=2', true],
            'an absolute URL' => ['https://evil.example/SLUG/shop/', false],
            'a protocol-relative address' => ['//evil.example/SLUG/shop/', false],
            'a backslash trick' => ['/\\evil.example', false],
            'a backslash inside the path' => ['/SLUG/shop/\\evil.example', false],
            'an encoded slash pair' => ['/SLUG/shop/%2f%2fevil.example', false],
            'an encoded backslash' => ['/SLUG/shop/%5cevil.example', false],
            'an encoded dot segment' => ['/SLUG/shop/%2e%2e/admin', false],
            'a double-encoded dot segment' => ['/SLUG/shop/%252e%252e/admin', false],
            'a plain dot segment' => ['/SLUG/shop/../admin', false],
            'the Admin' => ['/SLUG/admin', false],
            'an Admin path below the Shop prefix' => ['/SLUG/admin/orders', false],
            'another organization' => ['/other-org/shop/orders', false],
            'a look-alike prefix' => ['/SLUG/shopping/orders', false],
            'a javascript address' => ['javascript:alert(1)', false],
            'a newline' => ["/SLUG/shop/orders\r\nSet-Cookie: x=1", false],
            'an encoded newline' => ['/SLUG/shop/orders%0d%0aSet-Cookie:x', false],
            'a fragment' => ['/SLUG/shop/orders#evil', false],
            'an at-sign host trick' => ['/SLUG/shop/@evil.example', false],
            'the sign-in page itself' => ['/SLUG/shop/sign-in', false],
            'the register page itself' => ['/SLUG/shop/register?x=1', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('nextCases')]
    public function testOnlyAShopPathIsAcceptedAsNextAfterSignIn(string $next, bool $accepted): void
    {
        $next = str_replace('SLUG', $this->slug, $next);
        $this->account('ana@shop.test');

        $response = $this->page($this->browser(), 'POST', '/sign-in', ['email' => 'ana@shop.test', 'password' => TestKit::PASSWORD, 'next' => $next]);

        $this->assertSame($accepted ? $next : $this->url(''), $this->location($response));
    }

    public function testNextIsAlsoCheckedOnRegistrationAndCannotBeAnArray(): void
    {
        $response = $this->page($this->browser(), 'POST', '/register', ['full_name' => 'Ben Santos', 'email' => 'ben@shop.test', 'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password', 'next' => '//evil.example']);
        $this->assertSame($this->url(''), $this->location($response));

        $this->account('ana@shop.test');
        $response = $this->page($this->browser(), 'POST', '/sign-in', ['email' => 'ana@shop.test', 'password' => TestKit::PASSWORD, 'next' => ['/x']]);
        $this->assertSame($this->url(''), $this->location($response));
    }

    // ------------------------------------------------------------------ guards

    public function testGuestsAreSentToSignInWithNextOnEveryCustomerPage(): void
    {
        $id = '00000000-0000-4000-8000-000000000001';
        $guest = $this->browser();

        foreach (['/checkout' => [], '/orders' => [], '/orders/' . $id => [], '/orders/' . $id . '/receipt' => []] as $path => $query) {
            $response = $this->page($guest, 'GET', $path, query: $query);
            $this->assertSame($this->url('/sign-in?next=' . rawurlencode($this->url($path))), $this->location($response), $path);
        }

        $filtered = $this->page($guest, 'GET', '/orders', query: ['progress_key' => 'ready', 'page' => '2']);
        $this->assertSame($this->url('/sign-in?next=' . rawurlencode($this->url('/orders?progress_key=ready&page=2'))), $this->location($filtered));
    }

    public function testTheCartPageIsOpenToGuestsAndSignInKeepsItsNext(): void
    {
        $cart = $this->page($this->browser(), 'GET', '/cart');
        $this->assertSame(200, $cart->status());
        $this->assertStringContainsString('data-cart-page', $cart->body());
        $this->assertStringContainsString('You will be asked to sign in', $cart->body());

        $signIn = $this->page($this->browser(), 'GET', '/sign-in', ['next' => $this->url('/checkout')]);
        $this->assertStringContainsString('name="next" value="' . $this->url('/checkout') . '"', $signIn->body());
        $evil = $this->page($this->browser(), 'GET', '/sign-in', ['next' => 'https://evil.example/']);
        $this->assertStringContainsString('name="next" value=""', $evil->body());
    }

    public function testEveryCustomerPageSendsNoStore(): void
    {
        $account = $this->account('ana@shop.test');
        $variant = $this->stockedVariant();
        $browser = $this->browser($account);
        $order = $this->data($this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '1')), 201);

        $paths = ['/sign-in', '/register', '/cart', '/checkout', '/orders', '/orders/' . $order['id'], '/orders/' . $order['id'] . '/receipt', '/orders/00000000-0000-4000-8000-000000000001'];
        foreach ($paths as $path) {
            $signedIn = in_array($path, ['/sign-in', '/register'], true) ? $this->browser() : $browser;
            $response = $this->page($signedIn, 'GET', $path);
            $this->assertStringContainsString('no-store', (string) $response->header('Cache-Control'), $path . ' status ' . $response->status());
        }
        $this->assertStringContainsString('no-store', (string) $this->page($this->browser(), 'GET', '/orders')->header('Cache-Control'), 'the redirect to sign-in too');
    }

    /** @return array<string, mixed> */
    private function checkoutBody(string $variant, string $quantity, ?array $fulfillment = null): array
    {
        return [
            'items' => [['variant_id' => $variant, 'quantity' => $quantity]],
            'fulfillment' => $fulfillment ?? ['method' => 'pickup', 'branch_id' => $this->pickup],
            'checkout' => new \stdClass(),
        ];
    }

    // ------------------------------------------------------------------ orders, detail, receipt

    public function testACustomerSeesOnlyTheirOwnOrdersAndAnotherCustomersOrderIsTheSame404AsAnUnknownOne(): void
    {
        $ana = $this->browser($this->account('ana@shop.test', 'Ana Reyes'));
        $ben = $this->browser($this->account('ben@shop.test', 'Ben Santos'));
        $variant = $this->stockedVariant();

        $anaOrder = $this->data($this->api($ana, 'POST', '/checkout', $this->checkoutBody($variant, '2')), 201);
        $benOrder = $this->data($this->api($ben, 'POST', '/checkout', $this->checkoutBody($variant, '1')), 201);

        $anaList = $this->page($ana, 'GET', '/orders');
        $this->assertSame(200, $anaList->status());
        $this->assertStringContainsString((string) $anaOrder['order_number'], $anaList->body());
        $this->assertStringNotContainsString((string) $benOrder['order_number'], $anaList->body());
        $this->assertStringNotContainsString('Ben Santos', $anaList->body());

        $own = $this->page($ana, 'GET', '/orders/' . $anaOrder['id']);
        $this->assertSame(200, $own->status());
        $this->assertStringContainsString('Order ' . $anaOrder['order_number'], $own->body());

        $unknownId = '00000000-0000-4000-8000-0000000000aa';
        foreach (['' => 'order', '/receipt' => 'receipt'] as $suffix => $what) {
            $theirs = $this->page($ana, 'GET', '/orders/' . $benOrder['id'] . $suffix);
            $unknown = $this->page($ana, 'GET', '/orders/' . $unknownId . $suffix);
            $malformed = $this->page($ana, 'GET', '/orders/not-an-id' . $suffix);
            $this->assertSame(404, $theirs->status(), "another customer's $what");
            $this->assertSame(404, $unknown->status());
            $this->assertSame($unknown->body(), $theirs->body(), "another customer's $what is the same page as an unknown one");
            $this->assertSame($unknown->body(), $malformed->body());
            $this->assertStringNotContainsString((string) $benOrder['order_number'], $theirs->body());
        }
    }

    public function testTheOrderListFiltersByStatusAndPagesAndShowsAnEmptyState(): void
    {
        $account = $this->account('ana@shop.test');
        $browser = $this->browser($account);

        $empty = $this->page($browser, 'GET', '/orders');
        $this->assertSame(200, $empty->status());
        $this->assertStringContainsString('You have not placed an order yet', $empty->body());

        $variant = $this->stockedVariant('50');
        $this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '1'));
        $this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '1'));

        $all = $this->page($browser, 'GET', '/orders');
        $this->assertSame(2, substr_count($all->body(), 'class="order-card"'));
        $this->assertStringContainsString('<option value="placed">Placed</option>', $all->body());

        $filtered = $this->page($browser, 'GET', '/orders', ['progress_key' => 'ready']);
        $this->assertSame(0, substr_count($filtered->body(), 'class="order-card"'));
        $this->assertStringContainsString('No orders with that status', $filtered->body());

        $paged = $this->page($browser, 'GET', '/orders', ['per_page' => '1']);
        $this->assertSame(1, substr_count($paged->body(), 'class="order-card"'));
        $this->assertStringContainsString('Page 1 of 2', $paged->body());

        $bad = $this->page($browser, 'GET', '/orders', ['page' => 'abc', 'per_page' => '0']);
        $this->assertSame(200, $bad->status(), 'a hand-edited address is not an error page');
        $this->assertStringContainsString('Some options in the address were not valid', $bad->body());
    }

    public function testTheOrderPageShowsTotalsFulfilmentPaymentAndTheTimelineWithoutCancelControls(): void
    {
        $account = $this->account('ana@shop.test');
        $browser = $this->browser($account);
        $variant = $this->stockedVariant();

        $order = $this->data($this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '2', $this->deliveryAddress())), 201);
        $html = $this->page($browser, 'GET', '/orders/' . $order['id'], ['placed' => '1'])->body();
        $text = $this->spaced($html);

        $this->assertStringContainsString('Thank you, your order is placed. It is unpaid', $text);
        $this->assertStringContainsString('₱224.00', $text);
        $this->assertStringContainsString('VAT included in the total: ₱24.00', $text);
        $this->assertStringContainsString('12 Rizal Street', $text);
        $this->assertStringContainsString('Bacolod', $text);
        $this->assertMatchesRegularExpression('/Placed \d{2} [A-Z][a-z]{2} \d{4}/', $text, 'dates are DD Mon YYYY');
        $this->assertStringContainsString('aria-current="step"', $html);
        $this->assertStringContainsString('Current step', $text);
        $this->assertStringContainsString('Coming up', $text);
        $this->assertStringContainsString('Print receipt', $text);
        $this->assertStringNotContainsStringIgnoringCase('cancel order', $text);
        $this->assertStringNotContainsString('name="_csrf"', preg_replace('/<header.*?<\/header>/s', '', $html), 'no state-changing control on the page');
        $this->assertStringNotContainsString('Thank you, your order is placed', $this->text($this->page($browser, 'GET', '/orders/' . $order['id'])->body()), 'the placed notice is only for the redirect');

        // A delivery order's path includes the delivery step; a later step marks the earlier ones done.
        $this->org->prepare("UPDATE orders SET progress_key = 'confirmed' WHERE id = ?")->execute([$order['id']]);
        $later = $this->page($browser, 'GET', '/orders/' . $order['id'])->body();
        $this->assertStringContainsString('timeline__step--done', $later);
        $this->assertStringContainsString('timeline__step--current', $later);
    }

    public function testTheReceiptShowsTheVatBreakdownPaymentsAndTheBalanceAndIsPrintReady(): void
    {
        $account = $this->account('ana@shop.test', 'Ana Reyes');
        $browser = $this->browser($account);
        $variant = $this->stockedVariant();
        $order = $this->data($this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '2')), 201);
        $this->org->prepare("INSERT INTO payments (order_id, method, amount, reference) VALUES (?, 'gcash', '100.00', 'GC-REF-1')")->execute([$order['id']]);

        $html = $this->page($browser, 'GET', '/orders/' . $order['id'] . '/receipt')->body();
        $text = $this->spaced($html);

        $this->assertStringContainsString('Order receipt', $text);
        $this->assertStringContainsString('Test ' . $this->slug, $text, 'the organization name');
        $this->assertStringContainsString((string) $order['order_number'], $text);
        $this->assertStringContainsString('Ana Reyes', $text);
        $this->assertStringContainsString('Total (VAT included) ₱224.00', $text);
        $this->assertStringContainsString('Amount without VAT ₱200.00', $text);
        $this->assertStringContainsString('VAT included ₱24.00', $text);
        $this->assertStringContainsString('GCash', $text);
        $this->assertStringContainsString('₱100.00', $text);
        $this->assertStringContainsString('Balance ₱124.00', $text);
        $this->assertStringNotContainsString('GC-REF-1', $html, 'a staff-entered reference is not shown');
        $this->assertStringContainsString('data-print', $html);
        $this->assertStringContainsString('hidden', (string) preg_replace('/^.*?(<button[^>]*data-print[^>]*>).*$/s', '$1', $html));
        $this->assertStringNotContainsStringIgnoringCase('tax invoice', $text);
        $this->assertStringNotContainsStringIgnoringCase('official receipt', $text);
    }

    public function testAnOrganizationCanReplaceTheReceiptFromItsOwnFolder(): void
    {
        $account = $this->account('ana@shop.test');
        $browser = $this->browser($account);
        $variant = $this->stockedVariant();
        $order = $this->data($this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '1')), 201);

        $builtIn = $this->page($browser, 'GET', '/orders/' . $order['id'] . '/receipt')->body();
        $this->assertStringContainsString('Order receipt', $builtIn);

        $folder = dirname(__DIR__, 2) . '/orgs/' . $this->slug . '/shop';
        mkdir($folder, 0775, true);
        file_put_contents($folder . '/order-receipt.php', "<?php\n\$this->layout('layout');\n\$this->start('title'); echo 'Custom'; \$this->end();\n?><p>CUSTOM RECEIPT <?= \$this->e(\$order['order_number']) ?></p>\n");

        $custom = $this->page($browser, 'GET', '/orders/' . $order['id'] . '/receipt');
        $this->assertSame(200, $custom->status(), $this->debug($custom));
        $this->assertStringContainsString('CUSTOM RECEIPT ' . $order['order_number'], $custom->body());
        $this->assertStringNotContainsString('Order receipt', $custom->body(), 'the override replaces the built-in receipt');
    }

    public function testNoCustomerPageShowsACostOrAnotherHostAndEveryUserTextIsEscaped(): void
    {
        $account = $this->account('ana@shop.test', '<b>Ana</b> Reyes');
        $browser = $this->browser($account);
        $variant = $this->stockedVariant('20', '<script>alert(1)</script> Chair');
        $this->org->prepare("UPDATE branches SET name = '<i>Bacolod</i> Store' WHERE id = ?")->execute([$this->pickup]);

        $pickup = $this->data($this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '1')), 201);
        $delivery = $this->data($this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '1', $this->deliveryAddress(['recipient' => '<img src=x onerror=alert(1)>', 'line1' => '"><script>x()</script>']))), 201);

        $paths = ['/cart', '/checkout', '/orders'];
        foreach ([$pickup, $delivery] as $order) {
            array_push($paths, '/orders/' . $order['id'], '/orders/' . $order['id'] . '/receipt');
        }
        $paths[] = '/sign-in';
        $paths[] = '/register';

        foreach ($paths as $path) {
            $signedIn = in_array($path, ['/sign-in', '/register'], true) ? $this->browser() : $browser;
            $response = $this->page($signedIn, 'GET', $path);
            $html = $response->body();
            $this->assertContains($response->status(), [200, 302], $path . $this->debug($response));
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html, $path);
            $this->assertStringNotContainsString('<script>x()</script>', $html, $path);
            $this->assertStringNotContainsString('<img src=x', $html, $path);
            $this->assertStringNotContainsString('<b>Ana</b>', $html, $path);
            $this->assertStringNotContainsString('<i>Bacolod</i>', $html, $path);
            $this->assertStringNotContainsString(self::SECRET_COST, $html, $path . ': a cost must never appear');
            $this->assertStringNotContainsString('unit_cost', $html, $path);
            $this->assertSame(0, preg_match('#(?:href|src|action)="(?:https?:)?//#i', $html), $path . ': no link to another host');
            $this->assertSame(0, preg_match('#<(?:link|script|img|iframe)[^>]+(?:src|href)="https?://#i', $html), $path);
        }

        $detail = $this->page($browser, 'GET', '/orders/' . $delivery['id'])->body();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Chair', $detail);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $detail);
        $receipt = $this->page($browser, 'GET', '/orders/' . $pickup['id'] . '/receipt')->body();
        $this->assertStringContainsString('&lt;b&gt;Ana&lt;/b&gt; Reyes', $receipt);
        $this->assertStringContainsString('&lt;i&gt;Bacolod&lt;/i&gt; Store', $this->page($browser, 'GET', '/orders/' . $pickup['id'])->body());
    }

    // ------------------------------------------------------------------ reset-password page

    public function testTheResetPageOffersTheShopSignInAfterACustomerResetAndKeepsStaffBehaviour(): void
    {
        $organization = $this->kit->organizations->findBySlug($this->slug);
        $admin = $this->kit->member($organization, 'admin@shop.test', 'admin');
        $this->kit->member($organization, 'cashier@shop.test', 'cashier');
        $this->account('ana@shop.test');

        $customerToken = $this->kit->tokenFrom($this->kit->resets->issueForCustomer($admin, $organization, $this->kit->orgCustomers, 'ana@shop.test')['url']);
        $staffToken = $this->kit->tokenFrom($this->kit->resets->issueForStaff($admin, $organization, 'cashier@shop.test')['url']);
        $visitor = $this->browser();

        $customerPage = $this->app->handle($this->resetRequest($customerToken), $visitor['services']);
        $this->assertSame(200, $customerPage->status(), $this->debug($customerPage));
        $this->assertStringContainsString('Password changed', $customerPage->body());
        $this->assertStringContainsString('href="' . $this->url('/sign-in') . '"', $customerPage->body());
        $this->assertStringNotContainsString('/admin/login', $customerPage->body(), 'a customer is never pointed at the Admin');

        $staffPage = $this->app->handle($this->resetRequest($staffToken), $visitor['services']);
        $this->assertSame(200, $staffPage->status());
        $this->assertStringContainsString('href="/' . $this->slug . '/admin/login"', $staffPage->body());
        $this->assertStringContainsString('Go to sign-in', $staffPage->body());
        $this->assertStringNotContainsString('/shop/sign-in', $staffPage->body());

        $signedIn = $this->page($this->browser(), 'POST', '/sign-in', ['email' => 'ana@shop.test', 'password' => 'a-fresh-passphrase-1']);
        $this->assertSame($this->url(''), $this->location($signedIn), 'the customer signs in with the new password');
    }

    // ------------------------------------------------------------------ header

    public function testTheHeaderShowsTheRightLinksForAGuestAndForASignedInCustomer(): void
    {
        $guest = $this->app->handle(new Request('GET', $this->url('')), $this->guestServices)->body();
        $this->assertStringContainsString('href="' . $this->url('/cart') . '"', $guest);
        $this->assertStringContainsString('data-cart-badge', $guest);
        $this->assertStringContainsString('href="' . $this->url('/sign-in') . '"', $guest);
        $this->assertStringContainsString('href="' . $this->url('/register') . '"', $guest);
        $this->assertStringNotContainsString('My orders', $guest);
        $this->assertStringNotContainsString('Sign out', $guest);
        $this->assertStringNotContainsString('data-slot', $guest, 'the empty 6.1 slots are gone');

        $browser = $this->browser($this->account('ana@shop.test'));
        $signedIn = $this->page($browser, 'GET', '')->body();
        $this->assertStringContainsString('href="' . $this->url('/orders') . '"', $signedIn);
        $this->assertStringContainsString('My orders', $signedIn);
        $this->assertStringContainsString('Sign out', $signedIn);
        $this->assertStringContainsString('action="' . $this->url('/sign-out') . '"', $signedIn);
        $this->assertStringContainsString('name="_csrf" value="' . $this->token($browser) . '"', $signedIn);
        $this->assertStringNotContainsString('>Register<', $signedIn);
        $this->assertStringNotContainsString('>Account<', $signedIn, 'the Account link arrives with Batch 6.3b');
        $this->assertStringContainsString('data-shop-slug="' . $this->slug . '"', $signedIn);
    }

    // ------------------------------------------------------------------ the price answer the cart page needs

    public function testThePriceAnswerCarriesTheQuantityRuleAndFlagsEachBadLineByItsVariantId(): void
    {
        $browser = $this->browser();
        $whole = $this->stockedVariant();
        $decimal = $this->shop->variant(['decimals' => true, 'step' => '0.5', 'min' => '0.5', 'unit' => 'kg', 'price' => '80.00']);
        $ghost = '00000000-0000-4000-8000-0000000000bb';

        $ok = $this->data($this->api($browser, 'POST', '/cart/price', ['items' => [['variant_id' => $whole, 'quantity' => '2'], ['variant_id' => $decimal, 'quantity' => '1.5']]]));
        $this->assertSame('224.00', $ok['lines'][0]['line_total']);
        $this->assertFalse($ok['lines'][0]['allow_decimal_qty']);
        $this->assertSame('1.000', $ok['lines'][0]['qty_step']);
        $this->assertTrue($ok['lines'][1]['allow_decimal_qty']);
        $this->assertSame('0.500', $ok['lines'][1]['qty_step']);
        $this->assertSame('0.500', $ok['lines'][1]['min_qty']);
        $this->assertSame('Test Product', $ok['lines'][0]['product_name'], 'names and option labels already come from the server');
        $this->assertSame('Default', $ok['lines'][0]['variant_name']);

        $bad = $this->failure($this->api($browser, 'POST', '/cart/price', ['items' => [['variant_id' => $whole, 'quantity' => '2'], ['variant_id' => $ghost, 'quantity' => '1'], ['variant_id' => $decimal, 'quantity' => '0.7']]]), 422);
        $this->assertSame('Some products in the cart are no longer available.', $bad['fields']['items'], 'the existing key is unchanged');
        $this->assertSame('This product is no longer available.', $bad['fields']['items.' . $ghost]);
        $this->assertSame($this->sku($decimal) . ': Use a multiple of 0.5.', $bad['fields']['items.' . $this->sku($decimal) . '.quantity'], 'the existing key is unchanged');
        $this->assertSame($this->sku($decimal) . ': Use a multiple of 0.5.', $bad['fields']['items.' . $decimal]);
        $this->assertArrayNotHasKey('items.' . $whole, $bad['fields'], 'a good line is not flagged');
    }

    // ------------------------------------------------------------------ the whole purchase

    public function testTheWholePurchaseFromRegistrationToTheAdminOrderList(): void
    {
        $organization = $this->kit->organizations->findBySlug($this->slug);
        $admin = $this->kit->member($organization, 'admin@shop.test', 'admin');
        $variant = $this->stockedVariant('20', 'Walnut Chair');

        // Register on the page: this signs the customer in.
        $browser = $this->browser();
        $register = $this->page($browser, 'POST', '/register', ['full_name' => 'Ana Reyes', 'email' => 'ana@shop.test', 'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password', 'next' => $this->url('/checkout')]);
        $this->assertSame($this->url('/checkout'), $this->location($register));
        $checkout = $this->page($browser, 'GET', '/checkout');
        $this->assertSame(200, $checkout->status());
        $this->assertStringContainsString('data-checkout-page', $checkout->body());

        // The cart is priced by the server.
        $priced = $this->data($this->api($browser, 'POST', '/cart/price', ['items' => [['variant_id' => $variant, 'quantity' => '3']]]));
        $this->assertSame('336.00', $priced['total']);
        $this->assertSame('36.00', $priced['vat_amount']);
        $this->assertSame('300.00', $priced['net_of_vat']);
        $this->assertSame(['pickup_branches', 'delivery_available', 'payment_methods', 'fields'], array_keys($this->data($this->api($browser, 'GET', '/checkout/options'))));

        // Pickup.
        $pickup = $this->data($this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '3')), 201);
        $this->assertSame('336.00', $pickup['total']);
        $this->assertSame('3.000', $this->reserved($this->pickup, $variant));
        $this->assertSame('0.000', $this->reserved($this->origin, $variant));

        // Delivery.
        $delivery = $this->data($this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '2', $this->deliveryAddress())), 201);
        $this->assertSame('224.00', $delivery['total']);
        $this->assertSame('2.000', $this->reserved($this->origin, $variant));
        $this->assertSame('3.000', $this->reserved($this->pickup, $variant), 'the delivery order did not touch the pickup branch');
        $this->assertSame('Bacolod', $delivery['ship_to']['city']);

        // A stock shortage creates nothing and keeps the cart: 422 on items.
        $ordersBefore = count($this->orderIds());
        $short = $this->failure($this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '500')), 422);
        $this->assertStringContainsString('Not enough stock for: ' . $this->sku($variant), $short['fields']['items']);
        $this->assertCount($ordersBefore, $this->orderIds());
        $this->assertSame('3.000', $this->reserved($this->pickup, $variant));

        // The customer sees both on the pages.
        $list = $this->text($this->page($browser, 'GET', '/orders')->body());
        $this->assertStringContainsString((string) $pickup['order_number'], $list);
        $this->assertStringContainsString((string) $delivery['order_number'], $list);
        $this->assertStringContainsString('₱336.00', $list);
        $this->assertStringContainsString('₱224.00', $list);
        $this->assertStringContainsString('Walnut Chair', $this->text($this->page($browser, 'GET', '/orders/' . $pickup['id'])->body()));

        // And Admin sees them, too.
        $staff = $this->browser(null, $admin->id);
        $adminList = $this->app->handle(new Request('GET', '/' . $this->slug . '/api/v1/admin/orders', cookies: ['gfh_staff' => 'test-session'], server: ['REMOTE_ADDR' => self::IP]), $staff['services']);
        $numbers = array_column($this->data($adminList)['items'], 'order_number');
        $this->assertContains($pickup['order_number'], $numbers);
        $this->assertContains($delivery['order_number'], $numbers);
    }

    public function testASignedOutCheckoutCallIsRefusedAndCreatesNothing(): void
    {
        $variant = $this->stockedVariant();
        $guest = $this->browser();

        $response = $this->api($guest, 'POST', '/checkout', $this->checkoutBody($variant, '1'));

        $this->assertSame(401, $response->status());
        $this->assertSame([], $this->orderIds());
    }

    // ------------------------------------------------------------------ cost-leak fix: the customer JSON order routes

    /** Keys a customer must never see at any depth: costs, margins, profit, internal flags and every staff user reference. */
    private const FORBIDDEN_KEY = '/(^|_)(cost|costs|margin|profit)(_|$)|_by$|^staff|_staff|^user_id$|^notes?$|internal|^refund_review$|^cancellation_reason$/i';

    private const STAFF_REASON = 'Staff-only cancellation reason';

    /** @return list<string> the path of every forbidden key, found recursively */
    private function forbiddenKeys(mixed $value, string $path = ''): array
    {
        $found = [];
        if (!is_array($value)) {
            return $found;
        }
        foreach ($value as $key => $child) {
            $here = $path . '/' . $key;
            if (is_string($key) && preg_match(self::FORBIDDEN_KEY, $key) === 1) {
                $found[] = $here;
            }
            $found = [...$found, ...$this->forbiddenKeys($child, $here)];
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $data the decoded data of the response
     * @param array<string, string> $secrets values that must not appear anywhere in the raw body
     */
    private function assertCustomerSafe(array $data, Response $response, array $secrets, string $context): void
    {
        $this->assertSame([], $this->forbiddenKeys($data), $context . ': cost, margin or staff keys in the customer response');
        foreach ($secrets as $label => $secret) {
            $this->assertStringNotContainsString($secret, $response->body(), $context . ': ' . $label);
        }
    }

    /** @return array<string, string> what a customer response must never contain, as raw text */
    private function secretsOf(string $staffId): array
    {
        return ['the staff user id' => $staffId, 'a cost value' => self::SECRET_COST, 'the staff cancellation reason' => self::STAFF_REASON];
    }

    /**
     * Places an order as the customer and, when asked, takes it to a status as a staff member would: a payment by
     * the staff member, then the step (completing it sells the stock and snapshots the cost) or a cancellation.
     *
     * @param array{services: Services, session: Session} $browser
     * @return array<string, mixed> the checkout response
     */
    private function orderIn(string $status, array $browser, string $variant, string $staffId, bool $delivery = false): array
    {
        $order = $this->data($this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '2', $delivery ? $this->deliveryAddress() : null)), 201);
        if ($status === 'placed') {
            return $order;
        }

        $orders = $this->shop->services->orders();
        $orders->recordPayment((string) $order['id'], ['method' => 'cash', 'amount' => '50.00'], $staffId);
        if ($status === 'cancelled') {
            $orders->cancel((string) $order['id'], self::STAFF_REASON, $staffId);
        } else {
            $orders->advance((string) $order['id'], $status, $staffId);
        }

        return $order;
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function orderStatuses(): array
    {
        return [
            'placed' => ['placed', false],
            'confirmed' => ['confirmed', false],
            'ready' => ['ready', false],
            'out for delivery' => ['out_for_delivery', true],
            'completed (fulfilled)' => ['completed', false],
            'completed delivery order' => ['completed', true],
            'cancelled' => ['cancelled', false],
        ];
    }

    #[DataProvider('orderStatuses')]
    public function testTheCustomerOrderEndpointNeverReturnsCostsOrStaffIdsInAnyStatus(string $status, bool $delivery): void
    {
        $admin = $this->kit->member($this->kit->organizations->findBySlug($this->slug), 'admin@shop.test', 'admin');
        $browser = $this->browser($this->account('ana@shop.test'));
        $order = $this->orderIn($status, $browser, $this->stockedVariant(), $admin->id, $delivery);
        $id = (string) $order['id'];

        // The premise: the database really holds the cost and the staff ids that must stay hidden.
        if ($status !== 'placed') {
            $this->assertSame($admin->id, (string) $this->shop->scalar('SELECT recorded_by FROM payments WHERE order_id = ?', [$id]));
        }
        if ($status === 'completed') {
            $this->assertNotNull($this->shop->scalar('SELECT unit_cost FROM order_lines WHERE order_id = ?', [$id]));
            $this->assertSame($admin->id, (string) $this->shop->scalar('SELECT changed_by FROM order_status_history WHERE order_id = ? ORDER BY changed_at DESC, id DESC LIMIT 1', [$id]));
        }

        $response = $this->api($browser, 'GET', '/orders/' . $id);
        $data = $this->data($response);
        $this->assertSame($id, $data['id']);
        $this->assertCustomerSafe($data, $response, $this->secretsOf($admin->id), $status);
    }

    public function testTheCustomerOrderKeepsTheFieldsTheShopAndTheDocsNeed(): void
    {
        $admin = $this->kit->member($this->kit->organizations->findBySlug($this->slug), 'admin@shop.test', 'admin');
        $browser = $this->browser($this->account('ana@shop.test'));
        $variant = $this->stockedVariant();
        $delivery = $this->orderIn('completed', $browser, $variant, $admin->id, true);
        $pickup = $this->orderIn('placed', $browser, $variant, $admin->id);

        $data = $this->data($this->api($browser, 'GET', '/orders/' . $delivery['id']));
        $keys = array_keys($data);
        sort($keys);
        $expected = ['id', 'order_number', 'placed_at', 'fulfillment_method', 'subtotal', 'discount_total', 'delivery_fee', 'assembly_fee', 'installation_fee', 'vat_amount', 'total', 'deposit_due', 'promised_date', 'cancelled_at', 'payment_status', 'progress_key', 'pickup_branch', 'ship_to', 'lines', 'payments', 'history', 'path', 'payment_summary'];
        sort($expected);
        $this->assertSame($expected, $keys, 'the exact customer order shape (docs/api.md)');

        $this->assertSame($delivery['id'], $data['id']);
        $this->assertSame('completed', $data['progress_key']);
        $this->assertSame('delivery', $data['fulfillment_method']);
        $this->assertSame('224.00', $data['total']);
        $this->assertSame('Bacolod', $data['ship_to']['city']);
        $this->assertNull($data['pickup_branch']);

        $this->assertCount(1, $data['lines']);
        $this->assertSame(['name', 'sku', 'qty', 'unit_price', 'line_total', 'is_made_to_order'], array_keys($data['lines'][0]));
        $this->assertSame(['2.000', '112.00', '224.00'], [$data['lines'][0]['qty'], $data['lines'][0]['unit_price'], $data['lines'][0]['line_total']]);

        $this->assertCount(1, $data['payments']);
        $this->assertSame(['method', 'amount', 'paid_at', 'status'], array_keys($data['payments'][0]));
        $this->assertSame(['cash', '50.00', 'recorded'], [$data['payments'][0]['method'], $data['payments'][0]['amount'], $data['payments'][0]['status']]);
        $this->assertSame(['status', 'paid', 'balance', 'deposit_due'], array_keys($data['payment_summary']));
        $this->assertSame('50.00', $data['payment_summary']['paid']);

        $this->assertNotSame([], $data['history']);
        foreach ($data['history'] as $entry) {
            $this->assertSame(['progress_key', 'label', 'changed_at'], array_keys($entry));
        }
        $this->assertContains('completed', array_column($data['history'], 'progress_key'));
        $this->assertSame(['key', 'label', 'maps_to', 'is_terminal', 'current'], array_keys($data['path'][0]));

        $placed = $this->data($this->api($browser, 'GET', '/orders/' . $pickup['id']));
        $this->assertNull($placed['ship_to']);
        $this->assertSame(['name', 'address'], array_keys($placed['pickup_branch']));
    }

    public function testTheOrderListAndTheCheckoutAnswerAreCustomerSafeToo(): void
    {
        $admin = $this->kit->member($this->kit->organizations->findBySlug($this->slug), 'admin@shop.test', 'admin');
        $browser = $this->browser($this->account('ana@shop.test'));
        $variant = $this->stockedVariant();
        $done = $this->orderIn('completed', $browser, $variant, $admin->id);
        $this->orderIn('cancelled', $browser, $variant, $admin->id);

        // POST /checkout: the answer has the same shape as GET /orders/{id} and nothing hidden.
        $response = $this->api($browser, 'POST', '/checkout', $this->checkoutBody($variant, '1'));
        $created = $this->data($response, 201);
        $this->assertCustomerSafe($created, $response, $this->secretsOf($admin->id), 'POST /checkout');
        $this->assertSame($this->data($this->api($browser, 'GET', '/orders/' . $created['id'])), $created);
        $this->assertSame('112.00', $created['total']);

        // GET /orders: one item per order, with exactly the fields of the shop's order list page.
        $response = $this->api($browser, 'GET', '/orders');
        $list = $this->data($response);
        $this->assertCustomerSafe($list, $response, $this->secretsOf($admin->id), 'GET /orders');
        $this->assertCount(3, $list['items']);
        $this->assertArrayHasKey('pagination', $list);
        foreach ($list['items'] as $item) {
            $this->assertSame(ShopOrderService::LIST_FIELDS, array_keys($item));
        }
        $this->assertContains($done['id'], array_column($list['items'], 'id'));
        $this->assertContains(true, array_column($list['items'], 'cancelled'));
    }

    public function testAnotherCustomersOrderIsStillA404AndAGuestIsStillRefusedOnTheCustomerRoutes(): void
    {
        $ana = $this->browser($this->account('ana@shop.test', 'Ana Reyes'));
        $ben = $this->browser($this->account('ben@shop.test', 'Ben Santos'));
        $variant = $this->stockedVariant();
        $benOrder = $this->data($this->api($ben, 'POST', '/checkout', $this->checkoutBody($variant, '1')), 201);

        $theirs = $this->api($ana, 'GET', '/orders/' . $benOrder['id']);
        $unknown = $this->api($ana, 'GET', '/orders/00000000-0000-4000-8000-0000000000aa');
        $this->failure($theirs, 404);
        $this->assertSame($unknown->body(), $theirs->body(), "another customer's order looks like an unknown one");
        $this->assertStringNotContainsString((string) $benOrder['order_number'], $theirs->body());
        $this->assertSame([], $this->data($this->api($ana, 'GET', '/orders'))['items'], 'the list holds only the own orders');

        $guest = $this->browser();
        foreach (['/orders', '/orders/' . $benOrder['id']] as $path) {
            $response = $this->api($guest, 'GET', $path);
            $this->assertSame(401, $response->status(), $path);
            $this->assertStringNotContainsString((string) $benOrder['order_number'], $response->body());
        }
    }

    public function testTheStaffOrderEndpointStillReturnsCostsAndStaffIdsAfterFulfilment(): void
    {
        $admin = $this->kit->member($this->kit->organizations->findBySlug($this->slug), 'admin@shop.test', 'admin');
        $browser = $this->browser($this->account('ana@shop.test'));
        $order = $this->orderIn('completed', $browser, $this->stockedVariant(), $admin->id);

        $staff = $this->browser(null, $admin->id);
        $response = $this->app->handle(new Request('GET', '/' . $this->slug . '/api/v1/admin/orders/' . $order['id'], cookies: ['gfh_staff' => 'test-session'], server: ['REMOTE_ADDR' => self::IP]), $staff['services']);
        $data = $this->data($response);

        $this->assertNotNull($data['lines'][0]['unit_cost'], 'staff still see the cost of goods');
        $this->assertSame($admin->id, $data['payments'][0]['recorded_by'], 'staff still see who recorded a payment');
        $this->assertContains($admin->id, array_column($data['history'], 'changed_by'), 'staff still see who changed a step');
        $this->assertNotSame([], $this->forbiddenKeys($data), 'the staff view is not the customer view');
    }

    public function testTheCustomerApiAndTheShopPagesShareOneWhitelist(): void
    {
        $admin = $this->kit->member($this->kit->organizations->findBySlug($this->slug), 'admin@shop.test', 'admin');
        $account = $this->account('ana@shop.test');
        $browser = $this->browser($account);
        $order = $this->orderIn('completed', $browser, $this->stockedVariant(), $admin->id, true);

        $page = $this->shop->services->shopOrders()->detailFor($account, (string) $order['id']);
        $api = $this->data($this->api($browser, 'GET', '/orders/' . $order['id']));

        // Lines, payments and the delivery address: the page and the API are built from the same field lists.
        $this->assertSame(ShopOrderService::LINE_FIELDS, array_keys($page['lines'][0]));
        $this->assertSame(ShopOrderService::LINE_FIELDS, array_keys($api['lines'][0]));
        $this->assertSame(ShopOrderService::PAYMENT_FIELDS, array_keys($page['payments'][0]));
        $this->assertSame([...ShopOrderService::PAYMENT_FIELDS, ...ShopOrderService::API_PAYMENT_EXTRA], array_keys($api['payments'][0]));
        $this->assertSame(ShopOrderService::SHIP_TO_FIELDS, array_keys($page['ship_to']));
        $this->assertSame(ShopOrderService::SHIP_TO_FIELDS, array_keys($api['ship_to']));
        foreach (ShopOrderService::ORDER_FIELDS as $field) {
            $this->assertArrayHasKey($field, $page, 'the page shows ' . $field);
            $this->assertArrayHasKey($field, $api, 'the API returns ' . $field);
            $this->assertSame($page[$field], $api[$field], $field . ' is the same on the page and in the API');
        }
        $this->assertEqualsCanonicalizing([...ShopOrderService::ORDER_FIELDS, ...ShopOrderService::API_ORDER_EXTRA], array_keys($api), 'the API has no field outside the shared lists');

        // No shared list may name a cost, a margin or a staff reference.
        foreach ([ShopOrderService::ORDER_FIELDS, ShopOrderService::API_ORDER_EXTRA, ShopOrderService::LINE_FIELDS, ShopOrderService::PAYMENT_FIELDS, ShopOrderService::API_PAYMENT_EXTRA, ShopOrderService::SHIP_TO_FIELDS, ShopOrderService::LIST_FIELDS] as $fields) {
            foreach ($fields as $field) {
                $this->assertSame(0, preg_match(self::FORBIDDEN_KEY, $field), $field . ' must never be a customer field');
            }
        }

        // The customer controller reads orders only through ShopOrderService, never straight from OrderService.
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Controller/Api/CartController.php');
        $this->assertStringNotContainsString('->getOrder(', $controller);
        $this->assertStringNotContainsString('->listOrders(', $controller);
        $this->assertStringNotContainsString('return Response::ok($order, 201)', $controller);
    }
}
