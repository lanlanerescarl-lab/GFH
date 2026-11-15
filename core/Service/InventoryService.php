<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Database\Database;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\InventoryRepository;
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\QuantityRules;
use Gfh\Core\Support\Uuid;

/**
 * Stock. The ledger of movements is append-only; stock on hand is the sum of movements per branch and variant.
 * Weighted-average cost moves only when stock comes in (a receipt or the incoming side of a conversion).
 * Every method that changes stock runs in one transaction and locks the variants first, so two sales
 * cannot both take the last unit.
 *
 * receive() and convert() are public so extensions can create cost-bearing movements (for example repacking),
 * each with an explicit unit cost. A conversion is never a purchase: it writes convert_out and convert_in rows,
 * never a receipt.
 */
final class InventoryService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly InventoryRepository $repository,
        private readonly AuditService $audit,
        private readonly Hooks $hooks,
    ) {
    }

    /**
     * Brings stock in from a supplier (or any source with a known cost) and updates the average cost.
     *
     * @param array{code?: string, expires_on?: string|null, bin?: string|null}|null $batch required when the variant tracks batches
     * @return array{movement_id: string, batch_id: ?string, avg_cost: string}
     * @throws HttpException 422 with field errors
     */
    public function receive(
        string $branchId,
        string $variantId,
        mixed $quantity,
        mixed $unitCost,
        ?array $batch = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $createdBy = null,
        ?string $purchaseOrderLineId = null,
    ): array {
        return Database::transaction($this->pdo, function () use ($branchId, $variantId, $quantity, $unitCost, $batch, $referenceType, $referenceId, $createdBy, $purchaseOrderLineId): array {
            $variant = $this->lockedVariant($variantId);

            return $this->stockIn('receipt', $branchId, $variant, $quantity, $unitCost, $batch, $referenceType, $referenceId, $createdBy, null, null, $purchaseOrderLineId);
        });
    }

    /**
     * Turns stock of one variant into stock of another at the same branch (for example bulk to retail packs).
     * The outgoing side leaves at the source variant's average cost; the incoming side comes in at the explicit
     * $toUnitCost and updates the target's average cost.
     *
     * @param array{code?: string, expires_on?: string|null, bin?: string|null}|null $toBatch
     * @return array{conversion_id: string, out_cost: ?string, in_cost: string, to_avg_cost: string}
     */
    public function convert(
        string $branchId,
        string $fromVariantId,
        mixed $fromQuantity,
        string $toVariantId,
        mixed $toQuantity,
        mixed $toUnitCost,
        ?string $createdBy = null,
        ?array $toBatch = null,
        ?string $note = null,
    ): array {
        if ($fromVariantId === $toVariantId) {
            throw HttpException::unprocessable(['to_variant_id' => 'Choose a different variant to convert into.']);
        }

        return Database::transaction($this->pdo, function () use ($branchId, $fromVariantId, $fromQuantity, $toVariantId, $toQuantity, $toUnitCost, $createdBy, $toBatch, $note): array {
            $variants = $this->repository->lockVariants([$fromVariantId, $toVariantId]);
            $from = $variants[$fromVariantId] ?? throw HttpException::unprocessable(['from_variant_id' => 'That variant does not exist.']);
            $to = $variants[$toVariantId] ?? throw HttpException::unprocessable(['to_variant_id' => 'That variant does not exist.']);

            $conversionId = Uuid::v4();
            $fromMilli = $this->stockQuantity($from, $fromQuantity, 'from_quantity');

            $issued = $this->issue($from, $branchId, $fromMilli, 'convert_out', 'conversion', 'conversion', $conversionId, $createdBy, false, null, $conversionId . ':out', false, $note);
            $in = $this->stockIn('convert_in', $branchId, $to, $toQuantity, $toUnitCost, $toBatch, 'conversion', $conversionId, $createdBy, $note, Uuid::derive($conversionId, 'in'), null, 'to_quantity', 'to_unit_cost');

            $this->audit->organization($this->pdo, 'stock.converted', $createdBy, 'conversion', $conversionId, [
                'from_variant_id' => $fromVariantId,
                'to_variant_id' => $toVariantId,
                'branch_id' => $branchId,
            ]);

            return ['conversion_id' => $conversionId, 'out_cost' => $issued['unit_cost'], 'in_cost' => $in['unit_cost'], 'to_avg_cost' => $in['avg_cost']];
        });
    }

    /**
     * Takes stock out for a sale. Online orders pass $excludeReservationsOf so an order can take what it reserved.
     * A POS sale passes $allowOversell: stock that is not there is still recorded (and reported), because the sale
     * already happened. $keepOnRefusal does the same when a stock.pick_strategy hook refuses the sale.
     *
     * @param 'pos'|'online' $purpose
     * @return array{unit_cost: ?string, oversold: bool, short_by: string, refused: ?string, picks: list<array{batch_id: ?string, quantity: string}>}
     * @throws HttpException 422 when there is not enough stock and overselling is not allowed
     */
    public function sell(
        string $branchId,
        string $variantId,
        mixed $quantity,
        string $purpose,
        string $referenceType,
        string $referenceId,
        ?string $createdBy,
        bool $allowOversell = false,
        ?string $excludeReservationsOf = null,
        ?string $clientRefBase = null,
        bool $keepOnRefusal = false,
    ): array {
        return Database::transaction($this->pdo, function () use ($branchId, $variantId, $quantity, $purpose, $referenceType, $referenceId, $createdBy, $allowOversell, $excludeReservationsOf, $clientRefBase, $keepOnRefusal): array {
            $variant = $this->lockedVariant($variantId);
            $milli = $this->stockQuantity($variant, $quantity, 'quantity');

            return $this->issue(
                $variant, $branchId, $milli, $purpose === 'pos' ? 'sale_pos' : 'sale_online', $purpose,
                $referenceType, $referenceId, $createdBy, $allowOversell, $excludeReservationsOf, $clientRefBase, $keepOnRefusal, null,
            );
        });
    }

    /**
     * A correction, up or down, with a required reason.
     *
     * @return array{movement_id: string}
     */
    public function adjust(string $branchId, string $variantId, mixed $delta, string $reason, ?string $createdBy): array
    {
        return $this->manual('adjustment', $branchId, $variantId, $delta, $reason, $createdBy, true);
    }

    /** Removes damaged, expired or lost stock, with a required reason. $quantity is positive. */
    public function writeOff(string $branchId, string $variantId, mixed $quantity, string $reason, ?string $createdBy): array
    {
        return $this->manual('write_off', $branchId, $variantId, $quantity, $reason, $createdBy, false);
    }

    /**
     * Holds stock for an order until $expiresAt. Nothing is reserved if any item is short.
     *
     * @param list<array{variant_id: string, branch_id: string, quantity: mixed}> $items
     * @throws HttpException 422 listing the items that are short
     */
    public function reserve(string $orderId, array $items, \DateTimeImmutable $expiresAt): void
    {
        Database::transaction($this->pdo, function () use ($orderId, $items, $expiresAt): void {
            $variants = $this->repository->lockVariants(array_map(static fn (array $i): string => (string) $i['variant_id'], $items));

            $wanted = [];
            foreach ($items as $item) {
                $variant = $variants[(string) $item['variant_id']] ?? throw HttpException::unprocessable(['items' => 'A product in the order no longer exists.']);
                $this->assertBranch((string) $item['branch_id']);
                $milli = $this->stockQuantity($variant, $item['quantity'], 'items');
                $key = $item['branch_id'] . '|' . $item['variant_id'];
                $wanted[$key] = ['variant' => $variant, 'branch_id' => (string) $item['branch_id'], 'milli' => ($wanted[$key]['milli'] ?? 0) + $milli];
            }

            $short = [];
            foreach ($wanted as $entry) {
                $available = $this->availableMilli($entry['branch_id'], (string) $entry['variant']['id'], null);
                if ($entry['milli'] > $available) {
                    $short[] = sprintf('%s (only %s available)', $entry['variant']['sku'], self::plain(max(0, $available)));
                }
            }
            if ($short !== []) {
                throw HttpException::unprocessable(['items' => 'Not enough stock for: ' . implode(', ', $short) . '.']);
            }

            foreach ($wanted as $entry) {
                $this->repository->addReservation($orderId, (string) $entry['variant']['id'], $entry['branch_id'], Decimal::format($entry['milli'], 3), $expiresAt);
            }
        });
    }

    /** Frees an order's reservations (cancelled orders). Returns how many were released. */
    public function releaseOrder(string $orderId): int
    {
        return $this->repository->moveReservations($orderId, 'released');
    }

    /** Marks an order's reservations as used up once its stock has been sold. */
    public function consumeOrder(string $orderId): int
    {
        return $this->repository->moveReservations($orderId, 'consumed');
    }

    /** Releases every reservation whose time has run out. Safe to run any time; see InventoryRepository::releaseExpired. */
    public function releaseExpired(): int
    {
        return $this->repository->releaseExpired();
    }

    /** What can still be sold at a branch: on hand minus active reservations, as a decimal string. */
    public function available(string $branchId, string $variantId): string
    {
        return Decimal::format($this->availableMilli($branchId, $variantId, null), 3);
    }

    public function onHand(string $branchId, string $variantId): string
    {
        return Decimal::format(Decimal::parse($this->repository->onHandAt($branchId, $variantId), 3) ?? 0, 3);
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} */
    public function levels(ListQuery $query): array
    {
        [$rows, $total] = $this->repository->levels($query);

        return $query->result($rows, $total);
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} */
    public function ledger(ListQuery $query): array
    {
        [$rows, $total] = $this->repository->ledger($query);

        return $query->result($rows, $total);
    }

    /** @return list<array<string, mixed>> */
    public function branches(bool $activeOnly = false): array
    {
        return $this->repository->branches($activeOnly);
    }

    /** @return list<array<string, mixed>> */
    public function variantsForSelect(): array
    {
        return $this->repository->variantsForSelect();
    }

    /** @return list<array<string, mixed>> */
    public function reorderSuppliers(): array
    {
        return $this->repository->reorderSuppliers();
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} */
    public function reorderSuggestions(ListQuery $query): array
    {
        [$rows, $total] = $this->repository->reorderSuggestions($query);

        return $query->result($rows, $total);
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string, mixed> */
    private function lockedVariant(string $variantId): array
    {
        return $this->repository->lockVariants([$variantId])[$variantId]
            ?? throw HttpException::unprocessable(['variant_id' => 'That variant does not exist.']);
    }

    /** @param array<string, mixed> $variant */
    private function stockQuantity(array $variant, mixed $raw, string $field): int
    {
        try {
            return QuantityRules::forStock($variant, $raw);
        } catch (\InvalidArgumentException $exception) {
            throw HttpException::unprocessable([$field => $exception->getMessage()]);
        }
    }

    private function assertBranch(string $branchId): void
    {
        if (!Uuid::isValid($branchId) || ($this->repository->branch($branchId)['status'] ?? null) !== 'active') {
            throw HttpException::unprocessable(['branch_id' => 'Choose an active branch.']);
        }
    }

    private function availableMilli(string $branchId, string $variantId, ?string $excludeOrder): int
    {
        $onHand = Decimal::parse($this->repository->onHandAt($branchId, $variantId), 3) ?? 0;
        $reserved = Decimal::parse($this->repository->reservedAt($branchId, $variantId, $excludeOrder), 3) ?? 0;

        return $onHand - $reserved;
    }

    private static function plain(int $milli): string
    {
        return rtrim(rtrim(Decimal::format($milli, 3), '0'), '.');
    }

    /**
     * Stock coming in, at an explicit cost. Updates the variant's weighted-average cost:
     *   new average = (on hand x old average + received x unit cost) / (on hand + received)
     * and when nothing is on hand (or no cost was ever recorded) the average simply becomes the new cost.
     *
     * @param array<string, mixed> $variant a locked variant row
     * @param array{code?: string, expires_on?: string|null, bin?: string|null}|null $batch
     * @return array{movement_id: string, batch_id: ?string, avg_cost: string, unit_cost: string}
     */
    private function stockIn(
        string $type,
        string $branchId,
        array $variant,
        mixed $quantity,
        mixed $unitCost,
        ?array $batch,
        ?string $referenceType,
        ?string $referenceId,
        ?string $createdBy,
        ?string $reason,
        ?string $clientRef,
        ?string $purchaseOrderLineId = null,
        string $quantityField = 'quantity',
        string $costField = 'unit_cost',
    ): array {
        $this->assertBranch($branchId);
        $milli = $this->stockQuantity($variant, $quantity, $quantityField);

        $cost = Decimal::parse($unitCost, 4);
        if ($cost === null || $cost < 0) {
            throw HttpException::unprocessable([$costField => 'Enter a cost of zero or more, with up to 4 decimals.']);
        }

        $batchId = $this->resolveBatch($variant, $branchId, $batch, Decimal::format($milli, 3), Decimal::format($cost, 4), $purchaseOrderLineId);

        try {
            $onHand = Decimal::parse($this->repository->onHandTotal((string) $variant['id']), 3) ?? 0;
            $oldAverage = Decimal::parse((string) $variant['avg_cost'], 4) ?? 0;

            if ($onHand <= 0 || $variant['cost_updated_at'] === null) {
                $newAverage = $cost;
            } else {
                $numerator = Decimal::mul($onHand, $oldAverage) + Decimal::mul($milli, $cost);
                $divisor = $onHand + $milli;
                $newAverage = intdiv(2 * $numerator + $divisor, 2 * $divisor);
            }
        } catch (\RangeException $exception) {
            throw HttpException::unprocessable([$quantityField => $exception->getMessage()]);
        }

        $movementId = $this->repository->insertMovement([
            'branch_id' => $branchId,
            'variant_id' => $variant['id'],
            'batch_id' => $batchId,
            'movement_type' => $type,
            'quantity_delta' => Decimal::format($milli, 3),
            'unit_cost' => Decimal::format($cost, 4),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reason' => $reason,
            'created_by' => $createdBy,
            'client_ref' => $clientRef,
        ]);
        $this->repository->setAverageCost((string) $variant['id'], Decimal::format($newAverage, 4));

        return ['movement_id' => $movementId, 'batch_id' => $batchId, 'avg_cost' => Decimal::format($newAverage, 4), 'unit_cost' => Decimal::format($cost, 4)];
    }

    /**
     * Finds or creates the batch for a receipt. A variant that tracks batches needs a batch code.
     *
     * @param array<string, mixed> $variant
     * @param array{code?: string, expires_on?: string|null, bin?: string|null}|null $batch
     */
    private function resolveBatch(array $variant, string $branchId, ?array $batch, string $qty, string $cost, ?string $poLineId): ?string
    {
        if (!(bool) $variant['track_batches']) {
            return null;
        }

        $code = trim((string) ($batch['code'] ?? ''));
        if ($code === '' || mb_strlen($code) > 60) {
            throw HttpException::unprocessable(['batch_code' => 'Enter the batch code (up to 60 characters). This product tracks batches.']);
        }

        $expires = $batch['expires_on'] ?? null;
        if ($expires !== null && $expires !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $expires);
            if ($date === false || $date->format('Y-m-d') !== $expires) {
                throw HttpException::unprocessable(['expires_on' => 'Enter the expiry date as YYYY-MM-DD.']);
            }
        } else {
            $expires = null;
        }

        $bin = isset($batch['bin']) && trim((string) $batch['bin']) !== '' ? mb_substr(trim((string) $batch['bin']), 0, 40) : null;

        $existing = $this->repository->findBatch((string) $variant['id'], $branchId, $code);
        if ($existing !== null) {
            $this->repository->addToBatch((string) $existing['id'], $qty);

            return (string) $existing['id'];
        }

        return $this->repository->insertBatch((string) $variant['id'], $branchId, $code, $bin, $expires, $qty, $cost, $poLineId);
    }

    /**
     * Takes stock out through the pick strategy.
     *
     * @param array<string, mixed> $variant
     * @return array{unit_cost: ?string, oversold: bool, short_by: string, refused: ?string, picks: list<array{batch_id: ?string, quantity: string}>}
     */
    private function issue(
        array $variant,
        string $branchId,
        int $milli,
        string $movementType,
        string $purpose,
        string $referenceType,
        string $referenceId,
        ?string $createdBy,
        bool $allowOversell,
        ?string $excludeReservationsOf,
        ?string $clientRefBase,
        bool $keepOnRefusal,
        ?string $reason,
    ): array {
        $this->assertBranch($branchId);

        $available = $this->availableMilli($branchId, (string) $variant['id'], $excludeReservationsOf);
        $shortBy = max(0, $milli - max(0, $available));
        if ($shortBy > 0 && !$allowOversell) {
            throw HttpException::unprocessable(['quantity' => sprintf('Only %s available for %s.', self::plain(max(0, $available)), $variant['sku'])]);
        }

        [$picks, $refused] = $this->pick($variant, $branchId, $milli, $purpose, $keepOnRefusal);

        $unitCost = $variant['cost_updated_at'] === null ? null : Decimal::format(Decimal::parse((string) $variant['avg_cost'], 4) ?? 0, 4);

        $result = [];
        foreach ($picks as $index => $pick) {
            $this->repository->insertMovement([
                'branch_id' => $branchId,
                'variant_id' => $variant['id'],
                'batch_id' => $pick['batch_id'],
                'movement_type' => $movementType,
                'quantity_delta' => Decimal::format(-$pick['quantity_milli'], 3),
                'unit_cost' => $unitCost,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reason' => $reason,
                'created_by' => $createdBy,
                'client_ref' => $clientRefBase === null ? null : Uuid::derive($clientRefBase, 'pick' . $index),
            ]);
            $result[] = ['batch_id' => $pick['batch_id'], 'quantity' => Decimal::format($pick['quantity_milli'], 3)];
        }

        return [
            'unit_cost' => $unitCost,
            'oversold' => $shortBy > 0,
            'short_by' => Decimal::format($shortBy, 3),
            'refused' => $refused,
            'picks' => $result,
        ];
    }

    /**
     * Chooses which batches a sale takes from. The default is oldest received first (or no batch at all for
     * variants that do not track batches); the stock.pick_strategy hook can replace it, split the sale across
     * batches, or refuse by throwing an HttpException.
     *
     * @param array<string, mixed> $variant
     * @return array{0: list<array{batch_id: ?string, quantity_milli: int}>, 1: ?string} the picks and, if a refusal was overridden, its message
     */
    private function pick(array $variant, string $branchId, int $milli, string $purpose, bool $keepOnRefusal): array
    {
        $batches = [];
        if ((bool) $variant['track_batches']) {
            foreach ($this->repository->availableBatches($branchId, (string) $variant['id']) as $row) {
                $batches[] = [
                    'batch_id' => (string) $row['id'],
                    'batch_code' => (string) $row['batch_code'],
                    'bin' => $row['bin'],
                    'expires_on' => $row['expires_on'],
                    'received_on' => $row['received_on'],
                    'quantity_milli' => Decimal::parse((string) $row['on_hand'], 3) ?? 0,
                ];
            }
        }

        $default = [];
        $remaining = $milli;
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, $batch['quantity_milli']);
            $default[] = ['batch_id' => $batch['batch_id'], 'quantity_milli' => $take];
            $remaining -= $take;
        }
        if ($remaining > 0) {
            $default[] = ['batch_id' => null, 'quantity_milli' => $remaining];
        }

        $refused = null;
        try {
            $picks = $this->hooks->filter(Hooks::STOCK_PICK_STRATEGY, $default, [
                'variant' => $variant,
                'branch_id' => $branchId,
                'quantity_milli' => $milli,
                'batches' => $batches,
                'purpose' => $purpose,
            ]);
        } catch (HttpException $exception) {
            if (!$keepOnRefusal) {
                throw $exception;
            }
            $refused = $exception->getMessage();
            $picks = $default;
        }

        $this->assertValidPicks($picks, $batches, $milli);

        return [$picks, $refused];
    }

    /**
     * @param mixed                      $picks
     * @param list<array<string, mixed>> $batches
     */
    private function assertValidPicks(mixed $picks, array $batches, int $milli): void
    {
        $available = [];
        foreach ($batches as $batch) {
            $available[$batch['batch_id']] = $batch['quantity_milli'];
        }

        $sum = 0;
        $used = [];
        if (!is_array($picks) || $picks === []) {
            throw new \LogicException('The stock.pick_strategy hook must return at least one pick.');
        }

        foreach ($picks as $pick) {
            $quantity = is_array($pick) ? ($pick['quantity_milli'] ?? null) : null;
            $batchId = is_array($pick) ? ($pick['batch_id'] ?? null) : null;
            if (!is_int($quantity) || $quantity <= 0 || ($batchId !== null && !isset($available[$batchId]))) {
                throw new \LogicException('The stock.pick_strategy hook returned an invalid pick.');
            }
            if ($batchId !== null) {
                $used[$batchId] = ($used[$batchId] ?? 0) + $quantity;
                if ($used[$batchId] > $available[$batchId]) {
                    throw new \LogicException('The stock.pick_strategy hook took more from a batch than it holds.');
                }
            }
            $sum += $quantity;
        }

        if ($sum !== $milli) {
            throw new \LogicException('The stock.pick_strategy hook picks must add up to the quantity sold.');
        }
    }

    /** @return array{movement_id: string} */
    private function manual(string $type, string $branchId, string $variantId, mixed $amount, string $reason, ?string $createdBy, bool $signed): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw HttpException::unprocessable(['reason' => 'Give a reason (3 to 255 characters).']);
        }

        return Database::transaction($this->pdo, function () use ($type, $branchId, $variantId, $amount, $reason, $createdBy, $signed): array {
            $variant = $this->lockedVariant($variantId);
            $this->assertBranch($branchId);

            $field = $signed ? 'delta' : 'quantity';
            $parsed = Decimal::parse($amount, 3);
            if ($parsed === null || $parsed === 0 || (!$signed && $parsed < 0)) {
                throw HttpException::unprocessable([$field => $signed ? 'Enter a quantity to add (+) or remove (-).' : 'Enter a quantity greater than zero.']);
            }

            $milli = $this->stockQuantity($variant, Decimal::format(abs($parsed), 3), $field);
            $delta = ($signed && $parsed > 0) ? $milli : -$milli;

            if ($delta < 0) {
                $onHand = Decimal::parse($this->repository->onHandAt($branchId, $variantId), 3) ?? 0;
                if ($onHand < -$delta) {
                    throw HttpException::unprocessable([$field => sprintf('Only %s on hand at this branch.', self::plain(max(0, $onHand)))]);
                }
            }

            $unitCost = $variant['cost_updated_at'] === null ? null : Decimal::format(Decimal::parse((string) $variant['avg_cost'], 4) ?? 0, 4);
            $movementId = $this->repository->insertMovement([
                'branch_id' => $branchId,
                'variant_id' => $variantId,
                'movement_type' => $type,
                'quantity_delta' => Decimal::format($delta, 3),
                'unit_cost' => $unitCost,
                'reference_type' => 'manual',
                'reason' => $reason,
                'created_by' => $createdBy,
            ]);

            $this->audit->organization($this->pdo, $type === 'write_off' ? 'stock.written_off' : 'stock.adjusted', $createdBy, 'variant', $variantId, [
                'branch_id' => $branchId,
                'quantity' => Decimal::format($delta, 3),
                'reason' => $reason,
            ]);

            return ['movement_id' => $movementId];
        });
    }
}
