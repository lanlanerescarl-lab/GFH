<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\BranchRepository;
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\Format;
use Gfh\Core\Support\ListQuery;

/**
 * What the Shop's order pages show a signed-in customer. It adds no order rule: orders are read through
 * OrderService (the same calls as the JSON API) and ownership is decided by CustomerAccessService, so a customer
 * reads only their own orders and any other id is "not found".
 *
 * Its own job is to pick the fields a customer may see. OrderService::getOrder() is built for staff and carries
 * line costs, the staff user who recorded a payment and who changed a step, so the pages never pass its result on
 * as it is.
 *
 * It is also the one place that decides what a customer may see of an order. The JSON API for customers
 * (GET /orders, GET /orders/{id}, POST /checkout) reads orders through this class too, so the field lists below
 * are shared by the pages and the API and cannot drift apart. Never add a cost, margin, profit, staff user
 * reference (recorded_by, changed_by, created_by) or staff-only note to any of them.
 */
final class ShopOrderService
{
    /** Order fields shown on the order page and returned by the API, as the same values. */
    public const ORDER_FIELDS = [
        'id', 'order_number', 'placed_at', 'fulfillment_method', 'subtotal', 'discount_total', 'delivery_fee',
        'assembly_fee', 'installation_fee', 'vat_amount', 'total', 'deposit_due', 'promised_date', 'cancelled_at', 'payment_status',
    ];

    /** What the API adds to ORDER_FIELDS: the step, the address and branch, the lists, and the path and payment summary. */
    public const API_ORDER_EXTRA = ['progress_key', 'pickup_branch', 'ship_to', 'lines', 'payments', 'history', 'path', 'payment_summary'];

    /** One order line, on the page and in the API. No cost, no variant or line id. */
    public const LINE_FIELDS = ['name', 'sku', 'qty', 'unit_price', 'line_total', 'is_made_to_order'];

    /** One payment, on the page and in the API. No reference and never the staff member who recorded it. */
    public const PAYMENT_FIELDS = ['method', 'amount', 'paid_at'];

    /** What the API adds to each payment: the page lists recorded payments only, the API also says which are not. */
    public const API_PAYMENT_EXTRA = ['status'];

    /** The delivery address as the customer gave it. */
    public const SHIP_TO_FIELDS = ['recipient', 'line1', 'barangay', 'city', 'province', 'postal_code', 'phone'];

    /** One row of the customer's order list, on the page and in the API. */
    public const LIST_FIELDS = [
        'id', 'order_number', 'placed_at', 'progress_key', 'progress_label', 'payment_status', 'payment_label',
        'fulfillment_method', 'total', 'cancelled',
    ];

    public function __construct(
        private readonly OrderService $orders,
        private readonly CustomerAccessService $access,
        private readonly BranchRepository $branches,
    ) {
    }

    /**
     * The customer's own orders, newest first by default, one page.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string}
     */
    public function listFor(CustomerAccount $account, ListQuery $query): array
    {
        $record = $this->orders->customerForAccount($account->id);
        if ($record === null) {
            // Never ordered here: no organization customer record yet, so there is nothing to list.
            return $query->result([], 0);
        }

        $result = $this->orders->listOrders($query, (string) $record['id']);
        $result['items'] = array_map(fn (array $row): array => $this->listItem($row), $result['items']);

        return $result;
    }

    /**
     * One of the customer's own orders, ready for the detail page and the receipt.
     *
     * @return array<string, mixed>
     * @throws HttpException 404 when the id is unknown, malformed or another customer's (the same answer for all three)
     */
    public function detailFor(CustomerAccount $account, string $orderId): array
    {
        $this->access->assertCanViewOrder($account, $orderId);
        $order = $this->orders->getOrder($orderId);

        $total = (string) $order['total'];
        $paid = (string) $order['payment_summary']['paid'];
        $netUnits = (Decimal::parse($total, 2) ?? 0) - (Decimal::parse((string) $order['vat_amount'], 2) ?? 0);

        // The page lists recorded payments only.
        $recorded = array_values(array_filter($order['payments'], static fn (array $payment): bool => $payment['status'] === 'recorded'));

        return [
            'id' => (string) $order['id'],
            'order_number' => (string) $order['order_number'],
            'placed_at' => (string) $order['placed_at'],
            'fulfillment_method' => (string) $order['fulfillment_method'],
            'pickup_branch' => $this->pickupBranch($order['pickup_branch_id'] ?? null),
            'ship_to' => $this->shipTo($order['ship_to']),
            'lines' => $this->lines($order['lines']),
            'subtotal' => (string) $order['subtotal'],
            'discount_total' => (string) $order['discount_total'],
            'delivery_fee' => (string) $order['delivery_fee'],
            'assembly_fee' => (string) $order['assembly_fee'],
            'installation_fee' => (string) $order['installation_fee'],
            'vat_amount' => (string) $order['vat_amount'],
            'net_of_vat' => Decimal::format($netUnits, 2),
            'total' => $total,
            'deposit_due' => (string) $order['deposit_due'],
            'promised_date' => $order['promised_date'] === null ? null : (string) $order['promised_date'],
            'cancelled_at' => $order['cancelled_at'] === null ? null : (string) $order['cancelled_at'],
            'payment_status' => (string) $order['payment_status'],
            'payment_label' => Format::paymentStatus((string) $order['payment_status'], $paid, $total),
            'paid' => $paid,
            'balance' => (string) $order['payment_summary']['balance'],
            'payments' => $this->payments($recorded, false),
            'timeline' => $this->timeline($order),
        ];
    }

