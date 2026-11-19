<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Database\Database;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\BranchRepository;
use Gfh\Core\Support\SettingsSections;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Organization;

/**
 * Branches (stores and warehouses) for the Admin Settings screens.
 *
 * The rules, enforced here and nowhere else:
 *  - Only an actor who may edit Reports & Admin (an Admin) may use any method, whatever the page did before calling.
 *  - The code is chosen on create and never changes. Status changes only through deactivate() and reactivate().
 *  - At most one branch is the delivery branch (the database enforces it too). Making a branch the delivery branch
 *    moves the designation from the old one in the same transaction; clearing it is refused, the Admin picks another
 *    branch instead. Only an active branch can become the delivery branch or a pickup point.
 *  - A branch is deactivated only when nothing uses it: it is not the delivery branch, it has no active terminal, it
 *    holds no stock on hand and no open order points at it. Reactivating is always allowed.
 *  - Nothing is deleted, and every change writes an organization audit entry (no secrets are ever put in one).
 */
final class BranchService
{
    public const KINDS = ['store', 'warehouse'];

    private const CODE_PATTERN = '/^[A-Z0-9-]{2,12}$/D';
    private const NAME_LIMIT = 120;
    private const TEXT_LIMIT = 200;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly BranchRepository $repository,
        private readonly AuditService $audit,
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * @return list<array<string, mixed>> every branch with its number of active terminals
     * @throws HttpException 403 unless the actor may edit Reports & Admin
     */
    public function list(StaffUser $actor, Organization $organization): array
    {
        $this->assertMayManage($actor, $organization);

        return $this->repository->list();
    }

    /**
     * @return array<string, mixed>
     * @throws HttpException 403 unless permitted, 404 for an unknown or malformed id
     */
    public function get(StaffUser $actor, Organization $organization, string $id): array
    {
        $this->assertMayManage($actor, $organization);

        return $this->load($id);
    }

    /**
     * @param array<string, mixed> $input code, name, address, city, province, region, kind, is_pickup_point, is_delivery_origin
     * @return array{id: string}
     * @throws HttpException 403 unless permitted, 422 with field errors
     */
    public function create(StaffUser $actor, Organization $organization, array $input): array
    {
        $this->assertMayManage($actor, $organization);

        return Database::transaction($this->pdo, function () use ($actor, $input): array {
            $this->repository->lockBranchWrites();

            $errors = [];
            $code = is_string($input['code'] ?? null) ? trim($input['code']) : '';
            if (preg_match(self::CODE_PATTERN, $code) !== 1) {
                $errors['code'] = 'Enter a code of 2 to 12 characters: capital letters, digits or hyphens (for example BCD-MAIN).';
            } elseif ($this->repository->idByCode($code) !== null) {
                $errors['code'] = 'This code is already used by another branch.';
            }

            $fields = $this->profile($input, $errors);
            $fields['is_pickup_point'] = $this->flag($input['is_pickup_point'] ?? null);
            $fields['is_delivery_origin'] = $this->flag($input['is_delivery_origin'] ?? null);
            if ($errors !== []) {
                throw HttpException::unprocessable($errors);
            }

            // A new branch is always active, so it may be a pickup point or the delivery branch straight away.
            $previousDelivery = $fields['is_delivery_origin'] ? $this->repository->clearDeliveryOrigin() : null;
            $id = $this->repository->insert(['code' => $code] + $fields);

            $this->audit->organization($this->pdo, 'branch.created', $actor->id, 'branch', $id, [
                'code' => $code,
                'name' => $fields['name'],
                'kind' => $fields['kind'],
                'is_pickup_point' => $fields['is_pickup_point'],
                'is_delivery_origin' => $fields['is_delivery_origin'],
            ]);
            if ($fields['is_delivery_origin']) {
                $this->auditDeliveryMove($actor, $id, $previousDelivery);
            }

            return ['id' => $id];
        });
    }

