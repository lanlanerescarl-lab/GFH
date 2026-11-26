<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Database\Database;
use Gfh\Core\Extension\ExtensionLoader;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Organization;
use Gfh\Core\Tenancy\Tenant;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;

/**
 * Batch 8.3b: the hook pos.receipt.lines runs while the POS snapshot is built. The test-only extension
 * tests/fixtures/extension-root/extensions/pos-warranty prints a warranty line under every product that has the
 * extension-owned attribute ext_warranty_months, and one line under the totals.
 */
final class PosReceiptLinesSnapshotTest extends DatabaseTestCase
{
    private const OLD_KEYS = ['terminal', 'vat_rate_percent', 'payment_methods', 'discount_rules', 'products', 'etag', 'generated_at'];

    private string $branch;
    private string $terminal;
    private string $shift;
    private string $twelve;
    private string $twentyFour;
    private string $none;
    private string $logFile;
    private ?App $app = null;
    private ?Organization $organization = null;
    private ?StaffUser $admin = null;
    private string $slug = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->logFile = dirname(__DIR__, 2) . '/storage/logs/app.log';
        $this->branch = $this->shop->branch('S1');
        $this->terminal = $this->shop->terminal($this->branch, 'T3');
        $this->shift = $this->shop->shift($this->terminal, '1000.00');
        $this->twelve = $this->shop->variant(['sku' => 'FRIDGE-12', 'name' => 'Fridge', 'price' => '18000.00']);
        $this->twentyFour = $this->shop->variant(['sku' => 'AIRCON-24', 'name' => 'Aircon', 'price' => '25000.00']);
        $this->none = $this->shop->variant(['sku' => 'CABLE-1', 'name' => 'Cable', 'price' => '150.00']);
        foreach ([$this->twelve, $this->twentyFour, $this->none] as $variant) {
            $this->shop->stock($this->branch, $variant, '5', '100.00');
        }
        $this->attributes($this->twelve, ['ext_warranty_months' => 12, 'wattage' => '900']);
        $this->attributes($this->twentyFour, ['ext_warranty_months' => 24]);
        unset($GLOBALS['gfh_fixture_receipt_lines'], $GLOBALS['gfh_fixture_receipt_context']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['gfh_fixture_receipt_lines'], $GLOBALS['gfh_fixture_receipt_context']);
        if ($this->app !== null) {
            // Only a test that booted the App installed handlers (tests/Support/ShopTestCase.php does the same).
            restore_error_handler();
            restore_exception_handler();
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string, mixed> $attributes */
    private function attributes(string $variant, array $attributes): void
    {
        $this->org->prepare('UPDATE products SET attributes = ?::jsonb WHERE id = (SELECT product_id FROM product_variants WHERE id = ?)')
            ->execute([json_encode($attributes, JSON_THROW_ON_ERROR), $variant]);
    }

    private function load(Hooks $hooks): void
    {
        $report = (new ExtensionLoader(dirname(__DIR__) . '/fixtures/extension-root'))
            ->load('acme', $hooks, $this->shop->services->settings(), ['pos_warranty']);
        $this->assertSame(['pos-warranty'], $report['loaded']);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return $this->shop->services->pos()->snapshot($this->terminal);
    }

    private function sale(): array
    {
        return [
            'id' => Uuid::v4(),
            'receipt_number' => 'T3-000001',
            'terminal_id' => $this->terminal,
            'shift_id' => $this->shift,
            'sold_at' => (new \DateTimeImmutable())->format('c'),
            'lines' => [['variant_id' => $this->twelve, 'quantity' => '1', 'unit_price' => '18000.00']],
            'payments' => [['method' => 'cash', 'amount' => '18000.00']],
        ];
    }

    // ------------------------------------------------------------------ nothing registered

    public function testWithNoExtensionTheSnapshotHasExactlyTheKeysItAlwaysHad(): void
    {
        $snapshot = $this->snapshot();

        $this->assertSame(self::OLD_KEYS, array_keys($snapshot));
        $this->assertArrayNotHasKey('receipt_lines', $snapshot);
    }

    public function testARegisteredHookThatReturnsNothingValidStillAddsNoKeyAndKeepsTheEtag(): void
    {
        $plain = $this->snapshot();

        $this->shop->services->hooks()->add(Hooks::POS_RECEIPT_LINES, static fn (array $lines): array => [['text' => '<b>no</b>'], ['variant_id' => 'nope', 'text' => 'x'], 'junk']);
        $with = $this->snapshot();

        $this->assertSame(self::OLD_KEYS, array_keys($with));
        $this->assertSame($plain['etag'], $with['etag'], 'nothing added, so the content tag is the one it was');
    }

    // ------------------------------------------------------------------ the warranty extension

    public function testTheExtensionPrintsAWarrantyLineUnderEachWarrantyProductAndOneUnderTheTotals(): void
    {
        $this->load($this->shop->services->hooks());

        $snapshot = $this->snapshot();

        $expected = [...array_slice(self::OLD_KEYS, 0, 5), 'receipt_lines', ...array_slice(self::OLD_KEYS, 5)];
        $this->assertSame($expected, array_keys($snapshot), 'receipt_lines is the only addition, after the products');
        // The variants are a map: the order they come in (the snapshot's product order) does not matter.
        $this->assertEquals([$this->twelve => ['Warranty: 12 months'], $this->twentyFour => ['Warranty: 24 months']], $snapshot['receipt_lines']['variants']);
        $this->assertSame(['Keep this receipt for warranty claims.'], $snapshot['receipt_lines']['footer']);
        $this->assertArrayNotHasKey($this->none, $snapshot['receipt_lines']['variants'], 'a product without a warranty gets no line');
    }

    public function testTheVariantsGoOutAsAnObjectKeyedByVariantAndTheFooterAsAList(): void
    {
        $this->load($this->shop->services->hooks());

        $json = json_encode($this->snapshot(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json);

        $this->assertIsObject($decoded->receipt_lines->variants);
        $this->assertSame(['Warranty: 12 months'], $decoded->receipt_lines->variants->{$this->twelve});
        $this->assertIsArray($decoded->receipt_lines->footer);
    }

    public function testTheContentTagChangesWhenTheWarrantyDataChangesAndOnlyThen(): void
    {
        $this->load($this->shop->services->hooks());
        $first = $this->snapshot()['etag'];

        $this->assertSame($first, $this->snapshot()['etag'], 'the same data gives the same tag');

        $this->attributes($this->twelve, ['ext_warranty_months' => 36]);
        $changed = $this->snapshot();
        $this->assertNotSame($first, $changed['etag'], 'a terminal that downloads again can tell the warranty changed');
        $this->assertSame(['Warranty: 36 months'], $changed['receipt_lines']['variants'][$this->twelve]);
    }

    public function testTheHookGetsTheTerminalAndEveryVariantWithOnlyTheExtensionOwnedAttributes(): void
    {
        $this->load($this->shop->services->hooks());

        $this->snapshot();
        $context = $GLOBALS['gfh_fixture_receipt_context'];

        $this->assertSame(['id', 'code', 'name', 'branch_id', 'branch_name'], array_keys($context['terminal']));
        $this->assertSame($this->terminal, $context['terminal']['id']);
        $this->assertSame('T3', $context['terminal']['code']);
        $this->assertCount(3, $context['variants']);
        $byId = array_column($context['variants'], null, 'variant_id');
        $this->assertSame(['variant_id', 'product_id', 'sku', 'name', 'attributes'], array_keys($byId[$this->twelve]));
        $this->assertSame('FRIDGE-12', $byId[$this->twelve]['sku']);
        $this->assertSame(['ext_warranty_months' => 12], $byId[$this->twelve]['attributes'], 'wattage belongs to the catalog and is not given out');
        $this->assertSame([], $byId[$this->none]['attributes']);
    }

    // ------------------------------------------------------------------ the limits, enforced on the server

    public function testTheLimitsAreEnforcedWhateverTheCallbackReturns(): void
    {
        $GLOBALS['gfh_fixture_receipt_lines'] = [
            ['variant_id' => $this->twelve, 'text' => str_repeat('é', 120)],
            ['variant_id' => $this->twelve, 'text' => str_repeat('x', 121)],
            ['variant_id' => $this->twelve, 'text' => 'Parts <b>only</b>'],
            ['variant_id' => $this->twelve, 'text' => "two\nlines"],
            ['variant_id' => $this->twelve, 'text' => 'second'],
            ['variant_id' => $this->twelve, 'text' => 'third'],
            ['variant_id' => $this->twelve, 'text' => 'fourth is one too many'],
            ['variant_id' => Uuid::v4(), 'text' => 'a variant this terminal does not have'],
            ['text' => 'f1'], ['text' => 'f2'], ['text' => 'f3'], ['text' => 'f4'],
        ];
        $this->load($this->shop->services->hooks());

        $lines = $this->snapshot()['receipt_lines'];

        $this->assertSame([str_repeat('é', 120), 'second', 'third'], $lines['variants'][$this->twelve]);
        $this->assertSame(['f1', 'f2', 'f3'], $lines['footer']);
        $this->assertCount(1, $lines['variants']);
    }

    public function testAnHttpExceptionFromACallbackNeverBreaksTheSnapshot(): void
    {
        $this->shop->services->hooks()->add(Hooks::POS_RECEIPT_LINES, static fn (array $lines): array => throw HttpException::unprocessable(['x' => 'no']));

        $snapshot = $this->snapshot();

        $this->assertSame(self::OLD_KEYS, array_keys($snapshot), 'the terminal still gets its prices and stock');
    }

    // ------------------------------------------------------------------ the sale API is untouched

    public function testTheSaleApiAndTheStoredSaleAreTheSameWithAndWithoutTheExtension(): void
    {
        $pos = $this->shop->services->pos();
        $without = $pos->syncSale($this->sale());
        $storedWithout = $pos->getSale($without['sale_id']);

        $this->load($this->shop->services->hooks());
        $second = $this->sale();
        $second['receipt_number'] = 'T3-000002';
        $with = $pos->syncSale($second);
        $storedWith = $pos->getSale($with['sale_id']);

        $this->assertSame(array_keys($without), array_keys($with));
        $this->assertSame(array_keys($storedWithout), array_keys($storedWith));
        $this->assertSame(array_keys($storedWithout['lines'][0]), array_keys($storedWith['lines'][0]));
        $this->assertStringNotContainsString('Warranty', json_encode($storedWith, JSON_THROW_ON_ERROR), 'what was printed is not stored with the sale');
    }

    // ------------------------------------------------------------------ the real route

    /** @return array{0: Response, 1: array<string, mixed>} */
    private function httpSnapshot(bool $fixture, ?\Closure $extra = null): array
    {
        $app = $this->app ??= App::boot(dirname(__DIR__, 2));
        if ($this->organization === null || $this->admin === null) {
            $this->slug = 'pos-' . bin2hex(random_bytes(3));
            $this->organization = $this->kit->organization($this->slug);
            $this->admin = $this->kit->staff('pat@pos.test', TestKit::PASSWORD, null, 'active', 'Pat Admin');
            $this->kit->join($this->organization, $this->admin, 'admin');
        }
        [$organization, $admin, $slug] = [$this->organization, $this->admin, $this->slug];

        $services = new Services($app, false, $this->core, $this->org, testSessions: [
            'staff' => Session::inMemory('staff', $app->config(), ['user_id' => $admin->id]),
        ]);
        $tenantServices = $services->forTenant(new Tenant($organization, new Database($this->kit->config), null, $this->org));
        if ($fixture) {
            $this->load($tenantServices->hooks());
        }
        if ($extra !== null) {
            $extra($tenantServices->hooks());
        }

        $response = $app->handle(new Request('GET', '/' . $slug . '/api/v1/pos/terminals/' . $this->terminal . '/snapshot'), $services);
        $body = json_decode($response->body(), true);

        return [$response, is_array($body) ? $body : []];
    }

    private function logSize(): int
    {
        clearstatcache(true, $this->logFile);

        return is_file($this->logFile) ? (int) filesize($this->logFile) : 0;
    }

    /** @return list<string> */
    private function logLines(int $offset, string $contains): array
    {
        $text = is_file($this->logFile) ? (string) file_get_contents($this->logFile, false, null, $offset) : '';

        return array_values(array_filter(explode("\n", $text), static fn (string $line): bool => str_contains($line, $contains)));
    }

    public function testTheSnapshotRouteAnswers200WithTheLinesAndWithoutThemWhenNothingIsRegistered(): void
    {
        [$plain, $plainBody] = $this->httpSnapshot(false);
        $this->assertSame(200, $plain->status(), $plain->body());
        $this->assertArrayNotHasKey('receipt_lines', $plainBody['data']);
        $this->assertSame(self::OLD_KEYS, array_keys($plainBody['data']));

        [$with, $withBody] = $this->httpSnapshot(true);
        $this->assertSame(200, $with->status(), $with->body());
        $this->assertSame(['Warranty: 12 months'], $withBody['data']['receipt_lines']['variants'][$this->twelve]);
        $this->assertSame(['Keep this receipt for warranty claims.'], $withBody['data']['receipt_lines']['footer']);
    }

    public function testABrokenCallbackIsSkippedLoggedOnceAndTheTerminalStillGetsItsSnapshot(): void
    {
        $before = $this->logSize();

        [$response, $body] = $this->httpSnapshot(true, static function (Hooks $hooks): void {
            $hooks->add(Hooks::POS_RECEIPT_LINES, static fn (array $lines): array => throw new \RuntimeException('warranty lookup failed'), 5, 'broken-warranty');
            $hooks->add(Hooks::POS_RECEIPT_LINES, static fn (array $lines): string => 'not a list', 30, 'sloppy-warranty');
        });

        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame(['Warranty: 24 months'], $body['data']['receipt_lines']['variants'][$this->twentyFour], 'the good callback still printed its lines');
        $skipped = $this->logLines($before, 'Hook callback skipped: pos.receipt.lines');
        $this->assertCount(2, $skipped);
        $this->assertStringContainsString('broken-warranty', $skipped[0] . $skipped[1]);
        $this->assertStringContainsString('sloppy-warranty', $skipped[0] . $skipped[1]);
        $this->assertStringNotContainsString('Stack trace', implode("\n", $skipped));
    }

    public function testAtMostFiveBadLinesAreLoggedAndTheRestIsCountedInOneLine(): void
    {
        $GLOBALS['gfh_fixture_receipt_lines'] = array_map(static fn (int $i): array => ['text' => "bad <b>$i</b>"], range(1, 9));
        $before = $this->logSize();

        [$response] = $this->httpSnapshot(true);

        $this->assertSame(200, $response->status());
        $lines = $this->logLines($before, 'Hook value problem: pos.receipt.lines');
        $this->assertCount(6, $lines, 'five reasons and one closing count');
        $this->assertStringContainsString('4 more receipt lines were ignored', $lines[5]);
    }
}