    /**
     * One of the customer's own orders for the JSON API: the same checks as detailFor(), the same field lists, and
     * the path, history and payment summary that docs/api.md documents. Costs and staff ids are never in it.
     *
     * @return array<string, mixed>
     * @throws HttpException 404 when the id is unknown, malformed or another customer's
     */
    public function apiDetailFor(CustomerAccount $account, string $orderId): array
    {
        $this->access->assertCanViewOrder($account, $orderId);

        return $this->forApi($this->orders->getOrder($orderId));
    }

    /**
     * The customer-safe form of an order as OrderService::getOrder() returns it. Used by apiDetailFor() and by the
     * checkout answer, so the API answers the same way wherever it returns an order. A field not named in the
     * lists of this class is not in the result.
     *
     * @param array<string, mixed> $order getOrder() result
     * @return array<string, mixed>
     */
    public function forApi(array $order): array
    {
        $result = [];
        foreach (self::ORDER_FIELDS as $field) {
            $result[$field] = in_array($field, ['promised_date', 'cancelled_at'], true) && $order[$field] === null ? null : (string) $order[$field];
        }

        $result['progress_key'] = (string) $order['progress_key'];
        $result['pickup_branch'] = $this->pickupBranch($order['pickup_branch_id'] ?? null);
        $result['ship_to'] = $this->shipTo($order['ship_to']);
        $result['lines'] = $this->lines($order['lines']);
        $result['payments'] = $this->payments($order['payments'], true);
        $result['history'] = array_map(static fn (array $entry): array => [
            'progress_key' => (string) $entry['progress_key'],
            'label' => (string) $entry['label'],
            'changed_at' => (string) $entry['changed_at'],
        ], $order['history']);
        $result['path'] = array_map(static fn (array $step): array => [
            'key' => (string) $step['key'],
            'label' => (string) $step['label'],
            'maps_to' => (string) $step['maps_to'],
            'is_terminal' => (bool) $step['is_terminal'],
            'current' => (bool) $step['current'],
        ], $order['path']);
        $result['payment_summary'] = [
            'status' => (string) $order['payment_summary']['status'],
            'paid' => (string) $order['payment_summary']['paid'],
            'balance' => (string) $order['payment_summary']['balance'],
            'deposit_due' => (string) $order['payment_summary']['deposit_due'],
        ];

        return $result;
    }

    /**
     * Filter choices for the order list: the order steps, in order, as {key, label}.
     *
     * @return list<array{key: string, label: string}>
     */
    public function statusOptions(): array
    {
        $options = [];
        foreach ($this->orders->allSteps() as $step) {
            $options[] = ['key' => (string) $step['key'], 'label' => (string) $step['label']];
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function listItem(array $row): array
    {
        $item = [];
        foreach (self::LIST_FIELDS as $field) {
            $item[$field] = match ($field) {
                'payment_label' => Format::paymentStatus((string) $row['payment_status'], (string) $row['paid_total'], (string) $row['total']),
                'cancelled' => $row['cancelled_at'] !== null,
                default => (string) $row[$field],
            };
        }

        return $item;
    }

    /**
     * The order's path as done, current and upcoming steps, with the date each step was reached (Asia/Manila is applied
     * when the date is shown). A step with no history row has no date.
     *
     * @param array<string, mixed> $order getOrder() result
     * @return list<array{key: string, label: string, state: string, reached_at: ?string}>
     */
    private function timeline(array $order): array
    {
        $reached = [];
        foreach ($order['history'] as $entry) {
            // The first time a step was reached is the date that counts, even if an Admin moved the order back later.
            $reached[(string) $entry['progress_key']] ??= (string) $entry['changed_at'];
        }

        $currentIndex = null;
        foreach ($order['path'] as $index => $step) {
            if ($step['current']) {
                $currentIndex = $index;
            }
        }

        $timeline = [];
        foreach ($order['path'] as $index => $step) {
            $state = 'upcoming';
            if ($currentIndex !== null) {
                $state = $index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'upcoming');
            }
            $timeline[] = [
                'key' => (string) $step['key'],
                'label' => (string) $step['label'],
                'state' => $state,
                'reached_at' => $state === 'upcoming' ? null : ($reached[(string) $step['key']] ?? null),
            ];
        }

        return $timeline;
    }

    /** @return array{name: string, address: string}|null */
    private function pickupBranch(mixed $branchId): ?array
    {
        if (!is_string($branchId) || $branchId === '') {
            return null;
        }
        $branch = $this->branches->find($branchId);
        if ($branch === null) {
            return null;
        }

        $address = array_filter(
            array_map(static fn (string $key): string => trim((string) ($branch[$key] ?? '')), ['address', 'city', 'province']),
            static fn (string $part): bool => $part !== '',
        );

        return ['name' => (string) $branch['name'], 'address' => implode(', ', $address)];
    }

    /**
     * The delivery address as the customer gave it.
     *
     * @return array<string, string>|null
     */
    private function shipTo(mixed $shipTo): ?array
    {
        if (!is_array($shipTo)) {
            return null;
        }

        $address = [];
        foreach (self::SHIP_TO_FIELDS as $key) {
            $address[$key] = trim((string) ($shipTo[$key] ?? ''));
        }

        return $address;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, string|bool>>
     */
    private function lines(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $row = [];
            foreach (self::LINE_FIELDS as $field) {
                $row[$field] = $field === 'is_made_to_order' ? (bool) $line[$field] : (string) $line[$field];
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $payments
     * @return list<array<string, string>>
     */
    private function payments(array $payments, bool $withStatus): array
    {
        $fields = $withStatus ? [...self::PAYMENT_FIELDS, ...self::API_PAYMENT_EXTRA] : self::PAYMENT_FIELDS;
        $result = [];
        foreach ($payments as $payment) {
            $row = [];
            foreach ($fields as $field) {
                $row[$field] = (string) $payment[$field];
            }
            $result[] = $row;
        }

        return $result;
    }
}
