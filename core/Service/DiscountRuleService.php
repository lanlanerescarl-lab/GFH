<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Database\Database;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\DiscountRuleRepository;
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\SettingsSections;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Organization;

/**
 * Discount rules for the Admin Settings screens.
 *
 * The rules, enforced here and nowhere else:
 *  - Only an actor who may edit Reports & Admin (an Admin) may use any method, whatever the page did before calling.
 *  - The key is chosen on create and is read-only afterwards. A posted key that differs from the stored one refuses
 *    the whole save. The active flag changes only through deactivate and reactivate.
 *  - Nothing is deleted: sales point at rules (pos_sale_discounts.rule_id), so a rule that is no longer wanted is
 *    deactivated. An inactive rule is not offered to the POS and cannot be applied to a sale.
 *  - Editing a rule changes future sales only. A recorded sale keeps its own amounts (pos_sale_discounts.amount and
 *    the sale's discount_total, vat_amount and total), so no sale is recalculated from the rule. The percent and the
 *    VAT-exempt flag are never read back for a sale that exists.
 *  - The percent is worked out with Decimal (never a float): 0 to 100 with at most two decimals.
 *  - Every change writes an organization audit entry with the actor and no secret.
 */
final class DiscountRuleService
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/D';
    public const KEY_LIMIT = 40;
    public const LABEL_LIMIT = 80;
    /** 100.00 percent in hundredths. */
    private const PERCENT_MAX_UNITS = 10000;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly DiscountRuleRepository $repository,
        private readonly AuditService $audit,
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * @return list<array<string, mixed>> every rule with the number of sales that used it
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
        $this->assertId($id);

        return $this->repository->find($id) ?? throw HttpException::notFound('That discount rule could not be found.');
    }

    /**
     * @param array<string, mixed> $input key, label, percent, vat_exempt
     * @return array{id: string}
     * @throws HttpException 403 unless permitted, 422 with field errors
     */
    public function create(StaffUser $actor, Organization $organization, array $input): array
    {
        $this->assertMayManage($actor, $organization);

        return Database::transaction($this->pdo, function () use ($actor, $input): array {
            $errors = [];

            $key = is_string($input['key'] ?? null) ? trim($input['key']) : '';
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                $errors['key'] = 'Enter a key of up to 40 characters: lower-case letters, digits and underscores, starting with a letter (for example senior_citizen).';
            } elseif ($this->repository->idByKey($key) !== null) {
                $errors['key'] = 'This key is already used by another discount rule.';
            }

            $label = $this->label($input['label'] ?? null, $errors);
            $percentUnits = $this->percent($input['percent'] ?? null, $errors);
            $vatExempt = $this->flag($input['vat_exempt'] ?? null);
            if ($errors !== []) {
                throw HttpException::unprocessable($errors);
            }

            $percent = Decimal::format((int) $percentUnits, 2);
            try {
                $id = $this->repository->insert($key, $label, $percent, $vatExempt);
            } catch (\PDOException $exception) {
                // Two Admins adding the same key at once: the unique index decides, and the loser gets the field message.
                if ($exception->getCode() === '23505') {
                    throw HttpException::unprocessable(['key' => 'This key is already used by another discount rule.']);
                }
                throw $exception;
            }

            $this->audit->organization($this->pdo, 'discount_rule.created', $actor->id, 'discount_rule', $id, [
                'key' => $key,
                'label' => $label,
                'percent' => $percent,
                'vat_exempt' => $vatExempt,
            ]);

            return ['id' => $id];
        });
    }

    /**
     * Saves the edit form: label, percent and the VAT-exempt flag. A posted key that differs from the stored one is a
     * tampered request and refuses the whole save.
     *
     * @param array<string, mixed> $input label, percent, vat_exempt (and key, only if tampered)
     * @throws HttpException 403 unless permitted, 404 unknown rule, 422 with field errors
     */
    public function update(StaffUser $actor, Organization $organization, string $id, array $input): void
    {
        $this->assertMayManage($actor, $organization);
        $this->assertId($id);

        Database::transaction($this->pdo, function () use ($actor, $id, $input): void {
            $rule = $this->repository->find($id, true) ?? throw HttpException::notFound('That discount rule could not be found.');

            $errors = [];
            if (array_key_exists('key', $input) && $input['key'] !== $rule['key']) {
                $errors['key'] = 'The key cannot be changed after the rule is created. Add a new rule instead.';
            }
            $label = $this->label($input['label'] ?? null, $errors);
            $percentUnits = $this->percent($input['percent'] ?? null, $errors);
            $vatExempt = $this->flag($input['vat_exempt'] ?? null);
            if ($errors !== []) {
                throw HttpException::unprocessable($errors);
            }

            $percent = Decimal::format((int) $percentUnits, 2);
            $changed = [];
            $detail = ['key' => (string) $rule['key']];
            if ($label !== (string) $rule['label']) {
                $changed[] = 'label';
                $detail['label'] = ['from' => (string) $rule['label'], 'to' => $label];
            }
            // Compared as numbers in hundredths, so "20" and "20.00" are the same value and write nothing.
            if ((int) Decimal::parse((string) $rule['percent'], 2) !== (int) $percentUnits) {
                $changed[] = 'percent';
                $detail['percent'] = ['from' => Decimal::format((int) Decimal::parse((string) $rule['percent'], 2), 2), 'to' => $percent];
            }
            if ($vatExempt !== (bool) $rule['vat_exempt']) {
                $changed[] = 'vat_exempt';
                $detail['vat_exempt'] = ['from' => (bool) $rule['vat_exempt'], 'to' => $vatExempt];
            }
            if ($changed === []) {
                return; // nothing to save, so nothing to audit
            }

            $this->repository->update($id, $label, $percent, $vatExempt);
            $detail['changed'] = $changed;
            $this->audit->organization($this->pdo, 'discount_rule.updated', $actor->id, 'discount_rule', $id, $detail);
        });
    }

    /**
     * @throws HttpException 403 unless permitted, 404 unknown rule, 422 (field "action") when already inactive
     */
    public function deactivate(StaffUser $actor, Organization $organization, string $id): void
    {
        $this->assertMayManage($actor, $organization);
        $this->assertId($id);

        Database::transaction($this->pdo, function () use ($actor, $id): void {
            $rule = $this->repository->find($id, true) ?? throw HttpException::notFound('That discount rule could not be found.');
            if (!(bool) $rule['active']) {
                throw $this->refused('This discount rule is already inactive.');
            }

            $this->repository->setActive($id, false);
            $this->audit->organization($this->pdo, 'discount_rule.deactivated', $actor->id, 'discount_rule', $id, ['key' => (string) $rule['key']]);
        });
    }

    /**
     * @throws HttpException 403 unless permitted, 404 unknown rule, 422 (field "action") when already active
     */
    public function reactivate(StaffUser $actor, Organization $organization, string $id): void
    {
        $this->assertMayManage($actor, $organization);
        $this->assertId($id);

        Database::transaction($this->pdo, function () use ($actor, $id): void {
            $rule = $this->repository->find($id, true) ?? throw HttpException::notFound('That discount rule could not be found.');
            if ((bool) $rule['active']) {
                throw $this->refused('This discount rule is already active.');
            }

            $this->repository->setActive($id, true);
            $this->audit->organization($this->pdo, 'discount_rule.reactivated', $actor->id, 'discount_rule', $id, ['key' => (string) $rule['key']]);
        });
    }

    private function assertId(string $id): void
    {
        if (!Uuid::isValid($id)) {
            throw HttpException::notFound('That discount rule could not be found.');
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

    /** @param array<string, string> $errors */
    private function label(mixed $value, array &$errors): string
    {
        $label = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
        if ($label === '' || mb_strlen($label) > self::LABEL_LIMIT || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            $errors['label'] = 'Enter the name cashiers will see (up to 80 characters).';
        }

        return $label;
    }

    /**
     * The percent in hundredths (20.00 is 2000), or null with an error. Only a plain number from 0 to 100 with at
     * most two decimals is accepted; "1e1", "12.345", "-5", "101" and "" are all refused.
     *
     * @param array<string, string> $errors
     */
    private function percent(mixed $value, array &$errors): ?int
    {
        $units = is_string($value) || is_int($value) ? Decimal::parse($value, 2) : null;
        if ($units === null || $units < 0 || $units > self::PERCENT_MAX_UNITS) {
            $errors['percent'] = 'Enter a percentage from 0 to 100 with at most two decimals (for example 20 or 12.5).';

            return null;
        }

        return $units;
    }

    /** A tick box: anything the form sends as "on" counts, everything else (including nothing) is off. */
    private function flag(mixed $value): bool
    {
        return $value === true || $value === '1' || $value === 1 || $value === 'on';
    }
}
