<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Auth\Session;
use Gfh\Core\Database\Database;
use Gfh\Core\Extension\ExtensionLoader;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Tenant;
use Gfh\Tests\Support\ShopTestCase;
use Gfh\Tests\Support\TestKit;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Batch 8.3 Part B: extension checkout fields through the real router and services. The fixture extension
 * tests/fixtures/extension-root/extensions/checkout-fixture declares one field of each type (and some bad
 * definitions) and a fee that depends on a value; the care-notes extension is not involved.
 */
final class CheckoutFieldsTest extends ShopTestCase
{
    private const IP = '203.0.113.9';

    private string $pickup;
    private string $variant;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootShop();
        $this->logFile = dirname(__DIR__, 2) . '/storage/logs/app.log';
        $this->shop->steps();
        $this->pickup = $this->shop->branch('B1', true, false);
        $this->variant = $this->shop->variant(['name' => 'Fixture Lamp', 'price' => '112.00']);
        $this->shop->stock($this->pickup, $this->variant, '20', '37.31');
        $GLOBALS['gfh_fixture_checkout_seen'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['gfh_fixture_checkout_seen']);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{services: Services, session: Session} */
    private function browser(bool $withFixture, ?CustomerAccount $account = null, ?\Closure $extra = null): array
    {
        $session = Session::inMemory('customer', $this->app->config(), $account === null ? [] : ['user_id' => $account->id]);
        $services = new Services($this->app, false, $this->core, $this->org, testSessions: ['customer' => $session]);

        if ($withFixture || $extra !== null) {
            $tenant = new Tenant($this->kit->organizations->findBySlug($this->slug), new Database($this->kit->config), null, $this->org);
            $tenantServices = $services->forTenant($tenant);
            if ($withFixture) {
                $report = (new ExtensionLoader(dirname(__DIR__) . '/fixtures/extension-root'))
                    ->load($this->slug, $tenantServices->hooks(), $tenantServices->settings(), ['checkout_fixture']);
                $this->assertSame(['checkout-fixture'], $report['loaded']);
            }
            if ($extra !== null) {
                $extra($tenantServices->hooks());
            }
        }

        return ['services' => $services, 'session' => $session];
    }

    private function customer(bool $withFixture = true, ?\Closure $extra = null): array
    {
        $account = $this->kit->auth->registerCustomer('Ana Reyes', 'ana-' . bin2hex(random_bytes(3)) . '@shop.test', TestKit::PASSWORD, null, $this->kit->orgCustomers);

        return $this->browser($withFixture, $account, $extra);
    }

    /** @param array<string, mixed>|null $payload */
    private function api(array $browser, string $method, string $path, ?array $payload = null): Response
    {
        $server = ['REMOTE_ADDR' => self::IP, 'HTTP_X_CSRF_TOKEN' => $browser['services']->csrf($browser['session'])->token()];
        $reader = null;
        if ($payload !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $reader = static fn (): string => $json;
        }

        return $this->app->handle(new Request($method, '/' . $this->slug . '/api/v1' . $path, server: $server, bodyReader: $reader, cookies: ['gfh_customer' => 'test-session']), $browser['services']);
    }

    /** @return array<string, mixed> */
    private function ok(Response $response, int $status = 200): array
    {
        $body = json_decode($response->body(), true);
        $this->assertIsArray($body, $response->body());
        $this->assertSame($status, $response->status(), $response->body());

        return $body['data'];
    }

    /** @return array<string, string> the `fields` of a 422 */
    private function refused(Response $response): array
    {
        $body = json_decode($response->body(), true);
        $this->assertSame(422, $response->status(), $response->body());
        $this->assertFalse($body['ok']);
        $this->assertSame('validation_failed', $body['error']['code']);

        return $body['error']['fields'];
    }

    /** @param array<string, mixed> $checkout */
    private function body(array|\stdClass $checkout): array
    {
        return [
            'items' => [['variant_id' => $this->variant, 'quantity' => '1']],
            'fulfillment' => ['method' => 'pickup', 'branch_id' => $this->pickup],
            'checkout' => $checkout,
        ];
    }