    /**
     * Saves the edit form. A posted "code" is not a field: if one arrives and differs from the stored code the whole
     * save is refused, and a posted status or id is never read at all.
     *
     * @param array<string, mixed> $input name, address, city, province, region, kind, is_pickup_point, is_delivery_origin (and code, only if tampered)
     * @throws HttpException 403 unless permitted, 404 unknown branch, 422 with field errors
     */
    public function update(StaffUser $actor, Organization $organization, string $id, array $input): void
    {
        $this->assertMayManage($actor, $organization);
        $this->assertId($id);

        Database::transaction($this->pdo, function () use ($actor, $id, $input): void {
            $this->repository->lockBranchWrites();
            $branch = $this->repository->find($id, 'update') ?? throw HttpException::notFound('That branch could not be found.');

            $errors = [];
            if (array_key_exists('code', $input) && $input['code'] !== $branch['code']) {
                $errors['code'] = 'The code cannot be changed after the branch is created.';
            }

            $fields = $this->profile($input, $errors);
            $wantsPickup = $this->flag($input['is_pickup_point'] ?? null);
            $wantsDelivery = $this->flag($input['is_delivery_origin'] ?? null);
            $isActive = $branch['status'] === 'active';
            $wasPickup = (bool) $branch['is_pickup_point'];
            $wasDelivery = (bool) $branch['is_delivery_origin'];

            if ($wantsPickup && !$wasPickup && !$isActive) {
                $errors['is_pickup_point'] = 'Reactivate this branch before making it a pickup point.';
            }
            if ($wantsDelivery && !$wasDelivery && !$isActive) {
                $errors['is_delivery_origin'] = 'Reactivate this branch before making it the delivery branch.';
            }
            if (!$wantsDelivery && $wasDelivery) {
                $errors['is_delivery_origin'] = 'This is the delivery branch. To change it, tick Delivery branch on the branch that should take over instead.';
            }
            if ($errors !== []) {
                throw HttpException::unprocessable($errors);
            }

            $fields['is_pickup_point'] = $wantsPickup;
            $fields['is_delivery_origin'] = $wantsDelivery;
            $changed = $this->changedFields($branch, $fields);
            if ($changed === []) {
                return; // nothing to save, so nothing to audit
            }

            $previousDelivery = $wantsDelivery && !$wasDelivery ? $this->repository->clearDeliveryOrigin() : null;
            $this->repository->update($id, $fields);

            $this->audit->organization($this->pdo, 'branch.updated', $actor->id, 'branch', $id, ['code' => (string) $branch['code'], 'changed' => $changed]);
            if ($wantsDelivery && !$wasDelivery) {
                $this->auditDeliveryMove($actor, $id, $previousDelivery);
            }
        });
    }

    /**
     * @throws HttpException 403 unless permitted, 404 unknown branch, 422 (field "action") when the branch is in use
     */
    public function deactivate(StaffUser $actor, Organization $organization, string $id): void
    {
        $this->assertMayManage($actor, $organization);
        $this->assertId($id);

        Database::transaction($this->pdo, function () use ($actor, $id): void {
            $this->repository->lockBranchWrites();
            $branch = $this->repository->find($id, 'update') ?? throw HttpException::notFound('That branch could not be found.');

            if ($branch['status'] !== 'active') {
                throw $this->refused('This branch is already inactive.');
            }

            $reasons = $this->deactivationBlockers($branch);
            if ($reasons !== []) {
                throw $this->refused('This branch cannot be deactivated yet. ' . implode(' ', $reasons));
            }

            $this->repository->setStatus($id, 'inactive');
            $this->audit->organization($this->pdo, 'branch.deactivated', $actor->id, 'branch', $id, ['code' => (string) $branch['code']]);
        });
    }

    /**
     * Reactivating is always allowed: it only makes a branch usable again and takes nothing away from anyone.
     *
     * @throws HttpException 403 unless permitted, 404 unknown branch, 422 (field "action") when it is already active
     */
    public function reactivate(StaffUser $actor, Organization $organization, string $id): void
    {
        $this->assertMayManage($actor, $organization);
        $this->assertId($id);

        Database::transaction($this->pdo, function () use ($actor, $id): void {
            $this->repository->lockBranchWrites();
            $branch = $this->repository->find($id, 'update') ?? throw HttpException::notFound('That branch could not be found.');

            if ($branch['status'] === 'active') {
                throw $this->refused('This branch is already active.');
            }

            $this->repository->setStatus($id, 'active');
            $this->audit->organization($this->pdo, 'branch.reactivated', $actor->id, 'branch', $id, ['code' => (string) $branch['code']]);
        });
    }

