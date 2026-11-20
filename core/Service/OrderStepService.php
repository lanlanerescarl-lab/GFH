<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Database\Database;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\OrderStepRepository;
use Gfh\Core\Support\SettingsSections;
use Gfh\Core\Tenancy\Organization;

/**
 * Order progress steps for the Admin Settings screens.
 *
 * The rules, enforced here and nowhere else:
 *  - Only an actor who may edit Reports & Admin (an Admin) may use any method, whatever the page did before calling.
 *  - The key is chosen on create and is read-only afterwards (orders and their history point at it). The label can
 *    always be edited, even while orders are moving.
 *  - applies_when is one of the conditions OrderService::stepApplies() recognises (an unknown one would never match,
 *    so its step could never be reached), and maps_to is open, in_progress or complete (the groups the contract views
 *    and the Super Admin app read).
 *  - A structural change (add a step, delete a step, change the sort order, maps_to, is_terminal or applies_when) is
 *    refused while any order is open, meaning not cancelled and not on a terminal step: an open order's path would
 *    change under it.
 *  - A step is never deleted while an order or an order-history row points at it, even when no order is open.
 *  - After every structural change, inside the same transaction, the steps must still make a working order flow, or
 *    the whole change is rolled back (see assertFlowStillWorks()).
 *  - Every change writes an organization audit entry with the actor and no secret.
 *
 * Not covered: an order placed in the same instant as a structural change. The change locks the step rows and
 * serialises with other step changes, but order placement does not lock them (that code is outside this screen).
 */