    private function orderCount(): int
    {
        return (int) $this->shop->scalar('SELECT COUNT(*) FROM orders');
    }

    private function logSize(): int
    {
        clearstatcache(true, $this->logFile);

        return is_file($this->logFile) ? (int) filesize($this->logFile) : 0;
    }

    /** @return list<string> */
    private function problemLines(int $offset): array
    {
        $text = is_file($this->logFile) ? (string) file_get_contents($this->logFile, false, null, $offset) : '';

        return array_values(array_filter(explode("\n", $text), static fn (string $line): bool => str_contains($line, 'Hook value problem: checkout.fields')));
    }

    /** @return array<string, string|bool> what the checkout.adjustments hook received in its last call */
    private function lastSeen(): array
    {
        $this->assertNotSame([], $GLOBALS['gfh_fixture_checkout_seen'], 'the checkout.adjustments hook did not run');

        return end($GLOBALS['gfh_fixture_checkout_seen']);
    }

    private const GOOD = ['gift_note' => 'For Ben', 'company' => 'Acme Ltd', 'slot' => 'am', 'gift_wrap' => true];

    // ------------------------------------------------------------------ no fields: exactly as before

    public function testWithNoFieldsTheOptionsThePageAndTheOrderAreExactlyAsBefore(): void
    {
        $guest = $this->browser(false);
        $options = $this->ok($this->api($guest, 'GET', '/checkout/options'));
        $this->assertSame([], $options['fields']);
        $this->assertSame(['pickup_branches', 'delivery_available', 'payment_methods', 'fields'], array_keys($options));

        $customer = $this->customer(false);
        $page = $this->app->handle(new Request('GET', '/' . $this->slug . '/shop/checkout', cookies: ['gfh_customer' => 'test-session']), $customer['services']);
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('data-checkout-form', $page->body());
        $this->assertStringNotContainsString('checkout-ext-', $page->body());

        $order = $this->ok($this->api($customer, 'POST', '/checkout', $this->body(new \stdClass())), 201);
        $this->assertSame('112.00', $order['total']);
        $this->assertSame(1, $this->orderCount());
        $this->assertSame([], $GLOBALS['gfh_fixture_checkout_seen']);
    }

    public function testWithNoFieldsAnyPostedIdIsRefusedAndNothingIsOrdered(): void
    {
        $fields = $this->refused($this->api($this->customer(false), 'POST', '/checkout', $this->body(['promo' => 'x'])));

        $this->assertSame(['checkout.promo' => 'This field is not part of the checkout.'], $fields);
        $this->assertSame(0, $this->orderCount());
    }

    // ------------------------------------------------------------------ the definitions

    public function testTheOptionsHoldTheGoodDefinitionsInTheirNormalizedShapeAndTheBadOnesAreLoggedOnce(): void
    {
        $before = $this->logSize();
        $guest = $this->browser(true);

        $options = $this->ok($this->api($guest, 'GET', '/checkout/options'));
        // The page reads the options again on every visit; the same request object reads the hook only once per use here.
        $this->ok($this->api($guest, 'GET', '/checkout/options'));

        $this->assertSame(['gift_note', 'company', 'slot', 'gift_wrap'], array_column($options['fields'], 'id'));
        $this->assertSame([
            'id' => 'gift_note', 'label' => 'Gift note', 'type' => 'textarea', 'required' => false, 'options' => [], 'help' => 'Printed on a card.', 'max_length' => 20,
        ], $options['fields'][0]);
        $this->assertSame(['id', 'label', 'type', 'required', 'options', 'help', 'max_length'], array_keys($options['fields'][1]));
        $this->assertTrue($options['fields'][1]['required']);
        $this->assertSame(150, $options['fields'][1]['max_length']);
        $this->assertSame([['value' => 'am', 'label' => 'Morning'], ['value' => 'pm', 'label' => 'Afternoon']], $options['fields'][2]['options']);
        $this->assertNull($options['fields'][3]['max_length']);

        $lines = $this->problemLines($before);
        $this->assertCount(5, $lines, 'five bad definitions, each logged once even though the options were read twice');
        foreach (['id must be', 'type must be', 'a select needs', 'already used', 'not an object'] as $reason) {
            $this->assertCount(1, array_filter($lines, static fn (string $line): bool => str_contains($line, $reason)), $reason);
        }
    }

