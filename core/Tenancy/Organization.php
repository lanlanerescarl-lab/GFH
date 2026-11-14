<?php

declare(strict_types=1);

namespace Gfh\Core\Tenancy;

/** One row of the organization registry in the core database. */
final class Organization
{
    /**
     * @param array<string, mixed> $branding
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $shortName,
        public readonly string $status,
        public readonly string $connectionEncrypted,
        public readonly string $dbSchema,
        public readonly array $branding,
        public readonly array $settings,
        public readonly int $migrationVersion,
        public readonly ?string $templateVersion,
    ) {
    }

    /** @param array<string, mixed> $row a row from OrganizationRepository (connection_hex is the encrypted bytes as hex) */
    public static function fromRow(array $row): self
    {
        $connection = hex2bin((string) $row['connection_hex']);

        return new self(
            (string) $row['id'],
            (string) $row['slug'],
            (string) $row['name'],
            (string) $row['short_name'],
            (string) $row['status'],
            $connection === false ? '' : $connection,
            (string) $row['db_schema'],
            self::decodeObject($row['branding'] ?? null),
            self::decodeObject($row['settings'] ?? null),
            (int) $row['migration_version'],
            isset($row['template_version']) ? (string) $row['template_version'] : null,
        );
    }

    /** @return array<string, mixed> */
    private static function decodeObject(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
