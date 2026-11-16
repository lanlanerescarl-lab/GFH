<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Database\Database;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Payments\PaymentProvider;
use Gfh\Core\Repository\PosRepository;
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\QuantityRules;
use Gfh\Core\Support\Uuid;

/**
 * The server side of the point of sale: shifts, the offline snapshot, and sale sync.
 *
 * A terminal sells offline and uploads later, so sync has three rules:
 *  - idempotent: a sale carries the id the terminal gave it; uploading it again changes nothing and returns the first result;
 *  - never rejected for stock: a sale that already happened is always recorded. If it sold more than was on record,
 *    or a stock rule (such as an expired batch) objected, it is kept and flagged needs_review for a person to look at;
 *  - recomputed: totals are worked out here from the unit prices and quantities sent, never copied from the terminal.
 * Receipt numbers are the terminal's code and a counter, like T3-000123, so two terminals cannot collide.
 */
final class PosService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly PosRepository $repository,
        private readonly PricingService $pricing,
        private readonly InventoryService $inventory,
        private readonly AuditService $audit,
        private readonly PaymentProvider $payments,
    ) {
    }

    /**
     * Everything a terminal downloads to sell offline: its details, active products with prices and the stock
     * on record at its branch, quantity price tiers, the active discount rules, payment methods and a content tag.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $terminalId): array
    {
        $terminal = $this->terminal($terminalId);

        $tiers = [];
        foreach ($this->repository->quantityTiers() as $tier) {
            $tiers[(string) $tier['variant_id']][] = ['label' => $tier['label'], 'min_qty' => $tier['min_qty'], 'price' => $tier['price']];
        }

        $items = [];
        foreach ($this->repository->snapshotVariants((string) $terminal['branch_id']) as $row) {
            $items[] = [
                'variant_id' => $row['variant_id'],
                'product_id' => $row['product_id'],
                'sku' => $row['sku'],
                'barcode' => $row['barcode'],
                'name' => $row['product_name'] . ((string) $row['variant_name'] !== '' ? ' - ' . $row['variant_name'] : ''),
                'unit' => $row['unit'],
                'price' => $row['retail_price'],
                'allow_decimal_qty' => (bool) $row['allow_decimal_qty'],
                'qty_step' => $row['qty_step'],
                'min_qty' => $row['min_qty'],
                'track_batches' => (bool) $row['track_batches'],
                'media_id' => $row['media_id'],
                'on_hand' => $row['on_hand'],
                'tiers' => $tiers[(string) $row['variant_id']] ?? [],
            ];
        }

        $data = [
            'terminal' => ['id' => $terminal['id'], 'code' => $terminal['code'], 'name' => $terminal['name'], 'branch_id' => $terminal['branch_id'], 'branch_name' => $terminal['branch_name']],
            'vat_rate_percent' => PricingService::VAT_RATE_PERCENT,
            'payment_methods' => $this->payments->methods(),
            'discount_rules' => $this->repository->activeDiscountRules(),
            'products' => $items,
        ];

        $data['etag'] = sha1(json_encode($data, JSON_THROW_ON_ERROR));
        $data['generated_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        return $data;
    }

    /**
     * Opens a shift on a terminal with the opening cash count.
     *
     * @return array<string, mixed> the shift and the next receipt counter to use
     */
    public function openShift(string $terminalId, string $cashierId, mixed $openingCash): array
    {
        $terminal = $this->terminal($terminalId);
        $cash = Decimal::parse($openingCash, 2);
        if ($cash === null || $cash < 0) {
            throw HttpException::unprocessable(['opening_cash' => 'Enter the opening cash, like 2000.00.']);
        }
        if ($terminal['status'] !== 'active') {
            throw HttpException::unprocessable(['terminal_id' => 'This terminal is switched off.']);
        }

        return Database::transaction($this->pdo, function () use ($terminal, $cashierId, $cash): array {
            if ($this->repository->openShift((string) $terminal['id']) !== null) {
                throw HttpException::conflict('This terminal already has an open shift. Close it first.');
            }

            $id = $this->repository->insertShift((string) $terminal['id'], $cashierId, Decimal::format($cash, 2));
            $this->audit->organization($this->pdo, 'pos.shift_opened', $cashierId, 'shift', $id, ['terminal' => $terminal['code'], 'opening_cash' => Decimal::format($cash, 2)]);

            return [
                'shift' => $this->repository->shift($id),
                'next_receipt_number' => sprintf('%s-%06d', $terminal['code'], $this->repository->nextReceiptSequence((string) $terminal['id'])),
            ];
        });
    }

    /** The user who opened a shift, or null when there is no such shift. Controllers use it to keep a cashier to their own shift. */
    public function shiftOwnerId(string $shiftId): ?string
    {
        $shift = Uuid::isValid($shiftId) ? $this->repository->shift($shiftId) : null;

        return $shift === null ? null : (string) $shift['cashier_user_id'];
    }

    /** @return array<string, mixed>|null */
    public function currentShift(string $terminalId): ?array
    {
        $this->terminal($terminalId);

        return $this->repository->openShift($terminalId);
    }

    /**
     * Closes a shift with the counted cash. Expected cash is the opening cash plus the cash payments of its sales.
     *
     * @return array<string, mixed> the closed shift
     */
    public function closeShift(string $shiftId, mixed $countedCash, ?string $actorId): array
    {
        $counted = Decimal::parse($countedCash, 2);
        if ($counted === null || $counted < 0) {
            throw HttpException::unprocessable(['counted_cash' => 'Enter the counted cash, like 5230.50.']);
        }

        return Database::transaction($this->pdo, function () use ($shiftId, $counted, $actorId): array {
            $shift = (Uuid::isValid($shiftId) ? $this->repository->shift($shiftId, true) : null) ?? throw HttpException::notFound('That shift could not be found.');
            if ($shift['status'] !== 'open') {
                throw HttpException::unprocessable(['shift' => 'This shift is already closed.']);
            }

            $expected = (Decimal::parse((string) $shift['opening_cash'], 2) ?? 0) + (Decimal::parse($this->repository->cashTakenOnShift($shiftId), 2) ?? 0);
            $this->repository->closeShift($shiftId, Decimal::format($counted, 2), Decimal::format($expected, 2), Decimal::format($counted - $expected, 2));
            $this->audit->organization($this->pdo, 'pos.shift_closed', $actorId, 'shift', $shiftId, [
                'expected' => Decimal::format($expected, 2), 'counted' => Decimal::format($counted, 2), 'variance' => Decimal::format($counted - $expected, 2),
            ]);

            return $this->repository->shift($shiftId) ?? [];
        });
    }

    /**
     * Records a sale uploaded from a terminal.
     *
     * @param array<string, mixed> $payload id, receipt_number, terminal_id, shift_id, sold_at, lines[], payments[], discount, customer_id
     * @return array<string, mixed> sale_id, receipt_number, status, review_reason, total, duplicate
     * @throws HttpException 422 for a structurally bad sale, 409 for a receipt number used by a different sale
     */
    public function syncSale(array $payload): array
    {
        $id = (string) ($payload['id'] ?? '');
        if (!Uuid::isValid($id)) {
            throw HttpException::unprocessable(['id' => 'Every sale needs the id the terminal gave it.']);
        }

        $already = $this->repository->sale($id);
        if ($already !== null) {
            return $this->summary($already, true);
        }

        return Database::transaction($this->pdo, function () use ($payload, $id): array {
            // Two uploads of the same sale at once: the second waits here, then finds the first one's sale.
            $this->pdo->exec("SELECT pg_advisory_xact_lock(hashtext('pos_sale:" . $id . "'))");
            $again = $this->repository->sale($id);
            if ($again !== null) {
                return $this->summary($again, true);
            }

            $checked = $this->validateSale($payload, $id);
            $totals = $checked['totals'];

            // Stock first: nothing here may reject the sale, it only reports what looks wrong.
            $reasons = [];
            $costs = [];
            $firstBatch = [];
            foreach ($checked['lines'] as $index => $line) {
                $sold = $this->inventory->sell(
                    (string) $checked['terminal']['branch_id'], $line['variant_id'], Decimal::format($line['quantity_milli'], 3), 'pos', 'pos_sale', $id,
                    (string) $checked['shift']['cashier_user_id'], true, null, $id . ':' . $index, true,
                );
                $costs[$index] = $sold['unit_cost'];
                $firstBatch[$index] = $sold['picks'][0]['batch_id'] ?? null;

                if ($sold['oversold']) {
                    $reasons[] = sprintf('%s: sold %s more than the stock on record', $line['sku'], rtrim(rtrim($sold['short_by'], '0'), '.'));
                }
                if ($sold['refused'] !== null) {
                    $reasons[] = sprintf('%s: %s', $line['sku'], $sold['refused']);
                }
            }

            $status = $reasons === [] ? 'completed' : 'needs_review';
            $this->repository->insertSale([
                'id' => $id,
                'receipt_number' => $checked['receipt_number'],
                'shift_id' => $checked['shift']['id'],
                'terminal_id' => $checked['terminal']['id'],
                'branch_id' => $checked['terminal']['branch_id'],
                'cashier_user_id' => $checked['shift']['cashier_user_id'],
                'customer_id' => $checked['customer_id'],
                'sold_at' => $checked['sold_at'],
                'subtotal' => Decimal::format($totals['subtotal'], 2),
                'discount_total' => Decimal::format($totals['discount_total'], 2),
                'vat_amount' => Decimal::format($totals['vat_amount'], 2),
                'total' => Decimal::format($totals['total'], 2),
                'status' => $status,
                'review_reason' => $reasons === [] ? null : mb_substr(implode('; ', $reasons), 0, 500),
            ]);

            foreach ($checked['lines'] as $index => $line) {
                $this->repository->insertSaleLine($id, [
                    'variant_id' => $line['variant_id'],
                    'batch_id' => $firstBatch[$index],
                    'sku' => $line['sku'],
                    'name' => $line['name'],
                    'qty' => Decimal::format($line['quantity_milli'], 3),
                    'unit_price' => Decimal::format($line['unit_price'], 2),
                    'discount' => Decimal::format($line['discount'], 2),
                    'unit_cost' => $costs[$index],
                    'line_total' => Decimal::format($totals['line_totals'][$index], 2),
                ]);
            }

            if ($checked['rule'] !== null) {
                $this->repository->insertSaleDiscount($id, (string) $checked['rule']['id'], Decimal::format($totals['discount_total'], 2), $checked['id_last4']);
            }
            foreach ($checked['payments'] as $payment) {
                $this->repository->insertSalePayment($id, $payment['method'], Decimal::format($payment['amount'], 2), $payment['reference'], (string) $checked['shift']['cashier_user_id'], $checked['sold_at']);
            }

            if ($status === 'needs_review') {
                $this->audit->organization($this->pdo, 'pos.sale_flagged', (string) $checked['shift']['cashier_user_id'], 'pos_sale', $id, ['reason' => implode('; ', $reasons)]);
            }

            return $this->summary($this->repository->sale($id) ?? [], false);
        });
    }

    /** A manager looked at a flagged sale and accepted it. */
    public function resolveReview(string $saleId, string $note, ?string $actorId): array
    {
        $note = trim($note);
        if (mb_strlen($note) < 3 || mb_strlen($note) > 255) {
            throw HttpException::unprocessable(['note' => 'Write a short note on what you checked (3 to 255 characters).']);
        }

        $sale = (Uuid::isValid($saleId) ? $this->repository->sale($saleId) : null) ?? throw HttpException::notFound('That sale could not be found.');
        if ($sale['status'] !== 'needs_review') {
            throw HttpException::unprocessable(['sale' => 'This sale is not waiting for review.']);
        }

        $this->repository->setSaleStatus($saleId, 'completed');
        $this->audit->organization($this->pdo, 'pos.review_resolved', $actorId, 'pos_sale', $saleId, ['note' => $note, 'reason' => $sale['review_reason']]);

        return $this->getSale($saleId);
    }

    /** @return array<string, mixed> */
    public function getSale(string $saleId): array
    {
        $sale = (Uuid::isValid($saleId) ? $this->repository->sale($saleId) : null) ?? throw HttpException::notFound('That sale could not be found.');
        $sale['lines'] = $this->repository->saleLines($saleId);
        $sale['payments'] = $this->repository->salePayments($saleId);
        $sale['discounts'] = $this->repository->saleDiscounts($saleId);

        return $sale;
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} */
    public function listSales(ListQuery $query): array
    {
        [$rows, $total] = $this->repository->listSales($query);

        return $query->result($rows, $total);
    }

    /** @return list<array<string, mixed>> */
    public function terminals(): array
    {
        return $this->repository->terminals();
    }

    /** How many sales wait in the needs-review queue. */
    public function needsReviewCount(): int
    {
        return $this->repository->needsReviewCount();
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string, mixed> */
    private function terminal(string $terminalId): array
    {
        return (Uuid::isValid($terminalId) ? $this->repository->terminal($terminalId) : null) ?? throw HttpException::notFound('That terminal could not be found.');
    }

    /**
     * @param array<string, mixed> $sale
     * @return array<string, mixed>
     */
    private function summary(array $sale, bool $duplicate): array
    {
        return [
            'sale_id' => $sale['id'],
            'receipt_number' => $sale['receipt_number'],
            'status' => $sale['status'],
            'review_reason' => $sale['review_reason'],
            'total' => $sale['total'],
            'duplicate' => $duplicate,
        ];
    }

    /**
     * Checks everything about an uploaded sale and works out its totals.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function validateSale(array $p, string $id): array
    {
        $errors = [];

        $terminal = Uuid::isValid($p['terminal_id'] ?? null) ? $this->repository->terminal((string) $p['terminal_id']) : null;
        if ($terminal === null) {
            throw HttpException::unprocessable(['terminal_id' => 'Unknown terminal.']);
        }

        $shift = Uuid::isValid($p['shift_id'] ?? null) ? $this->repository->shift((string) $p['shift_id']) : null;
        if ($shift === null || $shift['terminal_id'] !== $terminal['id']) {
            throw HttpException::unprocessable(['shift_id' => 'That shift does not belong to this terminal.']);
        }

        $soldAt = null;
        try {
            $soldAt = new \DateTimeImmutable((string) ($p['sold_at'] ?? ''));
        } catch (\Exception) {
            $errors['sold_at'] = 'Use the date and time of the sale, like 2026-11-12T14:30:00+08:00.';
        }
        if ($soldAt !== null && $soldAt > new \DateTimeImmutable('+10 minutes')) {
            $errors['sold_at'] = 'The sale is dated in the future. Check the terminal clock.';
        }
        if ($soldAt !== null && $shift['status'] === 'closed' && $shift['closed_at'] !== null && $soldAt > new \DateTimeImmutable((string) $shift['closed_at'])) {
            throw HttpException::conflict('This sale was made after its shift was closed.');
        }

        $receipt = (string) ($p['receipt_number'] ?? '');
        if (preg_match('/^' . preg_quote((string) $terminal['code'], '/') . '-\d{6,9}$/', $receipt) !== 1) {
            $errors['receipt_number'] = sprintf('Receipt numbers for this terminal look like %s-000123.', $terminal['code']);
        } else {
            $other = $this->repository->saleIdByReceipt($receipt);
            if ($other !== null && $other !== $id) {
                throw HttpException::conflict('That receipt number is already used by a different sale.', ['receipt_number' => 'Already used.']);
            }
        }

        $customerId = null;
        if (isset($p['customer_id']) && $p['customer_id'] !== '' && $p['customer_id'] !== null) {
            if (!Uuid::isValid($p['customer_id']) || !$this->repository->customerExists((string) $p['customer_id'])) {
                $errors['customer_id'] = 'Unknown customer.';
            } else {
                $customerId = (string) $p['customer_id'];
            }
        }

        $rule = null;
        $idLast4 = null;
        $discount = $p['discount'] ?? null;
        if (is_array($discount) && ($discount['key'] ?? '') !== '') {
            $rule = $this->repository->discountRule((string) $discount['key']);
            if ($rule === null || !(bool) $rule['active']) {
                $errors['discount'] = 'That discount is not available.';
            }
            $idLast4 = isset($discount['id_ref_last4']) && $discount['id_ref_last4'] !== '' ? (string) $discount['id_ref_last4'] : null;
            if ($idLast4 !== null && preg_match('/^[A-Za-z0-9]{1,4}$/', $idLast4) !== 1) {
                $errors['discount.id_ref_last4'] = 'Enter only the last 4 characters of the ID.';
            }
        }

        $rawLines = is_array($p['lines'] ?? null) ? array_values($p['lines']) : [];
        if ($rawLines === [] || count($rawLines) > 200) {
            $errors['lines'] = 'A sale needs between 1 and 200 lines.';
        }
        $variants = $this->repository->variants(array_map(static fn (mixed $l): string => is_array($l) ? (string) ($l['variant_id'] ?? '') : '', $rawLines));

        $lines = [];
        foreach ($rawLines as $index => $line) {
            $variant = is_array($line) ? ($variants[(string) ($line['variant_id'] ?? '')] ?? null) : null;
            if ($variant === null) {
                $errors["lines.$index.variant_id"] = 'Unknown product.';
                continue;
            }
            try {
                $milli = QuantityRules::forSale($variant, $line['quantity'] ?? null);
            } catch (\InvalidArgumentException $exception) {
                $errors["lines.$index.quantity"] = $exception->getMessage();
                continue;
            }
            $price = Decimal::parse($line['unit_price'] ?? null, 2);
            $lineDiscount = Decimal::parse($line['discount'] ?? '0', 2);
            if ($price === null || $price < 0) {
                $errors["lines.$index.unit_price"] = 'Enter the price the item was sold at.';
                continue;
            }
            if ($lineDiscount === null || $lineDiscount < 0) {
                $errors["lines.$index.discount"] = 'Enter a discount of zero or more.';
                continue;
            }

            $lines[] = [
                'variant_id' => (string) $variant['id'],
                'sku' => (string) $variant['sku'],
                'name' => $variant['product_name'] . ((string) $variant['name'] !== '' ? ' - ' . $variant['name'] : ''),
                'quantity_milli' => $milli,
                'unit_price' => $price,
                'discount' => $lineDiscount,
            ];
        }

        $payments = [];
        $paid = 0;
        $rawPayments = is_array($p['payments'] ?? null) ? array_values($p['payments']) : [];
        if ($rawPayments === [] || count($rawPayments) > 10) {
            $errors['payments'] = 'Record 1 to 10 payments.';
        }
        foreach ($rawPayments as $index => $payment) {
            try {
                $clean = $this->payments->validate(is_array($payment) ? $payment : []);
            } catch (HttpException $exception) {
                foreach ($exception->fields() as $field => $message) {
                    $errors["payments.$index.$field"] = $message;
                }
                continue;
            }
            $amount = Decimal::parse(is_array($payment) ? ($payment['amount'] ?? null) : null, 2);
            if ($amount === null || $amount <= 0) {
                $errors["payments.$index.amount"] = 'Enter the amount paid with this method.';
                continue;
            }
            $paid += $amount;
            $payments[] = $clean + ['amount' => $amount];
        }

        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        try {
            $totals = $this->pricing->totalsForSale(
                array_map(static fn (array $l): array => ['quantity_milli' => $l['quantity_milli'], 'unit_price' => $l['unit_price'], 'discount' => $l['discount']], $lines),
                $rule,
            );
        } catch (\RangeException $exception) {
            throw HttpException::unprocessable(['lines' => $exception->getMessage()]);
        }

        if ($paid !== $totals['total']) {
            throw HttpException::unprocessable(['payments' => sprintf('The payments add up to %s but the sale total is %s.', Decimal::format($paid, 2), Decimal::format($totals['total'], 2))]);
        }

        return [
            'terminal' => $terminal,
            'shift' => $shift,
            'receipt_number' => $receipt,
            'sold_at' => $soldAt->format('Y-m-d H:i:sP'),
            'customer_id' => $customerId,
            'rule' => $rule,
            'id_last4' => $idLast4,
            'lines' => $lines,
            'payments' => $payments,
            'totals' => $totals,
        ];
    }
}
