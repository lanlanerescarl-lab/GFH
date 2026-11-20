<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Database\Database;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Payments\PaymentProvider;
use Gfh\Core\Repository\OrderRepository;
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\Format;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\Uuid;

/**
 * Orders. Two things are tracked separately and change independently:
 *  - progress: where the order is in its path of steps (steps are rows in order_progress_steps, each with an
 *    applies_when condition, so an order's path holds only the steps that fit its contents);
 *  - payment status: derived from the payments recorded (unpaid, deposit_paid, paid). Overpaying is refused.
 *
 * Creating an order prices it on the server, reserves the stock at the branch that will fulfil it, and picks the
 * first step of its path. Reaching a step that maps to "complete" sells the reserved stock and snapshots costs.
 * Cancelling releases the stock, keeps every payment, and flags any money taken for a manual refund check.
 */
final class OrderService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly OrderRepository $repository,
        private readonly PricingService $pricing,
        private readonly InventoryService $inventory,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly Hooks $hooks,
        private readonly PaymentProvider $payments,
    ) {
    }

    /** Pure rule: what the payment status is for these amounts (all centavos). */
    public static function derivePaymentStatus(int $total, int $depositDue, int $paid): string
    {
        if ($paid >= $total) {
            return 'paid';
        }

        return ($depositDue > 0 && $paid >= $depositDue) ? 'deposit_paid' : 'unpaid';
    }

    /** @return array<string, mixed> the customer's organization record (tier and type feed price tiers), or null */
    public function customerForAccount(string $coreCustomerId): ?array
    {
        return $this->repository->customerByCoreId($coreCustomerId);
    }

    /**
     * What the checkout page needs: pickup branches, whether delivery is available, payment methods and the
     * extension checkout fields (hook checkout.fields).
     *
     * @return array<string, mixed>
     */
    public function checkoutOptions(): array
    {
        return [
            'pickup_branches' => $this->repository->pickupBranches(),
            'delivery_available' => $this->repository->deliveryOriginId() !== null,
            'payment_methods' => $this->payments->methods(),
            'fields' => $this->hooks->filter(Hooks::CHECKOUT_FIELDS, [], []),
        ];
    }

    /**
     * Places an order.
     *
     * @param array<string, mixed>       $customer    the organization customer row (customers table)
     * @param list<array<string, mixed>> $items       each {variant_id, quantity}
     * @param array<string, mixed>       $fulfillment method pickup|delivery, branch_id (pickup), address fields (delivery)
     * @param array<string, mixed>       $checkout    values of the extension checkout fields
     * @return array<string, mixed> the order as returned by getOrder()
     * @throws HttpException 422 with field errors, including when stock is short
     */
    public function createOrder(array $customer, array $items, array $fulfillment, array $checkout, ?string $createdBy = null, string $channel = 'online'): array
    {
        return Database::transaction($this->pdo, function () use ($customer, $items, $fulfillment, $checkout, $createdBy, $channel): array {
            $where = $this->resolveFulfillment($fulfillment);

            $cart = $this->pricing->priceCart($items, [
                'customer' => ['tier' => $customer['tier'] ?? null, 'type' => $customer['customer_type'] ?? null],
                'channel' => $channel,
                'fulfillment' => $where['public'],
                'checkout' => $checkout,
            ]);

            $context = [
                'fulfillment_method' => $where['method'],
                'has_made_to_order' => $cart->madeToOrderVariantIds !== [],
                'assembly_fee' => $cart->assemblyFee,
                'installation_fee' => $cart->installationFee,
            ];
            $path = $this->progressPath($context);
            if ($path === []) {
                throw HttpException::unprocessable(['order' => 'This shop has no order steps set up yet. Please contact the shop.']);
            }

            $this->pdo->exec("SELECT pg_advisory_xact_lock(hashtext('gfh_order_number'))");
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Ymd');

            $orderId = $this->repository->insertOrder([
                'order_number' => $this->repository->nextOrderNumber($today),
                'customer_id' => $customer['id'],
                'channel' => $channel,
                'progress_key' => $path[0]['key'],
                'fulfillment_method' => $where['method'],
                'pickup_branch_id' => $where['pickup_branch_id'],
                'ship_from_branch_id' => $where['ship_from_branch_id'],
                'ship_to' => $where['ship_to'] === null ? null : json_encode($where['ship_to'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'subtotal' => Decimal::format($cart->subtotal, 2),
                'discount_total' => Decimal::format($cart->discountTotal, 2),
                'delivery_fee' => Decimal::format($cart->deliveryFee, 2),
                'assembly_fee' => Decimal::format($cart->assemblyFee, 2),
                'installation_fee' => Decimal::format($cart->installationFee, 2),
                'vat_amount' => Decimal::format($cart->vatAmount, 2),
                'total' => Decimal::format($cart->total, 2),
                'deposit_due' => Decimal::format($cart->depositDue, 2),
                'promised_date' => $cart->promisedDate,
                'created_by' => $createdBy,
            ]);

            $reserve = [];
            foreach ($cart->lines as $line) {
                $this->repository->insertLine($orderId, [
                    'variant_id' => $line['variant_id'],
                    'sku' => $line['sku'],
                    'name' => $line['product_name'] . ($line['variant_name'] !== '' ? ' - ' . $line['variant_name'] : ''),
                    'qty' => Decimal::format($line['quantity_milli'], 3),
                    'unit_price' => Decimal::format($line['unit_price'], 2),
                    'line_total' => Decimal::format($line['line_total'], 2),
                    'is_made_to_order' => $line['is_made_to_order'],
                ]);
                $reserve[] = ['variant_id' => $line['variant_id'], 'branch_id' => $where['stock_branch_id'], 'quantity' => Decimal::format($line['quantity_milli'], 3)];
            }

            $hours = $this->settings->int('reservation_hours');
            $this->inventory->reserve($orderId, $reserve, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . $hours . ' hours'));

            $this->repository->addHistory($orderId, (string) $path[0]['key'], $createdBy);
            $this->audit->organization($this->pdo, 'order.created', $createdBy, 'order', $orderId, ['total' => Decimal::format($cart->total, 2), 'channel' => $channel]);

            return $this->getOrder($orderId);
        });
    }

    /**
     * The steps this order goes through, in order: every step whose applies_when condition matches the order,
     * then the order.progress.steps hook (which owns the conditions it adds).
     *
     * Built-in conditions: always, has_made_to_order, is_delivery, is_pickup, has_assembly, has_installation.
     * A condition nobody recognises does not match.
     *
     * @param array{fulfillment_method: string, has_made_to_order: bool, assembly_fee: int, installation_fee: int} $context
     * @return list<array<string, mixed>>
     */
    public function progressPath(array $context): array
    {
        $all = $this->repository->steps();
        $path = [];
        foreach ($all as $step) {
            if (self::stepApplies((string) $step['applies_when'], $context)) {
                $path[] = $step;
            }
        }

        $filtered = $this->hooks->filter(Hooks::ORDER_PROGRESS_STEPS, $path, ['order' => $context, 'all_steps' => $all]);

        return array_values(is_array($filtered) ? $filtered : $path);
    }

    /**
     * All active progress steps defined for the organization (for Admin filter dropdowns).
     *
     * @return list<array<string, mixed>>
     */
    public function allSteps(): array
    {
        return $this->repository->steps();
    }

    /**
     * Every condition stepApplies() recognises. The Settings page for order steps offers exactly these and refuses
     * any other value, because an unrecognised one never matches and its step would never be reached.
     */
    public const APPLIES_WHEN = ['always', 'has_made_to_order', 'is_delivery', 'is_pickup', 'has_assembly', 'has_installation'];

    /**
     * Whether a step with this applies_when condition belongs to the path of an order with this context. Static and
     * public so the step rules (OrderStepService) ask the same question the order path asks, instead of copying it.
     *
     * @param array<string, mixed> $context
     */
    public static function stepApplies(string $condition, array $context): bool
    {
        return match ($condition) {
            'always' => true,
            'has_made_to_order' => (bool) $context['has_made_to_order'],
            'is_delivery' => $context['fulfillment_method'] === 'delivery',
            'is_pickup' => $context['fulfillment_method'] === 'pickup',
            'has_assembly' => $context['assembly_fee'] > 0,
            'has_installation' => $context['installation_fee'] > 0,
            default => false,
        };
    }

    /**
     * Moves an order to another step of its own path. Reaching a "complete" step sells the stock.
     *
     * @return array<string, mixed> the order
     */
    public function advance(string $orderId, string $stepKey, ?string $actorId): array
    {
        return Database::transaction($this->pdo, function () use ($orderId, $stepKey, $actorId): array {
            $order = $this->loadForUpdate($orderId);
            if ($order['cancelled_at'] !== null) {
                throw HttpException::unprocessable(['step' => 'This order is cancelled.']);
            }

            $path = $this->progressPath($this->contextOf($order));
            $byKey = [];
            foreach ($path as $step) {
                $byKey[(string) $step['key']] = $step;
            }

            $current = $byKey[(string) $order['progress_key']] ?? null;
            if ($current !== null && (bool) $current['is_terminal']) {
                throw HttpException::unprocessable(['step' => 'This order is already finished.']);
            }
            if (!isset($byKey[$stepKey])) {
                throw HttpException::unprocessable(['step' => 'That step is not part of this order\'s path.']);
            }
            if ($stepKey === (string) $order['progress_key']) {
                return $this->getOrder($orderId);
            }

            // Moving forward passes every step in between, so each one is logged in order. A move back, or from a
            // step that is no longer on the path, logs only the step it lands on.
            $keys = array_map(static fn (array $step): string => (string) $step['key'], array_values($path));
            $from = array_search((string) $order['progress_key'], $keys, true);
            $to = array_search($stepKey, $keys, true);
            $passed = ($from !== false && $to > $from) ? array_slice($keys, $from + 1, $to - $from) : [$stepKey];

            // Stock is sold once, the first time the order passes a step that means "complete".
            $reachesComplete = false;
            foreach ($passed as $passedKey) {
                $reachesComplete = $reachesComplete || $byKey[$passedKey]['maps_to'] === 'complete';
            }
            if ($reachesComplete && !$this->repository->hasSaleMovements($orderId)) {
                $this->fulfil($order, $actorId);
            }

            $this->repository->setProgress($orderId, $stepKey);
            foreach ($passed as $passedKey) {
                $this->repository->addHistory($orderId, $passedKey, $actorId);
            }
            $this->audit->organization($this->pdo, 'order.status_changed', $actorId, 'order', $orderId, ['from' => $order['progress_key'], 'to' => $stepKey, 'steps' => $passed]);

            return $this->getOrder($orderId);
        });
    }

    /**
     * Cancels an order that has not been fulfilled: its stock is released, its payments are kept, and when money
     * has been taken the order is flagged for a manual refund check.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $orderId, string $reason, ?string $actorId): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw HttpException::unprocessable(['reason' => 'Give a reason (3 to 255 characters).']);
        }

        return Database::transaction($this->pdo, function () use ($orderId, $reason, $actorId): array {
            $order = $this->loadForUpdate($orderId);
            if ($order['cancelled_at'] !== null) {
                throw HttpException::unprocessable(['order' => 'This order is already cancelled.']);
            }
            if ($this->repository->hasSaleMovements($orderId)) {
                throw HttpException::unprocessable(['order' => 'This order has already been fulfilled and cannot be cancelled.']);
            }

            $paid = Decimal::parse($this->repository->paidTotal($orderId), 2) ?? 0;
            $this->repository->cancel($orderId, $reason, $paid > 0);
            $this->inventory->releaseOrder($orderId);
            $this->audit->organization($this->pdo, 'order.cancelled', $actorId, 'order', $orderId, ['reason' => $reason, 'paid' => Decimal::format($paid, 2)]);

            return $this->getOrder($orderId);
        });
    }

    /**
     * Records a payment. The order's payment status is recalculated from all recorded payments.
     *
     * @param array<string, mixed> $details method, amount, reference
     * @return array<string, mixed> the order
     * @throws HttpException 422 for a bad amount, or one that would pay more than the order total
     */
    public function recordPayment(string $orderId, array $details, ?string $actorId): array
    {
        $clean = $this->payments->validate($details);

        $amount = Decimal::parse($details['amount'] ?? null, 2);
        if ($amount === null || $amount <= 0) {
            throw HttpException::unprocessable(['amount' => 'Enter an amount greater than zero, like 500.00.']);
        }

        return Database::transaction($this->pdo, function () use ($orderId, $clean, $amount, $actorId): array {
            $order = $this->loadForUpdate($orderId);
            if ($order['cancelled_at'] !== null) {
                throw HttpException::unprocessable(['order' => 'This order is cancelled, so no new payments can be recorded.']);
            }

            $total = Decimal::parse((string) $order['total'], 2) ?? 0;
            $paid = Decimal::parse($this->repository->paidTotal($orderId), 2) ?? 0;
            if ($paid + $amount > $total) {
                throw HttpException::unprocessable(['amount' => sprintf('This is more than the balance of %s.', Format::peso(Decimal::format($total - $paid, 2)))]);
            }

            $paymentId = $this->repository->addPayment($orderId, $clean['method'], Decimal::format($amount, 2), $clean['reference'], $actorId);

            $deposit = Decimal::parse((string) $order['deposit_due'], 2) ?? 0;
            $status = self::derivePaymentStatus($total, $deposit, $paid + $amount);
            $this->repository->setPaymentStatus($orderId, $status);

            $this->audit->organization($this->pdo, 'payment.recorded', $actorId, 'order', $orderId, [
                'payment_id' => $paymentId, 'method' => $clean['method'], 'amount' => Decimal::format($amount, 2), 'status' => $status,
            ]);

            return $this->getOrder($orderId);
        });
    }

    /**
     * An order with its lines, payments, history, path and payment summary.
     *
     * @return array<string, mixed>
     * @throws HttpException 404
     */
    public function getOrder(string $orderId): array
    {
        $order = Uuid::isValid($orderId) ? $this->repository->find($orderId) : null;
        if ($order === null) {
            throw HttpException::notFound('That order could not be found.');
        }

        $path = $this->progressPath($this->contextOf($order));
        $paid = Decimal::parse($this->repository->paidTotal($orderId), 2) ?? 0;
        $total = Decimal::parse((string) $order['total'], 2) ?? 0;

        $order['ship_to'] = $order['ship_to'] === null ? null : json_decode((string) $order['ship_to'], true);
        $order['lines'] = $this->repository->lines($orderId);
        $order['payments'] = $this->repository->payments($orderId);
        $order['history'] = $this->repository->history($orderId);
        $order['path'] = array_map(static fn (array $s): array => [
            'key' => $s['key'], 'label' => $s['label'], 'maps_to' => $s['maps_to'], 'is_terminal' => (bool) $s['is_terminal'],
            'current' => $s['key'] === $order['progress_key'],
        ], $path);
        $order['payment_summary'] = [
            'status' => $order['payment_status'],
            'paid' => Decimal::format($paid, 2),
            'balance' => Decimal::format($total - $paid, 2),
            'deposit_due' => (string) $order['deposit_due'],
        ];

        return $order;
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} */
    public function listOrders(ListQuery $query, ?string $onlyCustomerId = null): array
    {
        [$rows, $total] = $this->repository->list($query, $onlyCustomerId);

        return $query->result($rows, $total);
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} */
    public function listCustomers(ListQuery $query): array
    {
        [$rows, $total] = $this->repository->listCustomers($query);

        return $query->result($rows, $total);
    }

    /**
     * One organization customer record (the Admin Customers screen).
     *
     * @return array<string, mixed>
     * @throws HttpException 404
     */
    public function getCustomer(string $id): array
    {
        return (Uuid::isValid($id) ? $this->repository->customerById($id) : null)
            ?? throw HttpException::notFound('That customer could not be found.');
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string, mixed> */
    private function loadForUpdate(string $orderId): array
    {
        $order = Uuid::isValid($orderId) ? $this->repository->find($orderId, true) : null;

        return $order ?? throw HttpException::notFound('That order could not be found.');
    }

    /**
     * @param array<string, mixed> $order
     * @return array{fulfillment_method: string, has_made_to_order: bool, assembly_fee: int, installation_fee: int}
     */
    private function contextOf(array $order): array
    {
        $madeToOrder = false;
        foreach ($this->repository->lines((string) $order['id']) as $line) {
            $madeToOrder = $madeToOrder || (bool) $line['is_made_to_order'];
        }

        return [
            'fulfillment_method' => (string) $order['fulfillment_method'],
            'has_made_to_order' => $madeToOrder,
            'assembly_fee' => Decimal::parse((string) $order['assembly_fee'], 2) ?? 0,
            'installation_fee' => Decimal::parse((string) $order['installation_fee'], 2) ?? 0,
        ];
    }

    /**
     * Sells the order's reserved stock and snapshots each line's cost.
     *
     * @param array<string, mixed> $order
     */
    private function fulfil(array $order, ?string $actorId): void
    {
        $branchId = (string) ($order['pickup_branch_id'] ?? $order['ship_from_branch_id']);

        foreach ($this->repository->lines((string) $order['id']) as $line) {
            $sold = $this->inventory->sell(
                $branchId, (string) $line['variant_id'], $line['qty'], 'online', 'order', (string) $order['id'], $actorId,
                false, (string) $order['id'], $order['id'] . ':' . $line['id'],
            );
            $this->repository->setLineCost((string) $line['id'], $sold['unit_cost']);
        }

        $this->inventory->consumeOrder((string) $order['id']);
    }

    /**
     * Checks pickup or delivery details.
     *
     * @param array<string, mixed> $input
     * @return array{method: string, stock_branch_id: string, pickup_branch_id: ?string, ship_from_branch_id: ?string, ship_to: ?array<string, mixed>, public: array<string, mixed>}
     */
    private function resolveFulfillment(array $input): array
    {
        $method = (string) ($input['method'] ?? '');

        if ($method === 'pickup') {
            $branchId = (string) ($input['branch_id'] ?? '');
            $branch = Uuid::isValid($branchId) ? $this->repository->branch($branchId) : null;
            if ($branch === null || $branch['status'] !== 'active' || !(bool) $branch['is_pickup_point']) {
                throw HttpException::unprocessable(['fulfillment.branch_id' => 'Choose a branch to pick up from.']);
            }

            return [
                'method' => 'pickup', 'stock_branch_id' => $branchId, 'pickup_branch_id' => $branchId, 'ship_from_branch_id' => null,
                'ship_to' => null, 'public' => ['method' => 'pickup', 'branch_id' => $branchId],
            ];
        }

        if ($method === 'delivery') {
            $originId = $this->repository->deliveryOriginId();
            if ($originId === null) {
                throw HttpException::unprocessable(['fulfillment.method' => 'Delivery is not available right now. Please choose pickup.']);
            }

            $errors = [];
            $address = [];
            foreach (['line1' => [3, 150, true], 'barangay' => [0, 80, false], 'city' => [2, 80, true], 'province' => [2, 80, true], 'postal_code' => [0, 10, false], 'recipient' => [0, 120, false], 'phone' => [0, 20, false]] as $field => [$min, $max, $required]) {
                $value = trim((string) ($input[$field] ?? ''));
                if (($required || $value !== '') && (mb_strlen($value) < $min || mb_strlen($value) > $max)) {
                    $errors["fulfillment.$field"] = $required ? 'This field is required.' : 'This value is too long.';
                }
                $address[$field] = $value;
            }
            if ($errors !== []) {
                throw HttpException::unprocessable($errors);
            }

            return [
                'method' => 'delivery', 'stock_branch_id' => $originId, 'pickup_branch_id' => null, 'ship_from_branch_id' => $originId,
                'ship_to' => $address, 'public' => ['method' => 'delivery', 'address' => $address],
            ];
        }

        throw HttpException::unprocessable(['fulfillment.method' => 'Choose pickup or delivery.']);
    }
}
