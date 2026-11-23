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
 */
final class ShopOrderService
{
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

        $payments = [];
        foreach ($order['payments'] as $payment) {
            if ($payment['status'] !== 'recorded') {
                continue;
            }
            $payments[] = [
                'method' => (string) $payment['method'],
                'amount' => (string) $payment['amount'],
                'paid_at' => (string) $payment['paid_at'],
            ];
        }

        $lines = [];
        foreach ($order['lines'] as $line) {
            $lines[] = [
                'name' => (string) $line['name'],
                'sku' => (string) $line['sku'],
                'qty' => (string) $line['qty'],
                'unit_price' => (string) $line['unit_price'],
                'line_total' => (string) $line['line_total'],
                'is_made_to_order' => (bool) $line['is_made_to_order'],
            ];
        }

        return [
            'id' => (string) $order['id'],
            'order_number' => (string) $order['order_number'],
            'placed_at' => (string) $order['placed_at'],
            'fulfillment_method' => (string) $order['fulfillment_method'],
            'pickup_branch' => $this->pickupBranch($order['pickup_branch_id'] ?? null),
            'ship_to' => $this->shipTo($order['ship_to']),
            'lines' => $lines,
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
            'payments' => $payments,
            'timeline' => $this->timeline($order),
        ];
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
        return [
            'id' => (string) $row['id'],
            'order_number' => (string) $row['order_number'],
            'placed_at' => (string) $row['placed_at'],
            'progress_key' => (string) $row['progress_key'],
            'progress_label' => (string) $row['progress_label'],
            'payment_status' => (string) $row['payment_status'],
            'payment_label' => Format::paymentStatus((string) $row['payment_status'], (string) $row['paid_total'], (string) $row['total']),
            'fulfillment_method' => (string) $row['fulfillment_method'],
            'total' => (string) $row['total'],
            'cancelled' => $row['cancelled_at'] !== null,
        ];
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
        foreach (['recipient', 'line1', 'barangay', 'city', 'province', 'postal_code', 'phone'] as $key) {
            $address[$key] = trim((string) ($shipTo[$key] ?? ''));
        }

        return $address;
    }
}
