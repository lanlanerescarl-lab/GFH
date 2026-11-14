<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

use Gfh\Core\Database\Database;
use Gfh\Core\Tenancy\Organization;

/**
 * All SQL for the organization registry in the core database.
 * Reading works with the web-request role; writing needs the migrator role (bin/ scripts), because
 * web requests are never allowed to change the registry.
 */
final class OrganizationRepository
{
    private const COLUMNS = "id, slug, name, short_name, status, encode(connection_encrypted, 'hex') AS connection_hex, "
        . 'db_schema, branding::text AS branding, settings::text AS settings, migration_version, template_version';

    public function __construct(private readonly \PDO $core)
    {
    }

    public function findBySlug(string $slug): ?Organization
    {
        $statement = $this->core->prepare('SELECT ' . self::COLUMNS . ' FROM organizations WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();

        return $row === false ? null : Organization::fromRow($row);
    }

    /**
     * @param list<string> $statuses
     * @return list<Organization>
     */
    public function all(array $statuses = ['active', 'suspended']): array
    {
        if ($statuses === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
        $statement = $this->core->prepare(
            'SELECT ' . self::COLUMNS . ' FROM organizations WHERE status IN (' . $placeholders . ') ORDER BY slug',
        );
        $statement->execute(array_values($statuses));

        return array_map(static fn (array $row): Organization => Organization::fromRow($row), $statement->fetchAll());
    }

    /**
     * The feature keys switched on for an organization (read-only; the loader uses them to decide which shared extensions to load).
     *
     * @return list<string>
     */
    public function enabledFeatureKeys(string $organizationId): array
    {
        $statement = $this->core->prepare('SELECT feature_key FROM organization_features WHERE organization_id = :organization AND enabled ORDER BY feature_key');
        $statement->execute(['organization' => $organizationId]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Inserts an organization and its feature switches in one transaction.
     *
     * @param array<string, mixed> $branding
     * @param list<string>         $features
     */
    public function create(
        string $slug,
        string $name,
        string $shortName,
        string $connectionEncrypted,
        string $dbSchema,
        array $branding,
        array $features,
    ): Organization {
        Database::transaction($this->core, function (\PDO $pdo) use ($slug, $name, $shortName, $connectionEncrypted, $dbSchema, $branding, $features): void {
            $insert = $pdo->prepare(
                'INSERT INTO organizations (slug, name, short_name, connection_encrypted, db_schema, branding) '
                . "VALUES (:slug, :name, :short_name, decode(:connection, 'hex'), :db_schema, CAST(:branding AS jsonb)) "
                . 'RETURNING id',
            );
            $insert->execute([
                'slug' => $slug,
                'name' => $name,
                'short_name' => $shortName,
                'connection' => bin2hex($connectionEncrypted),
                'db_schema' => $dbSchema,
                'branding' => json_encode((object) $branding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $organizationId = (string) $insert->fetchColumn();

            $feature = $pdo->prepare(
                'INSERT INTO organization_features (organization_id, feature_key, enabled) VALUES (:organization_id, :feature_key, true)',
            );
            foreach ($features as $key) {
                $feature->execute(['organization_id' => $organizationId, 'feature_key' => $key]);
            }
        });

        $created = $this->findBySlug($slug);
        if ($created === null) {
            throw new \RuntimeException('The organization was saved but could not be read back.');
        }

        return $created;
    }

    public function setMigrationVersion(string $organizationId, int $version): void
    {
        $statement = $this->core->prepare('UPDATE organizations SET migration_version = :version WHERE id = :id');
        $statement->execute(['version' => $version, 'id' => $organizationId]);
    }
}