final class OrderStepService
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/D';
    public const LABEL_LIMIT = 80;
    public const SORT_MAX = 9999;
    public const MAPS_TO = ['open', 'in_progress', 'complete'];
    /** The Settings page routes /order-steps/new to the add form, so a step cannot take that key. */
    private const RESERVED_KEYS = ['new'];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly OrderStepRepository $repository,
        private readonly AuditService $audit,
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * @return list<array<string, mixed>> every step in path order with the number of orders on it
     * @throws HttpException 403 unless the actor may edit Reports & Admin
     */
    public function list(StaffUser $actor, Organization $organization): array
    {
        $this->assertMayManage($actor, $organization);

        return $this->repository->list();
    }

    /**
     * @return array<string, mixed> the step with order_count (not cancelled), orders_total and history_total
     * @throws HttpException 403 unless permitted, 404 for an unknown or malformed key
     */
    public function get(StaffUser $actor, Organization $organization, string $key): array
    {
        $this->assertMayManage($actor, $organization);

        return $this->find($key);
    }

    /** @throws HttpException 403 unless permitted */
    public function openOrderCount(StaffUser $actor, Organization $organization): int
    {
        $this->assertMayManage($actor, $organization);

        return $this->repository->countOpenOrders();
    }

    /**
     * @param array<string, mixed> $input key, label, sort_order, maps_to, is_terminal, applies_when
     * @return array{key: string}
     * @throws HttpException 403 unless permitted, 422 with field errors, or (field "action") while an order is open or when
     *                       the change would leave no working order flow
     */
    public function create(StaffUser $actor, Organization $organization, array $input): array
    {
        $this->assertMayManage($actor, $organization);

        return Database::transaction($this->pdo, function () use ($actor, $input): array {
            $this->repository->lockChanges();
            $steps = $this->repository->lockAll();

            $errors = [];
            $key = is_string($input['key'] ?? null) ? trim($input['key']) : '';
            if (preg_match(self::KEY_PATTERN, $key) !== 1 || in_array($key, self::RESERVED_KEYS, true)) {
                $errors['key'] = 'Enter a key of up to 40 characters: lower-case letters, digits and underscores, starting with a letter (for example out_for_delivery). "new" is reserved.';
            } elseif ($this->byKey($steps, $key) !== null) {
                $errors['key'] = 'This key is already used by another step.';
            }
            $fields = $this->fields($input, $steps, null, $errors);
            if ($errors !== []) {
                throw HttpException::unprocessable($errors);
            }

            $this->assertNoOpenOrders('add a step');

            $this->repository->insert($key, $fields['label'], $fields['sort_order'], $fields['is_terminal'], $fields['maps_to'], $fields['applies_when']);
            $this->assertFlowStillWorks($this->repository->lockAll());

            $this->audit->organization($this->pdo, 'order_step.created', $actor->id, 'order_step', $key, [
                'key' => $key,
                'label' => $fields['label'],
                'sort_order' => $fields['sort_order'],
                'maps_to' => $fields['maps_to'],
                'is_terminal' => $fields['is_terminal'],
                'applies_when' => $fields['applies_when'],
            ]);

            return ['key' => $key];
        });
    }

    /**
     * Saves the edit form. The label always saves. Any other change is structural and is refused while an order is
     * open. A posted key that differs from the stored one is a tampered request and refuses the whole save.
     *
     * @param array<string, mixed> $input label, sort_order, maps_to, is_terminal, applies_when (and key, only if tampered).
     *                                    A field that is left out keeps its stored value.
     * @throws HttpException 403 unless permitted, 404 unknown step, 422 with field errors or (field "action")
     */
    public function update(StaffUser $actor, Organization $organization, string $key, array $input): void
    {
        $this->assertMayManage($actor, $organization);
        $this->assertKey($key);

        Database::transaction($this->pdo, function () use ($actor, $key, $input): void {
            $this->repository->lockChanges();
            $steps = $this->repository->lockAll();
            $step = $this->byKey($steps, $key) ?? throw HttpException::notFound('That step could not be found.');

            $errors = [];
            if (array_key_exists('key', $input) && $input['key'] !== $step['key']) {
                $errors['key'] = 'The key cannot be changed after the step is created. Orders point at it.';
            }
            $fields = $this->fields($input + [
                'sort_order' => (string) $step['sort_order'],
                'maps_to' => (string) $step['maps_to'],
                'is_terminal' => (bool) $step['is_terminal'],
                'applies_when' => (string) $step['applies_when'],
            ], $steps, $key, $errors);
            if ($errors !== []) {
                throw HttpException::unprocessable($errors);
            }

            $structural = [];
            $detail = ['key' => $key];
            foreach (['sort_order', 'maps_to', 'is_terminal', 'applies_when'] as $field) {
                $before = $field === 'sort_order' ? (int) $step[$field] : ($field === 'is_terminal' ? (bool) $step[$field] : (string) $step[$field]);
                if ($fields[$field] !== $before) {
                    $structural[] = $field;
                    $detail[$field] = ['from' => $before, 'to' => $fields[$field]];
                }
            }
            $labelChanged = $fields['label'] !== (string) $step['label'];
            if ($structural === [] && !$labelChanged) {
                return; // nothing to save, so nothing to audit
            }
            if ($labelChanged) {
                $detail['label'] = ['from' => (string) $step['label'], 'to' => $fields['label']];
            }
            if ($structural !== []) {
                $this->assertNoOpenOrders('change the ' . str_replace('_', ' ', implode(', ', $structural)) . ' of a step');
            }

            $this->repository->update($key, $fields['label'], $fields['sort_order'], $fields['is_terminal'], $fields['maps_to'], $fields['applies_when']);
            if ($structural !== []) {
                $this->assertFlowStillWorks($this->repository->lockAll());
            }

            $detail['changed'] = array_merge($labelChanged ? ['label'] : [], $structural);
            $this->audit->organization($this->pdo, 'order_step.updated', $actor->id, 'order_step', $key, $detail);
        });
    }

    /**
     * @throws HttpException 403 unless permitted, 404 unknown step, 422 (field "action") while an order is open, when
     *                       an order or history row points at the step, or when the change would leave no working flow
     */
    public function delete(StaffUser $actor, Organization $organization, string $key): void
    {
        $this->assertMayManage($actor, $organization);
        $this->assertKey($key);

        Database::transaction($this->pdo, function () use ($actor, $key): void {
            $this->repository->lockChanges();
            $steps = $this->repository->lockAll();
            $step = $this->byKey($steps, $key) ?? throw HttpException::notFound('That step could not be found.');

            $this->assertNoOpenOrders('delete a step');

            $found = $this->repository->find($key) ?? throw HttpException::notFound('That step could not be found.');
            $orders = (int) $found['orders_total'];
            $history = (int) $found['history_total'];
            if ($orders > 0 || $history > 0) {
                throw $this->refused(sprintf(
                    'This step cannot be deleted because %d order(s) and %d history entr%s refer to it. Rename it instead.',
                    $orders,
                    $history,
                    $history === 1 ? 'y' : 'ies',
                ));
            }

            $this->repository->delete($key);
            $this->assertFlowStillWorks($this->repository->lockAll());

            $this->audit->organization($this->pdo, 'order_step.deleted', $actor->id, 'order_step', $key, [
                'key' => $key,
                'label' => (string) $step['label'],
            ]);
        });
    }

    /**
     * The steps must still make a working order flow. If not, the exception rolls the whole change back.
     *
     *  1. At least one step maps to complete and is terminal. Stock is sold when an order reaches a complete step, and
     *     an order ends on a terminal one, so without both nothing could ever finish.
     *  2. At least one step applies always, so every order has a path (an order with no path is refused at checkout).
     *  3. Every terminal step maps to complete. Stock is sold only on passing a complete step, so a terminal step that
     *     is not complete would finish orders without ever selling their stock.
     *  4. No order can start on a terminal step. A new order starts on the first step of its path, so for every kind of
     *     order (pickup or delivery, made to order or not, with or without assembly and installation) that first step
     *     must not be terminal, or orders would be born finished.
     *
     * @param list<array<string, mixed>> $steps every step after the change, in path order
     * @throws HttpException 422 (field "action")
     */
    private function assertFlowStillWorks(array $steps): void
    {
        $problems = [];

        $finishers = array_filter($steps, static fn (array $s): bool => (bool) $s['is_terminal'] && $s['maps_to'] === 'complete');
        if ($finishers === []) {
            $problems[] = 'At least one step must map to Complete and be marked as the last step, or orders could never finish.';
        }

        $always = array_filter($steps, static fn (array $s): bool => $s['applies_when'] === 'always');
        if ($always === []) {
            $problems[] = 'At least one step must apply always, or some orders would have no steps to follow.';
        }

        foreach ($steps as $step) {
            if ((bool) $step['is_terminal'] && $step['maps_to'] !== 'complete') {
                $problems[] = sprintf('"%s" is marked as the last step, so it must map to Complete: stock is only sold when an order reaches a Complete step.', (string) $step['label']);
            }
        }

        if ($always !== [] && $this->someOrderStartsFinished($steps)) {
            $problems[] = 'Some orders would start on a last step and be finished before they begin. Put an ordinary step first.';
        }

        if ($problems !== []) {
            throw $this->refused('That change was not saved. ' . implode(' ', $problems));
        }
    }

    /**
     * Whether a new order of any kind would start on a terminal step. Orders start on the first step of their path, and
     * the path is decided by OrderService::stepApplies(), the same rule the order flow uses.
     *
     * @param list<array<string, mixed>> $steps in path order
     */
    private function someOrderStartsFinished(array $steps): bool
    {
        foreach (['pickup', 'delivery'] as $method) {
            foreach ([false, true] as $madeToOrder) {
                foreach ([0, 1] as $assembly) {
                    foreach ([0, 1] as $installation) {
                        $context = ['fulfillment_method' => $method, 'has_made_to_order' => $madeToOrder, 'assembly_fee' => $assembly, 'installation_fee' => $installation];
                        foreach ($steps as $step) {
                            if (OrderService::stepApplies((string) $step['applies_when'], $context)) {
                                if ((bool) $step['is_terminal']) {
                                    return true;
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    /** @throws HttpException 422 (field "action") while any order is open */
    private function assertNoOpenOrders(string $what): void
    {
        $open = $this->repository->countOpenOrders();
        if ($open > 0) {
            throw $this->refused(sprintf(
                'You cannot %s while %d order(s) are open (not cancelled and not finished): their path would change under them. Finish or cancel them first. You can still rename a step.',
                $what,
                $open,
            ));
        }
    }

    /**
     * Validates the fields every create and edit has, and returns them typed.
     *
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $steps every step before the change
     * @param array<string, string> $errors
     * @return array{label: string, sort_order: int, maps_to: string, is_terminal: bool, applies_when: string}
     */
    private function fields(array $input, array $steps, ?string $ownKey, array &$errors): array
    {
        $label = is_string($input['label'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $input['label'])) : '';
        if ($label === '' || mb_strlen($label) > self::LABEL_LIMIT || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            $errors['label'] = 'Enter the step name (up to 80 characters).';
        }

        $sort = $input['sort_order'] ?? null;
        $sortOrder = (is_string($sort) && preg_match('/^\d{1,4}$/D', trim($sort)) === 1) ? (int) trim($sort) : (is_int($sort) ? $sort : 0);
        if ($sortOrder < 1 || $sortOrder > self::SORT_MAX) {
            $errors['sort_order'] = 'Enter the position as a whole number from 1 to 9999. Steps run from the lowest number to the highest.';
        } else {
            foreach ($steps as $other) {
                if ((string) $other['key'] !== $ownKey && (int) $other['sort_order'] === $sortOrder) {
                    $errors['sort_order'] = sprintf('"%s" already uses position %d. Pick a free number so the order of steps is clear.', (string) $other['label'], $sortOrder);
                    break;
                }
            }
        }

        $mapsTo = is_string($input['maps_to'] ?? null) ? trim($input['maps_to']) : '';
        if (!in_array($mapsTo, self::MAPS_TO, true)) {
            $errors['maps_to'] = 'Choose Open, In progress or Complete.';
        }

        $appliesWhen = is_string($input['applies_when'] ?? null) ? trim($input['applies_when']) : '';
        if (!in_array($appliesWhen, OrderService::APPLIES_WHEN, true)) {
            $errors['applies_when'] = 'Choose one of the listed conditions. Any other value would never apply, so the step would never be reached.';
        }

        $terminal = $input['is_terminal'] ?? null;

        return [
            'label' => $label,
            'sort_order' => $sortOrder,
            'maps_to' => $mapsTo,
            'is_terminal' => $terminal === true || $terminal === '1' || $terminal === 1 || $terminal === 'on',
            'applies_when' => $appliesWhen,
        ];
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @return array<string, mixed>|null
     */
    private function byKey(array $steps, string $key): ?array
    {
        foreach ($steps as $step) {
            if ((string) $step['key'] === $key) {
                return $step;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function find(string $key): array
    {
        $this->assertKey($key);

        return $this->repository->find($key) ?? throw HttpException::notFound('That step could not be found.');
    }

    private function assertKey(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw HttpException::notFound('That step could not be found.');
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
}
