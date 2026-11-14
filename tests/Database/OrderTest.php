<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\OrderService;
use Gfh\Tests\Support\DatabaseTestCase;

final class OrderTest extends DatabaseTestCase
{
    private string $pickup = '';
    private string $origin = '';
    private string $plain = '';
    private string $madeToOrder = '';
    /** @var array<string, mixed> */
    private array $customer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop->steps();
        $this->pickup = $this->shop->branch('P1', true, false);
        $this->origin = $this->shop->branch('D1', false, true);
        $this->plain = $this->shop->variant(['sku' => 'PLAIN-1', 'price' => '112.00']);
        $this->madeToOrder = $this->shop->variant(['sku' => 'MTO-1', 'price' => '112.00']);
        foreach ([$this->pickup, $this->origin] as $branch) {
            $this->shop->stock($branch, $this->plain, '10', '50.00');
            $this->shop->stock($branch, $this->madeToOrder, '10', '50.00');
        }
        $this->customer = $this->shop->customer();
    }

    /** @return array<string, mixed> */
    private function placePickup(string $variant, string $quantity = '1'): array
    {
        return $this->shop->services->orders()->createOrder(
            $this->customer,
            [['variant_id' => $variant, 'quantity' => $quantity]],
            ['method' => 'pickup', 'branch_id' => $this->pickup],
            [],
        );
    }

    /** @return array<string, mixed> */
    private function placeDelivery(string $variant): array
    {
        return $this->shop->services->orders()->createOrder(
            $this->customer,
            [['variant_id' => $variant, 'quantity' => '1']],
            ['method' => 'delivery', 'line1' => '12 Lacson Street', 'city' => 'Bacolod City', 'province' => 'Negros Occidental'],
            [],
        );
    }

    private function markMadeToOrder(): void
    {
        $variant = $this->madeToOrder;
        $this->shop->hooks->add(Hooks::CHECKOUT_ADJUSTMENTS, static function (array $adjustments) use ($variant): array {
            $adjustments['made_to_order_variants'] = [$variant];

            return $adjustments;
        });
    }

    /**
     * @param array<string, mixed> $order
     * @return list<string>
     */
    private function pathKeys(array $order): array
    {
        return array_map(static fn (array $step): string => (string) $step['key'], $order['path']);
    }

    public function testTheProgressPathIncludesOnlyTheStepsThatFitTheOrder(): void
    {
        $this->markMadeToOrder();

        $this->assertSame(['placed', 'confirmed', 'ready', 'completed'], $this->pathKeys($this->placePickup($this->plain)));
        $this->assertSame(['placed', 'confirmed', 'ready', 'out_for_delivery', 'completed'], $this->pathKeys($this->placeDelivery($this->plain)));
        $this->assertSame(['placed', 'confirmed', 'in_production', 'ready', 'completed'], $this->pathKeys($this->placePickup($this->madeToOrder)));
    }

    public function testAnUnrecognisedConditionNeverMatches(): void
    {
        $this->org->exec("UPDATE order_progress_steps SET applies_when = 'some_extension_condition' WHERE key = 'ready'");

        $this->assertSame(['placed', 'confirmed', 'completed'], $this->pathKeys($this->placePickup($this->plain)));
    }

    public function testAStepCanOnlyBeChosenFromTheOrdersOwnPath(): void
    {
        $order = $this->placePickup($this->plain);

        $error = $this->assertHttpStatus(422, fn () => $this->shop->services->orders()->advance($order['id'], 'in_production', null));
        $this->assertArrayHasKey('step', $error->fields());
    }

    public function testPaymentStatusGoesUnpaidDepositPaidPaidAndOverpayingIsRefused(): void
    {
        $this->shop->services->settings()->set('deposit_percent', 30, null);
        $this->markMadeToOrder();
        $orders = $this->shop->services->orders();

        $order = $this->placePickup($this->madeToOrder);
        $this->assertSame('112.00', $order['total']);
        $this->assertSame('33.60', $order['deposit_due'], '30% of 112.00');
        $this->assertSame('unpaid', $order['payment_status']);

        $order = $orders->recordPayment($order['id'], ['method' => 'cash', 'amount' => '20.00'], null);
        $this->assertSame('unpaid', $order['payment_status'], 'the deposit is not covered yet');

        $order = $orders->recordPayment($order['id'], ['method' => 'cash', 'amount' => '13.60'], null);
        $this->assertSame('deposit_paid', $order['payment_status']);
        $this->assertSame('78.40', $order['payment_summary']['balance']);

        $error = $this->assertHttpStatus(422, fn () => $orders->recordPayment($order['id'], ['method' => 'cash', 'amount' => '78.41'], null));
        $this->assertArrayHasKey('amount', $error->fields());

        $order = $orders->recordPayment($order['id'], ['method' => 'gcash', 'amount' => '78.40', 'reference' => 'GC-123456'], null);
        $this->assertSame('paid', $order['payment_status']);
        $this->assertSame('0.00', $order['payment_summary']['balance']);

        $this->assertHttpStatus(422, fn () => $orders->recordPayment($order['id'], ['method' => 'cash', 'amount' => '0.01'], null));
    }

    public function testAnOrderWithoutADepositGoesStraightFromUnpaidToPaid(): void
    {
        $orders = $this->shop->services->orders();
        $order = $this->placePickup($this->plain);

        $this->assertSame('0.00', $order['deposit_due']);
        $order = $orders->recordPayment($order['id'], ['method' => 'cash', 'amount' => '50.00'], null);
        $this->assertSame('unpaid', $order['payment_status']);
        $order = $orders->recordPayment($order['id'], ['method' => 'cash', 'amount' => '62.00'], null);
        $this->assertSame('paid', $order['payment_status']);
    }

    public function testElectronicPaymentsNeedAReferenceAndOnlyKnownMethodsAreAccepted(): void
    {
        $order = $this->placePickup($this->plain);
        $orders = $this->shop->services->orders();

        $this->assertArrayHasKey('reference', $this->assertHttpStatus(422, fn () => $orders->recordPayment($order['id'], ['method' => 'maya', 'amount' => '10.00'], null))->fields());
        $this->assertArrayHasKey('method', $this->assertHttpStatus(422, fn () => $orders->recordPayment($order['id'], ['method' => 'bitcoin', 'amount' => '10.00'], null))->fields());
        $this->assertArrayHasKey('amount', $this->assertHttpStatus(422, fn () => $orders->recordPayment($order['id'], ['method' => 'cash', 'amount' => '-5'], null))->fields());
    }

    public function testProgressAndPaymentStatusChangeIndependently(): void
    {
        $orders = $this->shop->services->orders();
        $order = $this->placePickup($this->plain);

        $order = $orders->recordPayment($order['id'], ['method' => 'cash', 'amount' => '112.00'], null);
        $this->assertSame('paid', $order['payment_status']);
        $this->assertSame('placed', $order['progress_key'], 'paying does not move the order along');

        $order = $orders->advance($order['id'], 'confirmed', null);
        $this->assertSame('confirmed', $order['progress_key']);
        $this->assertSame('paid', $order['payment_status'], 'moving the order along does not touch payment');
        $this->assertSame(['placed', 'confirmed'], array_column($order['history'], 'progress_key'));
    }

    public function testCreatingAnOrderReservesStockAndFulfilmentSellsItAndSnapshotsTheCost(): void
    {
        $inventory = $this->shop->services->inventory();
        $order = $this->placePickup($this->plain, '2');

        $this->assertSame('8.000', $inventory->available($this->pickup, $this->plain));
        $this->assertSame('10.000', $inventory->onHand($this->pickup, $this->plain), 'reserved, not yet sold');
        $this->assertNull($order['lines'][0]['unit_cost']);

        $order = $this->shop->services->orders()->advance($order['id'], 'completed', null);

        $this->assertSame('8.000', $inventory->onHand($this->pickup, $this->plain));
        $this->assertSame('50.0000', $order['lines'][0]['unit_cost'], 'weighted-average cost at the time of sale');
        $this->assertSame('consumed', $this->shop->scalar('SELECT status FROM stock_reservations WHERE order_id = ?', [$order['id']]));
        $this->assertSame('sale_online', $this->shop->scalar("SELECT movement_type FROM stock_movements WHERE reference_id = ?", [$order['id']]));
        $this->assertHttpStatus(422, fn () => $this->shop->services->orders()->advance($order['id'], 'confirmed', null));
    }

    public function testDeliveryShipsFromTheDeliveryBranchAndPickupFromTheChosenOne(): void
    {
        $delivery = $this->placeDelivery($this->plain);
        $pickup = $this->placePickup($this->plain);

        $this->assertSame($this->origin, $delivery['ship_from_branch_id']);
        $this->assertNull($delivery['pickup_branch_id']);
        $this->assertSame('Bacolod City', $delivery['ship_to']['city']);
        $this->assertSame($this->pickup, $pickup['pickup_branch_id']);
        $this->assertSame('9.000', $this->shop->services->inventory()->available($this->origin, $this->plain));
        $this->assertSame('9.000', $this->shop->services->inventory()->available($this->pickup, $this->plain));
    }

    public function testBadFulfilmentChoicesAreRefusedWithFieldErrors(): void
    {
        $orders = $this->shop->services->orders();
        $items = [['variant_id' => $this->plain, 'quantity' => '1']];

        $notAPickupPoint = $this->assertHttpStatus(422, fn () => $orders->createOrder($this->customer, $items, ['method' => 'pickup', 'branch_id' => $this->origin], []));
        $this->assertArrayHasKey('fulfillment.branch_id', $notAPickupPoint->fields());

        $noAddress = $this->assertHttpStatus(422, fn () => $orders->createOrder($this->customer, $items, ['method' => 'delivery', 'city' => 'B'], []));
        $this->assertArrayHasKey('fulfillment.line1', $noAddress->fields());
        $this->assertArrayHasKey('fulfillment.province', $noAddress->fields());

        $this->assertArrayHasKey('fulfillment.method', $this->assertHttpStatus(422, fn () => $orders->createOrder($this->customer, $items, ['method' => 'drone'], []))->fields());

        $this->org->exec('UPDATE branches SET is_delivery_origin = false');
        $this->assertArrayHasKey('fulfillment.method', $this->assertHttpStatus(422, fn () => $orders->createOrder($this->customer, $items, ['method' => 'delivery', 'line1' => '12 Lacson Street', 'city' => 'Bacolod City', 'province' => 'Negros Occidental'], []))->fields());
    }

    public function testAnOrderForMoreThanIsInStockIsRefusedAndCreatesNothing(): void
    {
        $error = $this->assertHttpStatus(422, fn () => $this->placePickup($this->plain, '11'));

        $this->assertArrayHasKey('items', $error->fields());
        $this->assertSame(0, (int) $this->shop->scalar('SELECT COUNT(*) FROM orders'));
        $this->assertSame(0, (int) $this->shop->scalar('SELECT COUNT(*) FROM stock_reservations'));
    }

    public function testCancellingReleasesStockKeepsPaymentsAndFlagsAnyMoneyTakenForRefund(): void
    {
        $orders = $this->shop->services->orders();
        $inventory = $this->shop->services->inventory();

        $unpaid = $this->placePickup($this->plain, '2');
        $cancelledUnpaid = $orders->cancel($unpaid['id'], 'Customer changed their mind', null);
        $this->assertNotNull($cancelledUnpaid['cancelled_at']);
        $this->assertFalse((bool) $cancelledUnpaid['refund_review']);
        $this->assertSame('10.000', $inventory->available($this->pickup, $this->plain));

        $paid = $this->placePickup($this->plain, '1');
        $orders->recordPayment($paid['id'], ['method' => 'cash', 'amount' => '50.00'], null);
        $cancelledPaid = $orders->cancel($paid['id'], 'Out of delivery range', null);

        $this->assertTrue((bool) $cancelledPaid['refund_review'], 'money was taken, so a person must check the refund');
        $this->assertCount(1, $cancelledPaid['payments'], 'the payment is kept');
        $this->assertSame('unpaid', $cancelledPaid['payment_status']);
        $this->assertHttpStatus(422, fn () => $orders->recordPayment($paid['id'], ['method' => 'cash', 'amount' => '10.00'], null));
        $this->assertHttpStatus(422, fn () => $orders->advance($paid['id'], 'confirmed', null));
        $this->assertHttpStatus(422, fn () => $orders->cancel($paid['id'], 'Again', null));
    }

    public function testAFulfilledOrderCannotBeCancelled(): void
    {
        $order = $this->placePickup($this->plain);
        $this->shop->services->orders()->advance($order['id'], 'completed', null);

        $this->assertHttpStatus(422, fn () => $this->shop->services->orders()->cancel($order['id'], 'Too late', null));
    }

    public function testPricesAndTotalsAreRecomputedOnTheServer(): void
    {
        $this->org->prepare("INSERT INTO price_tiers (variant_id, basis, label, min_qty, price) VALUES (?, 'quantity', '2 or more', 2, 100.00)")->execute([$this->plain]);

        // A browser-supplied price is ignored: only the variant and quantity count.
        $order = $this->shop->services->orders()->createOrder(
            $this->customer,
            [['variant_id' => $this->plain, 'quantity' => '2', 'unit_price' => '1.00']],
            ['method' => 'pickup', 'branch_id' => $this->pickup],
            [],
        );

        $this->assertSame('200.00', $order['subtotal']);
        $this->assertSame('200.00', $order['total']);
        $this->assertSame('21.43', $order['vat_amount'], '200.00 less 178.57 VAT-free');
        $this->assertSame('100.00', $order['lines'][0]['unit_price']);
    }

    public function testCheckoutAdjustmentsAddFeesAndAPromisedDate(): void
    {
        $this->shop->hooks->add(Hooks::CHECKOUT_ADJUSTMENTS, static function (array $adjustments, array $context): array {
            if (($context['fulfillment']['method'] ?? '') === 'delivery') {
                $adjustments['delivery_fee'] = '150.00';
                $adjustments['promised_date'] = '2026-12-24';
            }

            return $adjustments;
        });

        $delivery = $this->placeDelivery($this->plain);
        $pickup = $this->placePickup($this->plain);

        $this->assertSame('150.00', $delivery['delivery_fee']);
        $this->assertSame('262.00', $delivery['total'], '112.00 + 150.00');
        $this->assertSame('28.07', $delivery['vat_amount']);
        $this->assertSame('2026-12-24', $delivery['promised_date']);
        $this->assertSame('0.00', $pickup['delivery_fee']);
        $this->assertNull($pickup['promised_date']);
    }

    public function testThePaymentStatusRuleItself(): void
    {
        $this->assertSame('unpaid', OrderService::derivePaymentStatus(11200, 3360, 0));
        $this->assertSame('unpaid', OrderService::derivePaymentStatus(11200, 3360, 3359));
        $this->assertSame('deposit_paid', OrderService::derivePaymentStatus(11200, 3360, 3360));
        $this->assertSame('paid', OrderService::derivePaymentStatus(11200, 3360, 11200));
        $this->assertSame('unpaid', OrderService::derivePaymentStatus(11200, 0, 5000));
        $this->assertSame('paid', OrderService::derivePaymentStatus(11200, 0, 11200));
    }

    public function testOrderNumbersAreSequentialPerDay(): void
    {
        $first = $this->placePickup($this->plain);
        $second = $this->placePickup($this->plain);

        $this->assertMatchesRegularExpression('/^SO-\d{8}-0001$/', $first['order_number']);
        $this->assertMatchesRegularExpression('/^SO-\d{8}-0002$/', $second['order_number']);
    }

    public function testOrderListsFilterAndPaginate(): void
    {
        $this->placePickup($this->plain);
        $second = $this->placePickup($this->plain);
        $this->shop->services->orders()->recordPayment($second['id'], ['method' => 'cash', 'amount' => '112.00'], null);

        $request = new \Gfh\Core\Http\Request('GET', '/x', ['payment_status' => 'paid', 'per_page' => '1', 'sort' => '-placed']);
        $query = \Gfh\Core\Support\ListQuery::fromRequest($request, ['placed' => 'o.placed_at', 'total' => 'o.total'], 'placed', ['payment_status']);
        $result = $this->shop->services->orders()->listOrders($query);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame($second['id'], $result['items'][0]['id']);

        $all = $this->shop->services->orders()->listOrders(\Gfh\Core\Support\ListQuery::fromRequest(new \Gfh\Core\Http\Request('GET', '/x', ['per_page' => '1']), ['placed' => 'o.placed_at'], 'placed'));
        $this->assertSame(2, $all['pagination']['total']);
        $this->assertSame(2, $all['pagination']['pages']);
        $this->assertCount(1, $all['items']);
    }
}
