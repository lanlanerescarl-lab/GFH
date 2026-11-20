<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Database\Database;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Support\SettingsSections;
use Gfh\Core\Tenancy\Organization;

/**
 * Organization settings for the Admin Settings screens.
 *
 * The rules, enforced here and nowhere else:
 *  - Only an actor who may edit Reports & Admin (an Admin) may use any method, whatever the page did before calling.
 *  - A value is saved only through SettingsService::set(), which validates it against the setting's own range or type
 *    and writes the audit entry. This service never touches the settings table.
 *  - A posted value is turned into the setting's own type before it is saved (a switch into true or false, whole
 *    digits into a number), so a stored value keeps its type. A key that is not a known setting is refused.
 *  - One save is all or nothing: if any field is refused, no field is saved, and every refused field is reported.
 *  - A value that equals the current one is not written, so it adds no audit entry.
 */
final class OrganizationSettingsService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly SettingsService $settings,
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, help: string, type: string, value: mixed, default: mixed, min: ?int, max: ?int, source: ?string}>
     * @throws HttpException 403 unless the actor may edit Reports & Admin
     */
    public function list(StaffUser $actor, Organization $organization): array
    {
        $this->assertMayManage($actor, $organization);

        return $this->settings->describe();
    }

    /**
     * @param array<string, string> $posted setting key => the text the form sent
     * @throws HttpException 403 unless permitted, 422 with one message per refused field
     */
    public function save(StaffUser $actor, Organization $organization, array $posted): void
    {
        $this->assertMayManage($actor, $organization);

        Database::transaction($this->pdo, function () use ($actor, $posted): void {
            $known = [];
            foreach ($this->settings->describe() as $setting) {
                $known[$setting['key']] = $setting;
            }

            $errors = [];
            foreach ($posted as $key => $raw) {
                $key = (string) $key;
                if (!isset($known[$key])) {
                    $errors[$key] = sprintf('"%s" is not a setting.', $key);
                    continue;
                }

                $setting = $known[$key];
                $value = $this->typed($setting['type'], $raw, $errors, $key);
                if ($value === null || $value === $setting['value']) {
                    continue;
                }

                try {
                    $this->settings->set($key, $value, $actor->id);
                } catch (HttpException $exception) {
                    if ($exception->status() !== 422) {
                        throw $exception;
                    }
                    $errors[$key] = (string) (array_values($exception->fields())[0] ?? $exception->getMessage());
                }
            }

            if ($errors !== []) {
                // Leaving the transaction by exception undoes any field that was already written.
                throw HttpException::unprocessable($errors);
            }
        });
    }

    /**
     * The posted text in the setting's own type, or null when the field has an error. Text that is not plainly a
     * number is handed on as it is, so SettingsService::set() refuses it with the setting's own message.
     *
     * @param array<string, string> $errors
     */
    private function typed(string $type, mixed $raw, array &$errors, string $key): int|bool|string|null
    {
        $text = is_string($raw) ? trim($raw) : '';

        if ($type === 'bool') {
            if ($text === '1') {
                return true;
            }
            if ($text === '0') {
                return false;
            }
            $errors[$key] = 'Choose on or off.';

            return null;
        }

        if ($type === 'int') {
            return preg_match('/^\d{1,9}$/D', $text) === 1 ? (int) $text : $text;
        }

        return $text;
    }

    /** The same permission the Settings pages ask for, checked again here so a caller that skips the page is still refused. */
    private function assertMayManage(StaffUser $actor, Organization $organization): void
    {
        $this->permissions->assert($actor, $organization, SettingsSections::MODULE, SettingsSections::ACTION);
    }
}