    public function testTheCheckoutPageStillAnswers200WithFieldsAndTheShellIsTheSame(): void
    {
        $customer = $this->customer();

        $page = $this->app->handle(new Request('GET', '/' . $this->slug . '/shop/checkout', cookies: ['gfh_customer' => 'test-session']), $customer['services']);

        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('data-checkout-form', $page->body());
        $this->assertStringContainsString('checkout-fields.js', (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/shop/js/checkout.js'), 'the script that renders them is published');
    }

    public function testAThrowingOrSloppyFieldsCallbackLeavesTheOtherFieldsAndTheCheckoutWorking(): void
    {
        $guest = $this->browser(true, null, static function (Hooks $hooks): void {
            $hooks->add(Hooks::CHECKOUT_FIELDS, static fn (array $fields): array => throw new \RuntimeException('fields exploded'), 5, 'broken-fields');
            $hooks->add(Hooks::CHECKOUT_FIELDS, static fn (array $fields): string => 'not a list', 30, 'sloppy-fields');
        });

        $options = $this->ok($this->api($guest, 'GET', '/checkout/options'));

        $this->assertSame(['gift_note', 'company', 'slot', 'gift_wrap'], array_column($options['fields'], 'id'));
    }

    // ------------------------------------------------------------------ the order

    public function testValidValuesPlaceTheOrderAndReachTheAdjustmentsHookAndTheFeeThatDependsOnThem(): void
    {
        $order = $this->ok($this->api($this->customer(), 'POST', '/checkout', $this->body(self::GOOD + ['company' => '  Acme Ltd  '])), 201);

        $this->assertSame(self::GOOD, $this->lastSeen(), 'the hook gets the checked values: trimmed, typed, every declared id');
        $this->assertSame('30.00', $order['assembly_fee']);
        $this->assertSame('142.00', $order['total'], '112.00 + the 30.00 the hook adds for a ticked gift wrap');
        $this->assertSame(1, $this->orderCount());
    }

    public function testBlankOptionalValuesAreStillPassedAsBlank(): void
    {
        $this->ok($this->api($this->customer(), 'POST', '/checkout', $this->body(['company' => 'Acme'])), 201);

        $this->assertSame(['gift_note' => '', 'company' => 'Acme', 'slot' => '', 'gift_wrap' => false], $this->lastSeen());
    }

    public function testTheValuesAreNotStoredWithTheOrder(): void
    {
        $this->ok($this->api($this->customer(), 'POST', '/checkout', $this->body(self::GOOD)), 201);

        // The schema has no per-order extension data and Batch 8.3 adds no migration: the values go to the hook only.
        $row = (string) $this->shop->scalar('SELECT row_to_json(o)::text FROM orders o');
        foreach (['Acme Ltd', 'For Ben', 'gift_wrap'] as $value) {
            $this->assertStringNotContainsString($value, $row);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> field, message */
    public static function refusals(): iterable
    {
        yield 'a required field is missing' => [['gift_note' => 'x'], 'checkout.company', 'Company is required.'];
        yield 'a required field is blank' => [['company' => '   '], 'checkout.company', 'Company is required.'];
        yield 'a select value that is not an option' => [['company' => 'A', 'slot' => 'midnight'], 'checkout.slot', 'Choose one of the listed options for Time slot.'];
        yield 'text over the limit' => [['company' => 'A', 'gift_note' => str_repeat('n', 21)], 'checkout.gift_note', 'Gift note must be at most 20 characters.'];
        yield 'text with a tag' => [['company' => '<b>A</b>'], 'checkout.company', 'Company must be plain text: no tags and no control characters.'];
        yield 'text with a control character' => [['company' => "A\x07"], 'checkout.company', 'Company must be plain text: no tags and no control characters.'];
        yield 'a number for text' => [['company' => 5], 'checkout.company', 'Company must be plain text.'];
        yield 'a checkbox with an odd value' => [['company' => 'A', 'gift_wrap' => 'maybe'], 'checkout.gift_wrap', 'Gift wrap must be ticked or left empty.'];
        yield 'an unknown id' => [['company' => 'A', 'surprise' => 'x'], 'checkout.surprise', 'This field is not part of the checkout.'];
    }

    /** @param array<string, mixed> $checkout */
    #[DataProvider('refusals')]
    public function testABadValueIsRefusedWithItsMessageAndNothingIsOrderedOrReserved(array $checkout, string $field, string $message): void
    {
        $fields = $this->refused($this->api($this->customer(), 'POST', '/checkout', $this->body($checkout)));

        $this->assertSame($message, $fields[$field]);
        $this->assertSame(0, $this->orderCount());
        $this->assertSame('0', (string) $this->shop->scalar("SELECT COALESCE(SUM(quantity), 0)::int FROM stock_reservations WHERE status = 'active'"));
        $this->assertSame([], $GLOBALS['gfh_fixture_checkout_seen'], 'the pricing hook never saw a bad value');
    }

    public function testAnUnknownIdWithAnOddNameIsReportedWithoutEchoingIt(): void
    {
        $fields = $this->refused($this->api($this->customer(), 'POST', '/checkout', $this->body(['company' => 'A', '<script>alert(1)</script>' => 'x'])));

        $this->assertSame(['checkout' => 'This field is not part of the checkout.'], $fields);
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        $fields = $this->refused($this->api($this->customer(), 'POST', '/checkout', $this->body(['slot' => 'noon', 'surprise' => 'x'])));

        $this->assertSame(['checkout.surprise', 'checkout.company', 'checkout.slot'], array_keys($fields));
    }

    // ------------------------------------------------------------------ the price call

    public function testAPriceCallNeverFailsForAHalfFilledFormAndTheHookOnlySeesValidValues(): void
    {
        $customer = $this->customer();
        $price = [
            'items' => [['variant_id' => $this->variant, 'quantity' => '1']],
            'fulfillment' => ['method' => 'pickup', 'branch_id' => $this->pickup],
            'checkout' => ['company' => '<b>x</b>', 'slot' => 'noon', 'gift_wrap' => true, 'surprise' => 'hi'],
        ];

        $cart = $this->ok($this->api($customer, 'POST', '/cart/price', $price));

        $this->assertSame(['gift_note' => '', 'company' => '', 'slot' => '', 'gift_wrap' => true], $this->lastSeen(), 'bad values are blank, unknown ids are gone');
        $this->assertSame('30.00', $cart['assembly_fee'], 'a valid value still changes the price shown');
        $this->assertSame('142.00', $cart['total']);

        // Nothing at all posted: still 200, and the hook sees every declared id blank.
        $empty = $this->ok($this->api($customer, 'POST', '/cart/price', ['items' => $price['items']]));
        $this->assertSame('112.00', $empty['total']);
        $this->assertSame(['gift_note' => '', 'company' => '', 'slot' => '', 'gift_wrap' => false], $this->lastSeen());
    }

    public function testThePriceShownIsThePriceCharged(): void
    {
        $customer = $this->customer();
        $price = $this->ok($this->api($customer, 'POST', '/cart/price', $this->body(self::GOOD)));
        $order = $this->ok($this->api($customer, 'POST', '/checkout', $this->body(self::GOOD)), 201);

        $this->assertSame($price['total'], $order['total']);
        $this->assertSame($price['assembly_fee'], $order['assembly_fee']);
    }
}
