<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/** All SQL for the organization settings table (key and JSON value). */
final class SettingsRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT key, value::text AS value FROM settings');
        $settings = [];
        foreach ($statement->fetchAll() as $row) {
            $settings[(string) $row['key']] = json_decode((string) $row['value'], true);
        }

        return $settings;
    }

    public function set(string $key, mixed $value, ?string $updatedBy): void
    {
        $this->pdo->prepare(
            'INSERT INTO settings (key, value, updated_by) VALUES (?, CAST(? AS jsonb), ?) '
            . 'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now(), updated_by = EXCLUDED.updated_by',
        )->execute([$key, json_encode($value, JSON_THROW_ON_ERROR), $updatedBy]);
    }
}
