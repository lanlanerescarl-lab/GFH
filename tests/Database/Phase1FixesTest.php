<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Support\Format;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Http\Request;
use Gfh\Tests\Support\DatabaseTestCase;

/**
 * Stage 5, Phase 1 fixes from the owner's browser testing of Batches 5.1 to 5.3:
 * sent date, average cost, receive validation, partial payment display, payment wording, order history,
 * and the POS review rules the owner asked to see verified.
 */
final class Phase1FixesTest extends DatabaseTestCase
{
    private string $pickup = '';
    private string $origin = '';
    private string $plain = '';
    private string $tracked = '';
    private string $supplier = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop->steps();
        $this->pickup = $this->shop->branch('P1', true, false);
        $this->origin = $this->shop->branch('D1', false, true);
        $this->plain = $this->shop->variant(['sku' => 'PLAIN-1', 'price' => '112.00']);
        $this->tracked = $this->shop->variant(['sku' => 'TRACK-1', 'price' => '112.00', 'track_batches' => true]);
        $this->shop->stock($this->pickup, $this->plain, '10', '50.00');
        $this->supplier = $this->shop->services->procurement()->createSupplier(['name' => 'Phase One Supplies'], null)['id'];
    }

    /** @return array<string, mixed> a sent purchase order with 20 plain and 20 tracked units ordered */
    private function sentPurchaseOrder(): array
    {
        $procurement = $this->shop->services->procurement();
        $created = $procurement->createPurchaseOrder([
            'supplier_id' => $this->supplier,
            'branch_id' => $this->pickup,
            'lines' => [
                ['variant_id' => $this->plain, 'quantity' => '20', 'unit_cost' => '40.00'],
                ['variant_id' => $this->tracked, 'quantity' => '20', 'unit_cost' => '60.00'],
            ],
        ], null);
        $procurement->sendPurchaseOrder($created['id'], null);

        return $procurement->getPurchaseOrder($created['id']);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function lineOf(array $order, string $variantId): array
    {
        foreach ($order['lines'] as $line) {
            if ($line['variant_id'] === $variantId) {
                return $line;
            }
        }

        throw new \RuntimeException('No line for that variant.');
    }

    private function today(int $offsetDays = 0): string
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));

        return $today->modify(sprintf('%+d days', $offsetDays))->format('Y-m-d');
    }

    // ------------------------------------------------------------------ 1. sent date

    public function testSendingAPurchaseOrderSavesTheSentDate(): void
    {
        $procurement = $this->shop->services->procurement();
        $created = $procurement->createPurchaseOrder([
            'supplier_id' => $this->supplier,
            'branch_id' => $this->pickup,
            'lines' => [['variant_id' => $this->plain, 'quantity' => '2', 'unit_cost' => '40.00']],
        ], null);

        $this->assertNull($procurement->getPurchaseOrder($created['id'])['sent_at'], 'a draft has not been sent');

        $procurement->sendPurchaseOrder($created['id'], null);
        $sent = $procurement->getPurchaseOrder($created['id']);

        $this->assertSame('sent', $sent['status']);
        $this->assertNotNull($sent['sent_at']);
        $this->assertNotSame('—', Format::dateTime((string) $sent['sent_at']), 'the saved value is a date the page can show');
    }

    public function testMigrationBackfillGivesAlreadySentOrdersTheirRealSentDate(): void
    {
        $procurement = $this->shop->services->procurement();
        $created = $procurement->createPurchaseOrder([
            'supplier_id' => $this->supplier,
            'branch_id' => $this->pickup,
            'lines' => [['variant_id' => $this->plain, 'quantity' => '2', 'unit_cost' => '40.00']],
        ], null);
        $id = $created['id'];

        // An order sent before migration 0007: sent status, no date, but an audit entry.
        $this->org->prepare("UPDATE purchase_orders SET status = 'sent', sent_at = NULL WHERE id = ?")->execute([$id]);
        $this->org->prepare("INSERT INTO audit_log (occurred_at, action, entity, entity_id) VALUES ('2026-03-04 10:00:00+08', 'po.sent', 'purchase_order', ?)")->execute([$id]);

        $migration = (string) file_get_contents(dirname(__DIR__, 2) . '/database/org/migrations/0007_purchase_order_sent_at.sql');
        $this->assertSame(1, preg_match('/UPDATE purchase_orders p.*?;/s', $migration, $matches), 'the backfill statement is in the migration');
        $this->org->exec($matches[0]);

        $this->assertSame('2026-03-04', (string) $this->shop->scalar("SELECT (sent_at AT TIME ZONE 'Asia/Manila')::date FROM purchase_orders WHERE id = ?", [$id]));
    }

    // ------------------------------------------------------------------ 2. average cost

    public function testAverageCostIsStoredExactlyAndShownRoundedHalfUp(): void
    {
        $variant = $this->shop->variant(['sku' => 'AVG-1']);
        $this->shop->stock($this->pickup, $variant, '1', '124.00');
        $this->shop->stock($this->pickup, $variant, '2', '125.00');

        $stored = (string) $this->shop->scalar('SELECT avg_cost FROM product_variants WHERE id = ?', [$variant]);
        $this->assertSame('124.6667', $stored, 'the stored value keeps four decimals: (1 x 124 + 2 x 125) / 3');
        $this->assertSame('₱124.67', Format::peso($stored), 'the display rounds half up, it does not cut off');
    }

    // ------------------------------------------------------------------ 3, 4, 11c. receiving

    public function testReceiveRefusesAnExpiryDateBeforeTodayButAcceptsTodayInManila(): void
    {
        $procurement = $this->shop->services->procurement();
        $order = $this->sentPurchaseOrder();
        $line = $this->lineOf($order, $this->tracked);

        $error = $this->assertHttpStatus(422, fn () => $procurement->receive($order['id'], [
            ['line_id' => $line['id'], 'qty_received' => '5', 'batch_code' => 'LOT-OLD', 'expires_on' => $this->today(-1)],
        ], null));
        $this->assertSame('The expiry date cannot be earlier than today.', $error->fields()['lines.0.expires_on']);
        $this->assertSame('0.000', $this->shop->services->inventory()->onHand($this->pickup, $this->tracked), 'a refused receipt changes nothing');

        $procurement->receive($order['id'], [
            ['line_id' => $line['id'], 'qty_received' => '5', 'batch_code' => 'LOT-TODAY', 'expires_on' => $this->today(), 'bin' => 'A-1'],
        ], null);
        $this->assertSame('5.000', $this->shop->services->inventory()->onHand($this->pickup, $this->tracked), 'today is accepted');
        $this->assertSame($this->today(), (string) $this->shop->scalar("SELECT expires_on FROM stock_batches WHERE batch_code = 'LOT-TODAY'"));

        $procurement->receive($order['id'], [
            ['line_id' => $line['id'], 'qty_received' => '5', 'batch_code' => 'LOT-LATER', 'expires_on' => $this->today(30)],
        ], null);
        $this->assertSame('10.000', $this->shop->services->inventory()->onHand($this->pickup, $this->tracked));
    }

    public function testReceiveStillRefusesAMalformedExpiryDateWithAFieldError(): void
    {
        $order = $this->sentPurchaseOrder();
        $line = $this->lineOf($order, $this->tracked);

        $error = $this->assertHttpStatus(422, fn () => $this->shop->services->procurement()->receive($order['id'], [
            ['line_id' => $line['id'], 'qty_received' => '5', 'batch_code' => 'LOT-X', 'expires_on' => '31/12/2030'],
        ], null));
        $this->assertSame('Enter the expiry date as YYYY-MM-DD.', $error->fields()['lines.0.expires_on']);
    }

    public function testReceivingMoreThanOrderedIsRefusedBecauseOfTheQuantitiesNotTheEmptyOptionalFields(): void
    {
        $procurement = $this->shop->services->procurement();
        $order = $this->sentPurchaseOrder();
        $plainLine = $this->lineOf($order, $this->plain);
        $trackedLine = $this->lineOf($order, $this->tracked);

        // 15 accepted + 10 rejected = 25 against 20 ordered, with batch code, expiry and bin all empty.
        $error = $this->assertHttpStatus(422, fn () => $procurement->receive($order['id'], [
            ['line_id' => $plainLine['id'], 'qty_received' => '15', 'qty_rejected' => '10', 'batch_code' => '', 'expires_on' => null, 'bin' => null],
            ['line_id' => $trackedLine['id'], 'qty_received' => '15', 'qty_rejected' => '10', 'batch_code' => '', 'expires_on' => '', 'bin' => ''],
        ], null));

        $fields = $error->fields();
        foreach (['lines.0.qty_received', 'lines.1.qty_received'] as $key) {
            $this->assertArrayHasKey($key, $fields);
            $this->assertStringContainsString('Only 20 is still to be received on this line.', $fields[$key]);
        }
        foreach (['batch_code', 'expires_on', 'bin'] as $optional) {
            $this->assertArrayNotHasKey('lines.0.' . $optional, $fields);
            $this->assertArrayNotHasKey('lines.1.' . $optional, $fields);
        }
        $this->assertSame('10.000', $this->shop->services->inventory()->onHand($this->pickup, $this->plain), 'nothing was received');
    }

    public function testAPlainVariantIsReceivedWithoutAnyBatchFields(): void
    {
        $order = $this->sentPurchaseOrder();
        $plainLine = $this->lineOf($order, $this->plain);

        $received = $this->shop->services->procurement()->receive($order['id'], [
            ['line_id' => $plainLine['id'], 'qty_received' => '5', 'qty_rejected' => '0'],
        ], null);

        $this->assertSame('partially_received', $received['status']);
        $this->assertSame('15.000', $this->shop->services->inventory()->onHand($this->pickup, $this->plain));
        $this->assertSame(0, (int) $this->shop->scalar('SELECT COUNT(*) FROM stock_batches WHERE variant_id = ?', [$this->plain]), 'a variant that does not track batches stores none');
    }

    // ------------------------------------------------------------------ 5, 6. payments

    public function testPartialPaymentKeepsTheStoredStatusAndReadsPartlyPaid(): void
    {
        $orders = $this->shop->services->orders();
        $customer = $this->shop->customer();
        $placed = $orders->createOrder($customer, [['variant_id' => $this->plain, 'quantity' => '1']], ['method' => 'pickup', 'branch_id' => $this->pickup], []);
        $this->assertSame('112.00', $placed['total']);

        $order = $orders->recordPayment($placed['id'], ['method' => 'cash', 'amount' => '50.00'], null);

        $this->assertSame('unpaid', $order['payment_status'], 'the stored state and the core rule are unchanged');
        $this->assertSame('Partly paid (₱50.00 of ₱112.00)', Format::paymentStatus((string) $order['payment_status'], $order['payment_summary']['paid'], (string) $order['total']));

        $list = $orders->listOrders(ListQuery::fromRequest(new Request('GET', '/'), ['placed' => 'o.placed_at'], '-placed', ['payment_status']));
        $this->assertSame('50.00', (string) $list['items'][0]['paid_total'], 'the list carries the amount paid so it can show the same label');

        $order = $orders->recordPayment($placed['id'], ['method' => 'cash', 'amount' => '62.00'], null);
        $this->assertSame('paid', $order['payment_status']);
        $this->assertSame('Paid', Format::paymentStatus('paid', $order['payment_summary']['paid'], (string) $order['total']));
    }

    public function testTheOverpaymentErrorUsesThePesoSign(): void
    {
        $orders = $this->shop->services->orders();
        $placed = $orders->createOrder($this->shop->customer(), [['variant_id' => $this->plain, 'quantity' => '1']], ['method' => 'pickup', 'branch_id' => $this->pickup], []);
        $orders->recordPayment($placed['id'], ['method' => 'cash', 'amount' => '50.00'], null);

        $error = $this->assertHttpStatus(422, fn () => $orders->recordPayment($placed['id'], ['method' => 'cash', 'amount' => '100.00'], null));

        $this->assertSame('This is more than the balance of ₱62.00.', $error->fields()['amount']);
        $this->assertStringNotContainsString('PHP', $error->fields()['amount']);
    }

    // ------------------------------------------------------------------ 7. order history

    public function testMovingStraightToCompletedLogsEveryStepInOrderAndSellsStockOnce(): void
    {
        $orders = $this->shop->services->orders();
        $placed = $orders->createOrder($this->shop->customer(), [['variant_id' => $this->plain, 'quantity' => '1']], ['method' => 'pickup', 'branch_id' => $this->pickup], []);

        $orders->advance($placed['id'], 'confirmed', null);
        $done = $orders->advance($placed['id'], 'completed', null);   // Confirmed straight to Completed

        $this->assertSame(['placed', 'confirmed', 'ready', 'completed'], array_map(static fn (array $h): string => (string) $h['progress_key'], $done['history']));
        $this->assertSame('completed', $done['progress_key']);
        $this->assertSame(1, (int) $this->shop->scalar("SELECT COUNT(*) FROM stock_movements WHERE reference_type = 'order' AND reference_id = ? AND movement_type = 'sale_online'", [$placed['id']]), 'stock is sold once');
        $this->assertSame('9.000', $this->shop->services->inventory()->onHand($this->pickup, $this->plain));

        $this->assertHttpStatus(422, fn () => $orders->advance($placed['id'], 'completed', null));
        $this->assertSame(4, (int) $this->shop->scalar('SELECT COUNT(*) FROM order_status_history WHERE order_id = ?', [$placed['id']]), 'a refused move logs nothing');
    }

    public function testASingleStepMoveLogsOneEntryAndAJumpAheadLogsEachStepPassed(): void
    {
        $orders = $this->shop->services->orders();
        $placed = $orders->createOrder($this->shop->customer(), [['variant_id' => $this->plain, 'quantity' => '1']], ['method' => 'pickup', 'branch_id' => $this->pickup], []);

        $step = $orders->advance($placed['id'], 'confirmed', null);
        $this->assertSame(['placed', 'confirmed'], array_map(static fn (array $h): string => (string) $h['progress_key'], $step['history']));

        $jump = $orders->advance($placed['id'], 'ready', null);
        $this->assertSame(['placed', 'confirmed', 'ready'], array_map(static fn (array $h): string => (string) $h['progress_key'], $jump['history']));
        $this->assertSame(0, (int) $this->shop->scalar("SELECT COUNT(*) FROM stock_movements WHERE reference_type = 'order' AND reference_id = ? AND movement_type = 'sale_online'", [$placed['id']]), 'nothing is sold before completion');
    }

    // ------------------------------------------------------------------ 11a, 11b. POS review

    /** @return array{sale_id: string, status: string} a sale kept as needs_review because it oversold */
    private function flaggedSale(): array
    {
        $terminal = $this->shop->terminal($this->pickup, 'T9');
        $shift = $this->shop->shift($terminal, '1000.00');

        $result = $this->shop->services->pos()->syncSale([
            'id' => \Gfh\Core\Support\Uuid::v4(),
            'receipt_number' => 'T9-000001',
            'terminal_id' => $terminal,
            'shift_id' => $shift,
            'sold_at' => (new \DateTimeImmutable())->format('c'),
            'lines' => [['variant_id' => $this->plain, 'quantity' => '12', 'unit_price' => '112.00']],
            'payments' => [['method' => 'cash', 'amount' => '1344.00']],
        ]);
        $this->assertSame('needs_review', $result['status']);

        return $result;
    }

    public function testResolvingAReviewWithAnEmptyNoteIsRefusedAndTheSaleStaysInTheQueue(): void
    {
        $pos = $this->shop->services->pos();
        $flagged = $this->flaggedSale();

        foreach (['', '   ', 'ok'] as $note) {
            $error = $this->assertHttpStatus(422, fn () => $pos->resolveReview($flagged['sale_id'], $note, null));
            $this->assertArrayHasKey('note', $error->fields());
        }
        $this->assertSame('needs_review', $pos->getSale($flagged['sale_id'])['status']);
    }

    public function testResolvingAReviewDoesNotChangeStock(): void
    {
        $pos = $this->shop->services->pos();
        $flagged = $this->flaggedSale();
        $before = $this->shop->services->inventory()->onHand($this->pickup, $this->plain);
        $movementsBefore = (int) $this->shop->scalar('SELECT COUNT(*) FROM stock_movements');

        $resolved = $pos->resolveReview($flagged['sale_id'], 'Counted the shelf; the record was wrong', null);

        $this->assertSame('completed', $resolved['status']);
        $this->assertSame($before, $this->shop->services->inventory()->onHand($this->pickup, $this->plain));
        $this->assertSame($movementsBefore, (int) $this->shop->scalar('SELECT COUNT(*) FROM stock_movements'), 'resolving writes no stock movement');
    }
}
