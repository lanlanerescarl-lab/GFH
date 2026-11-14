<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Database\Database;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\ProcurementRepository;
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\QuantityRules;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Support\Validation;

/**
 * Suppliers (which may be contract manufacturers) and purchase orders.
 * Receiving a purchase order line brings accepted stock in through InventoryService::receive at the line's cost
 * (which updates the weighted-average cost), records rejected quantities without stock, and captures a batch code,
 * expiry date and bin for variants that track batches.
 */
final class ProcurementService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly ProcurementRepository $repository,
        private readonly InventoryService $inventory,
        private readonly AuditService $audit,
    ) {
    }

    // ------------------------------------------------------------------ suppliers

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} */
    public function listSuppliers(ListQuery $query): array
    {
        [$rows, $total] = $this->repository->listSuppliers($query);

        return $query->result($rows, $total);
    }

    /** @return array<string, mixed> */
    public function getSupplier(string $id): array
    {
        return (Uuid::isValid($id) ? $this->repository->supplier($id) : null) ?? throw HttpException::notFound('That supplier could not be found.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: string}
     */
    public function createSupplier(array $data, ?string $actorId): array
    {
        $fields = $this->supplierFields($data, null);
        $id = $this->repository->createSupplier($fields, $actorId);
        $this->audit->organization($this->pdo, 'supplier.created', $actorId, 'supplier', $id, ['name' => $fields['name']]);

        return ['id' => $id];
    }

    /** @param array<string, mixed> $data */
    public function updateSupplier(string $id, array $data, ?string $actorId): void
    {
        $existing = $this->getSupplier($id);
        $fields = $this->supplierFields($data + $existing, $id);
        $this->repository->updateSupplier($id, $fields);
        $this->audit->organization($this->pdo, 'supplier.updated', $actorId, 'supplier', $id, ['name' => $fields['name']]);
    }

    public function setSupplierStatus(string $id, string $status, ?string $actorId): void
    {
        $this->getSupplier($id);
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw HttpException::unprocessable(['status' => 'Choose active or inactive.']);
        }
        $this->repository->setSupplierStatus($id, $status);
        $this->audit->organization($this->pdo, 'supplier.status_changed', $actorId, 'supplier', $id, ['status' => $status]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function supplierFields(array $data, ?string $supplierId): array
    {
        $errors = [];

        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($data['name'] ?? '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter the supplier name (2 to 120 characters).';
        } else {
            $other = $this->repository->supplierIdByName($name);
            if ($other !== null && $other !== $supplierId) {
                $errors['name'] = 'A supplier with this name already exists.';
            }
        }

        $email = null;
        if (isset($data['contact_email']) && trim((string) $data['contact_email']) !== '') {
            $email = Validation::email($data['contact_email']);
            if ($email === null) {
                $errors['contact_email'] = 'Enter a valid email address, or leave it empty.';
            }
        }

        $phone = Validation::phone($data['contact_phone'] ?? null);
        if ($phone === null) {
            $errors['contact_phone'] = 'Enter a valid phone number, or leave it empty.';
        }

        $lead = null;
        if (isset($data['lead_time_days']) && $data['lead_time_days'] !== '') {
            $lead = filter_var($data['lead_time_days'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 365]]);
            if ($lead === false) {
                $errors['lead_time_days'] = 'Enter a whole number of days from 0 to 365.';
                $lead = null;
            }
        }

        foreach (['contact_name' => 120, 'address' => 255, 'payment_terms' => 80] as $field => $max) {
            if (isset($data[$field]) && mb_strlen((string) $data[$field]) > $max) {
                $errors[$field] = sprintf('Use at most %d characters.', $max);
            }
        }

        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        $text = static fn (string $key): ?string => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        return [
            'name' => $name,
            'contact_name' => $text('contact_name'),
            'contact_email' => $email,
            'contact_phone' => $phone === '' ? null : $phone,
            'address' => $text('address'),
            'payment_terms' => $text('payment_terms'),
            'lead_time_days' => $lead,
        ];
    }

    // ------------------------------------------------------------------ purchase orders

    /**
     * Creates a draft purchase order.
     *
     * @param array<string, mixed> $data supplier_id, branch_id, expected_on, notes, lines[] of {variant_id, quantity, unit_cost}
     * @return array{id: string, po_number: string}
     */
    public function createPurchaseOrder(array $data, ?string $actorId): array
    {
        $errors = [];

        $supplierId = (string) ($data['supplier_id'] ?? '');
        $supplier = Uuid::isValid($supplierId) ? $this->repository->supplier($supplierId) : null;
        if ($supplier === null || $supplier['status'] !== 'active') {
            $errors['supplier_id'] = 'Choose an active supplier.';
        }

        $branchId = (string) ($data['branch_id'] ?? '');
        $branch = Uuid::isValid($branchId) ? $this->repository->branch($branchId) : null;
        if ($branch === null || $branch['status'] !== 'active') {
            $errors['branch_id'] = 'Choose the branch that will receive the goods.';
        }

        $expected = null;
        if (isset($data['expected_on']) && $data['expected_on'] !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['expected_on']);
            if ($date === false || $date->format('Y-m-d') !== $data['expected_on']) {
                $errors['expected_on'] = 'Enter the date as YYYY-MM-DD.';
            } else {
                $expected = $date->format('Y-m-d');
            }
        }

        $notes = isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null;
        if ($notes !== null && mb_strlen($notes) > 500) {
            $errors['notes'] = 'Use at most 500 characters.';
        }

        $lines = [];
        $rawLines = is_array($data['lines'] ?? null) ? array_values($data['lines']) : [];
        if ($rawLines === [] || count($rawLines) > 100) {
            $errors['lines'] = 'Add between 1 and 100 lines.';
        }
        foreach ($rawLines as $index => $line) {
            $variantId = is_array($line) ? (string) ($line['variant_id'] ?? '') : '';
            $variant = Uuid::isValid($variantId) ? $this->repository->variant($variantId) : null;
            if ($variant === null || $variant['status'] === 'discontinued') {
                $errors["lines.$index.variant_id"] = 'Choose an existing product variant.';
                continue;
            }
            try {
                $milli = QuantityRules::forStock($variant, $line['quantity'] ?? null);
            } catch (\InvalidArgumentException $exception) {
                $errors["lines.$index.quantity"] = $exception->getMessage();
                continue;
            }
            $cost = Decimal::parse($line['unit_cost'] ?? null, 4);
            if ($cost === null || $cost < 0) {
                $errors["lines.$index.unit_cost"] = 'Enter a cost of zero or more, with up to 4 decimals.';
                continue;
            }
            $lines[] = ['variant_id' => $variantId, 'quantity' => Decimal::format($milli, 3), 'unit_cost' => Decimal::format($cost, 4)];
        }

        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        return Database::transaction($this->pdo, function () use ($supplierId, $branchId, $expected, $notes, $lines, $actorId): array {
            $this->pdo->exec("SELECT pg_advisory_xact_lock(hashtext('gfh_po_number'))");
            $number = $this->repository->nextPoNumber((new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y'));
            $id = $this->repository->insertPo($number, $supplierId, $branchId, $expected, $notes, $actorId);
            foreach ($lines as $line) {
                $this->repository->insertPoLine($id, $line['variant_id'], $line['quantity'], $line['unit_cost']);
            }
            $this->audit->organization($this->pdo, 'po.created', $actorId, 'purchase_order', $id, ['po_number' => $number]);

            return ['id' => $id, 'po_number' => $number];
        });
    }

    /** Marks a draft as sent to the supplier. */
    public function sendPurchaseOrder(string $id, ?string $actorId): void
    {
        $po = $this->loadPo($id);
        if ($po['status'] !== 'draft') {
            throw HttpException::unprocessable(['status' => 'Only a draft purchase order can be sent.']);
        }
        $this->repository->setPoStatus($id, 'sent');
        $this->audit->organization($this->pdo, 'po.sent', $actorId, 'purchase_order', $id, ['po_number' => $po['po_number']]);
    }

    /** Cancels a purchase order that has not received anything yet. */
    public function cancelPurchaseOrder(string $id, ?string $actorId): void
    {
        Database::transaction($this->pdo, function () use ($id, $actorId): void {
            $po = $this->repository->po($id, true) ?? throw HttpException::notFound('That purchase order could not be found.');
            if (!in_array($po['status'], ['draft', 'sent'], true)) {
                throw HttpException::unprocessable(['status' => 'Only a draft or sent purchase order can be cancelled.']);
            }
            $this->repository->setPoStatus($id, 'cancelled');
            $this->audit->organization($this->pdo, 'po.cancelled', $actorId, 'purchase_order', $id, ['po_number' => $po['po_number']]);
        });
    }

    /**
     * Receives goods against a purchase order. Each entry names a line and says how much was accepted and how
     * much was rejected. Accepted stock comes in at the line's cost; rejected stock only goes on record.
     *
     * @param list<array<string, mixed>> $entries each {line_id, qty_received, qty_rejected, batch_code, expires_on, bin}
     * @return array<string, mixed> the purchase order
     * @throws HttpException 422 with field errors named lines.{i}.{field}
     */
    public function receive(string $id, array $entries, ?string $actorId): array
    {
        if ($entries === [] || count($entries) > 100) {
            throw HttpException::unprocessable(['lines' => 'Enter what was received for at least one line.']);
        }

        return Database::transaction($this->pdo, function () use ($id, $entries, $actorId): array {
            $po = $this->repository->po($id, true) ?? throw HttpException::notFound('That purchase order could not be found.');
            if (!in_array($po['status'], ['sent', 'partially_received'], true)) {
                throw HttpException::unprocessable(['status' => 'Goods can only be received against a sent purchase order.']);
            }

            $lines = [];
            foreach ($this->repository->poLines($id) as $line) {
                $lines[(string) $line['id']] = $line;
            }

            // Everything is checked first, so a bad entry rejects the whole receipt.
            $plan = [];
            $errors = [];
            $seen = [];
            foreach (array_values($entries) as $index => $entry) {
                $lineId = is_array($entry) ? (string) ($entry['line_id'] ?? '') : '';
                $line = $lines[$lineId] ?? null;
                if ($line === null || isset($seen[$lineId])) {
                    $errors["lines.$index.line_id"] = 'Choose a line of this purchase order (each once).';
                    continue;
                }
                $seen[$lineId] = true;

                $received = $this->quantityOrZero($line, $entry['qty_received'] ?? 0);
                $rejected = $this->quantityOrZero($line, $entry['qty_rejected'] ?? 0);
                if ($received === null) {
                    $errors["lines.$index.qty_received"] = 'Enter a valid quantity, or 0.';
                }
                if ($rejected === null) {
                    $errors["lines.$index.qty_rejected"] = 'Enter a valid quantity, or 0.';
                }
                if ($received === null || $rejected === null) {
                    continue;
                }
                if ($received + $rejected === 0) {
                    $errors["lines.$index.qty_received"] = 'Enter a received or rejected quantity.';
                    continue;
                }

                $ordered = Decimal::parse((string) $line['qty_ordered'], 3) ?? 0;
                $already = (Decimal::parse((string) $line['qty_received'], 3) ?? 0) + (Decimal::parse((string) $line['qty_rejected'], 3) ?? 0);
                $remaining = $ordered - $already;
                if ($received + $rejected > $remaining) {
                    $errors["lines.$index.qty_received"] = sprintf('Only %s is still to be received on this line.', rtrim(rtrim(Decimal::format($remaining, 3), '0'), '.'));
                    continue;
                }

                $plan[] = ['index' => $index, 'line' => $line, 'received' => $received, 'rejected' => $rejected, 'entry' => $entry];
            }
            if ($errors !== []) {
                throw HttpException::unprocessable($errors);
            }

            foreach ($plan as $item) {
                $line = $item['line'];
                if ($item['received'] > 0) {
                    try {
                        $this->inventory->receive(
                            (string) $po['branch_id'], (string) $line['variant_id'], Decimal::format($item['received'], 3), (string) $line['unit_cost'],
                            [
                                'code' => (string) ($item['entry']['batch_code'] ?? ''),
                                'expires_on' => $item['entry']['expires_on'] ?? null,
                                'bin' => $item['entry']['bin'] ?? null,
                            ],
                            'purchase_order', $id, $actorId, (string) $line['id'],
                        );
                    } catch (HttpException $exception) {
                        $prefixed = [];
                        foreach ($exception->fields() as $field => $message) {
                            $prefixed['lines.' . $item['index'] . '.' . $field] = $message;
                        }
                        throw $prefixed === [] ? $exception : HttpException::unprocessable($prefixed);
                    }
                }

                $newReceived = (Decimal::parse((string) $line['qty_received'], 3) ?? 0) + $item['received'];
                $newRejected = (Decimal::parse((string) $line['qty_rejected'], 3) ?? 0) + $item['rejected'];
                $this->repository->setLineReceipt((string) $line['id'], Decimal::format($newReceived, 3), Decimal::format($newRejected, 3));
            }

            $complete = true;
            foreach ($this->repository->poLines($id) as $line) {
                $done = (Decimal::parse((string) $line['qty_received'], 3) ?? 0) + (Decimal::parse((string) $line['qty_rejected'], 3) ?? 0);
                $complete = $complete && $done >= (Decimal::parse((string) $line['qty_ordered'], 3) ?? 0);
            }
            $status = $complete ? 'received' : 'partially_received';
            $this->repository->setPoStatus($id, $status);
            $this->audit->organization($this->pdo, 'po.received', $actorId, 'purchase_order', $id, ['po_number' => $po['po_number'], 'status' => $status]);

            return $this->getPurchaseOrder($id);
        });
    }

    /** @return array<string, mixed> */
    public function getPurchaseOrder(string $id): array
    {
        $po = $this->loadPo($id);
        $po['lines'] = $this->repository->poLines($id);

        return $po;
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} */
    public function listPurchaseOrders(ListQuery $query): array
    {
        [$rows, $total] = $this->repository->listPos($query);

        return $query->result($rows, $total);
    }

    /** @return array<string, mixed> */
    private function loadPo(string $id): array
    {
        return (Uuid::isValid($id) ? $this->repository->po($id) : null) ?? throw HttpException::notFound('That purchase order could not be found.');
    }

    /**
     * A quantity that may be zero. Anything above zero follows the variant's quantity rules.
     *
     * @param array<string, mixed> $line
     */
    private function quantityOrZero(array $line, mixed $raw): ?int
    {
        $milli = Decimal::parse($raw, 3);
        if ($milli === null || $milli < 0) {
            return null;
        }
        if ($milli === 0) {
            return 0;
        }

        try {
            return QuantityRules::forStock($line, Decimal::format($milli, 3));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
