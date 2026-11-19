<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Database\Database;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\BranchRepository;
use Gfh\Core\Repository\TerminalRepository;
use Gfh\Core\Support\SettingsSections;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Organization;

/**
 * POS terminals for the Admin Settings screens.
 *
 * The rules, enforced here and nowhere else:
 *  - Only an actor who may edit Reports & Admin (an Admin) may use any method, whatever the page did before calling.
 *  - A terminal is created at an active branch. Its code (it prefixes receipt numbers) and its branch are fixed at
 *    creation, so only the name can change. last_seen_at is written by the POS and is never edited here.
 *  - A terminal with an open shift cannot be deactivated, and a terminal whose branch is inactive cannot be reactivated.
 *  - Nothing is deleted, and every change writes an organization audit entry.
 */
final class TerminalService
{
    private const CODE_PATTERN = '/^[A-Z0-9]{1,6}$/D';
    private const NAME_LIMIT = 120;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly TerminalRepository $repository,
        private readonly BranchRepository $branches,
        private readonly AuditService $audit,
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * @return list<array<string, mixed>> every terminal with its branch
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

        return $this->repository->find($id) ?? throw HttpException::notFound('That terminal could not be found.');
    }

    /**
     * The branches a new terminal may be placed at: active ones only.
     *
     * @return list<array<string, mixed>>
     */
    public function branchOptions(StaffUser $actor, Organization $organization): array
    {
        $this->assertMayManage($actor, $organization);

        return $this->branches->activeOptions();
    }

    /**
     * @param array<string, mixed> $input branch_id, code, name
     * @return array{id: string}
     * @throws HttpException 403 unless permitted, 422 with field errors
     */
    public function create(StaffUser $actor, Organization $organization, array $input): array
    {
        $this->assertMayManage($actor, $organization);

        return Database::transaction($this->pdo, function () use ($actor, $input): array {
            $errors = [];

            // The branch row is locked against changes until this transaction ends, so it cannot be deactivated
            // between the check and the insert.
            $branchId = is_string($input['branch_id'] ?? null) ? trim($input['branch_id']) : '';
            $branch = Uuid::isValid($branchId) ? $this->branches->find($branchId, 'share') : null;
            if ($branch === null || $branch['status'] !== 'active') {
                $errors['branch_id'] = 'Choose an active branch.';
            }

            $code = is_string($input['code'] ?? null) ? trim($input['code']) : '';
            if (preg_match(self::CODE_PATTERN, $code) !== 1) {
                $errors['code'] = 'Enter a code of 1 to 6 characters: capital letters and digits only (for example T3).';
            } elseif ($this->repository->idByCode($code) !== null) {
                $errors['code'] = 'This code is already used by another terminal.';
            }

            $name = $this->name($input['name'] ?? null, $errors);
            if ($errors !== []) {
                throw HttpException::unprocessable($errors);
            }

            $id = $this->repository->insert($branchId, $code, $name);
            $this->audit->organization($this->pdo, 'terminal.created', $actor->id, 'terminal', $id, [
                'code' => $code,
                'name' => $name,
                'branch_id' => $branchId,
            ]);

            return ['id' => $id];
        });
    }

    /**
     * Saves the edit form: the name only. A posted code or branch that differs from the stored one is a tampered
     * request and refuses the whole save.
     *
     * @param array<string, mixed> $input name (and code or branch_id, only if tampered)
     * @throws HttpException 403 unless permitted, 404 unknown terminal, 422 with field errors
     */
    public function update(StaffUser $actor, Organization $organization, string $id, array $input): void
    {
        $this->assertMayManage($actor, $organization);
        $this->assertId($id);

        Database::transaction($this->pdo, function () use ($actor, $id, $input): void {
            $terminal = $this->repository->find($id, true) ?? throw HttpException::notFound('That terminal could not be found.');

            $errors = [];
            if (array_key_exists('code', $input) && $input['code'] !== $terminal['code']) {
                $errors['code'] = 'The code cannot be changed after the terminal is created. It prefixes receipt numbers.';
            }
            if (array_key_exists('branch_id', $input) && $input['branch_id'] !== $terminal['branch_id']) {
                $errors['branch_id'] = 'A terminal cannot move to another branch. Deactivate it and add a new terminal there.';
            }
            $name = $this->name($input['name'] ?? null, $errors);
            if ($errors !== []) {
                throw HttpException::unprocessable($errors);
            }

            if ($name === (string) $terminal['name']) {
                return; // nothing to save, so nothing to audit
            }

            $this->repository->updateName($id, $name);
            $this->audit->organization($this->pdo, 'terminal.updated', $actor->id, 'terminal', $id, ['code' => (string) $terminal['code'], 'changed' => ['name']]);
        });
    }

    /**
     * @throws HttpException 403 unless permitted, 404 unknown terminal, 422 (field "action") with an open shift
     */
    public function deactivate(StaffUser $actor, Organization $organization, string $id): void
    {
        $this->assertMayManage($actor, $organization);
        $this->assertId($id);

        Database::transaction($this->pdo, function () use ($actor, $id): void {
            $terminal = $this->repository->find($id, true) ?? throw HttpException::notFound('That terminal could not be found.');

            if ($terminal['status'] !== 'active') {
                throw $this->refused('This terminal is already inactive.');
            }
            if ($this->repository->countOpenShifts($id) > 0) {
                throw $this->refused('This terminal has an open shift. Close the shift on the POS first, then deactivate the terminal.');
            }

            $this->repository->setStatus($id, 'inactive');
            $this->audit->organization($this->pdo, 'terminal.deactivated', $actor->id, 'terminal', $id, ['code' => (string) $terminal['code']]);
        });
    }

    /**
     * @throws HttpException 403 unless permitted, 404 unknown terminal, 422 (field "action") when already active or its branch is inactive
     */
    public function reactivate(StaffUser $actor, Organization $organization, string $id): void
    {
        $this->assertMayManage($actor, $organization);
        $this->assertId($id);

        Database::transaction($this->pdo, function () use ($actor, $id): void {
            $terminal = $this->repository->find($id, true) ?? throw HttpException::notFound('That terminal could not be found.');

            if ($terminal['status'] === 'active') {
                throw $this->refused('This terminal is already active.');
            }
            if ($terminal['branch_status'] !== 'active') {
                throw $this->refused('Its branch is inactive. Reactivate the branch first, then reactivate this terminal.');
            }

            $this->repository->setStatus($id, 'active');
            $this->audit->organization($this->pdo, 'terminal.reactivated', $actor->id, 'terminal', $id, ['code' => (string) $terminal['code']]);
        });
    }

    private function assertId(string $id): void
    {
        if (!Uuid::isValid($id)) {
            throw HttpException::notFound('That terminal could not be found.');
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
    private function name(mixed $value, array &$errors): string
    {
        $name = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
        if ($name === '' || mb_strlen($name) > self::NAME_LIMIT || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            $errors['name'] = 'Enter the terminal name (up to 120 characters).';
        }

        return $name;
    }
}