    /**
     * Why this branch cannot be deactivated, one sentence per reason, empty when nothing uses it. The delivery flag
     * comes from the row already locked for this change; each other reason is a single count query in the repository.
     *
     * @param array<string, mixed> $branch
     * @return list<string>
     */
    private function deactivationBlockers(array $branch): array
    {
        $id = (string) $branch['id'];
        $reasons = [];

        if ((bool) $branch['is_delivery_origin']) {
            $reasons[] = 'It is the delivery branch. Make another branch the delivery branch first.';
        }

        $terminals = $this->repository->countActiveTerminals($id);
        if ($terminals > 0) {
            $reasons[] = sprintf('It still has %d active terminal%s. Deactivate %s first.', $terminals, $terminals === 1 ? '' : 's', $terminals === 1 ? 'it' : 'them');
        }

        $stocked = $this->repository->countVariantsWithStockOnHand($id);
        if ($stocked > 0) {
            $reasons[] = sprintf('It still holds stock on hand (%d product%s). Move it out or write it off first.', $stocked, $stocked === 1 ? '' : 's');
        }

        $orders = $this->repository->countOpenOrders($id);
        if ($orders > 0) {
            $reasons[] = sprintf('%d open order%s still %s it for pickup or shipping. Finish or cancel %s first.', $orders, $orders === 1 ? '' : 's', $orders === 1 ? 'uses' : 'use', $orders === 1 ? 'it' : 'them');
        }

        return $reasons;
    }

    /** @return array<string, mixed> */
    private function load(string $id): array
    {
        $this->assertId($id);

        return $this->repository->find($id) ?? throw HttpException::notFound('That branch could not be found.');
    }

    private function assertId(string $id): void
    {
        if (!Uuid::isValid($id)) {
            throw HttpException::notFound('That branch could not be found.');
        }
    }

    /** The same permission the Settings pages ask for, checked again here so a caller that skips the page is still refused. */
    private function assertMayManage(StaffUser $actor, Organization $organization): void
    {
        $this->permissions->assert($actor, $organization, SettingsSections::MODULE, SettingsSections::ACTION);
    }

    private function refused(string $message): HttpException
    {
        return HttpException::unprocessable(['action' => $message], $message);
    }

    /** Only the box ticked with value 1 counts as ticked; absent or anything else is unticked. */
    private function flag(mixed $value): bool
    {
        return $value === '1';
    }

    /**
     * Cleans and checks the fields the create and edit forms share. Errors are added to $errors by field name.
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     * @return array{name: string, address: ?string, city: ?string, province: ?string, region: ?string, kind: string}
     */
    private function profile(array $input, array &$errors): array
    {
        $name = $this->text($input['name'] ?? null);
        if ($name === '' || mb_strlen($name) > self::NAME_LIMIT || $this->hasControlCharacters($name)) {
            $errors['name'] = 'Enter the branch name (up to 120 characters).';
        }

        $optional = [];
        foreach (['address', 'city', 'province', 'region'] as $field) {
            $value = $this->text($input[$field] ?? null);
            if (mb_strlen($value) > self::TEXT_LIMIT || $this->hasControlCharacters($value)) {
                $errors[$field] = 'Use at most 200 characters, with no control characters.';
            }
            $optional[$field] = $value === '' ? null : $value;
        }

        $kind = is_string($input['kind'] ?? null) ? trim($input['kind']) : '';
        if (!in_array($kind, self::KINDS, true)) {
            $errors['kind'] = 'Choose store or warehouse.';
        }

        return ['name' => $name, 'address' => $optional['address'], 'city' => $optional['city'], 'province' => $optional['province'], 'region' => $optional['region'], 'kind' => $kind];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
    }

    private function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    /**
     * Names of the fields whose value differs from the stored row.
     *
     * @param array<string, mixed> $branch
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    private function changedFields(array $branch, array $fields): array
    {
        $changed = [];
        foreach (['name', 'address', 'city', 'province', 'region', 'kind'] as $field) {
            if (($branch[$field] === null ? null : (string) $branch[$field]) !== $fields[$field]) {
                $changed[] = $field;
            }
        }
        foreach (['is_pickup_point', 'is_delivery_origin'] as $field) {
            if ((bool) $branch[$field] !== $fields[$field]) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function auditDeliveryMove(StaffUser $actor, string $toBranchId, ?string $fromBranchId): void
    {
        $this->audit->organization($this->pdo, 'branch.delivery_origin_changed', $actor->id, 'branch', $toBranchId, [
            'from_branch_id' => $fromBranchId,
            'to_branch_id' => $toBranchId,
        ]);
    }
}
