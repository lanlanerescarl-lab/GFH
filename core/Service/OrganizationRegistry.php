<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Database\ConnectionString;
use Gfh\Core\Repository\OrganizationRepository;
use Gfh\Core\Security\SecretBox;
use Gfh\Core\Tenancy\Organization;

/**
 * Registers organizations in the core registry: validates the details, encrypts the connection string
 * and stores everything. Creating an organization is data only; it never copies code.
 */
final class OrganizationRegistry
{
    /** First path segments that already mean something else. */
    public const RESERVED_SLUGS = ['health', 'api', 'media', 'assets', 'superadmin', '_skeleton'];

    public function __construct(
        private readonly OrganizationRepository $repository,
        private readonly SecretBox $secrets,
    ) {
    }

    /**
     * @param array<string, mixed> $branding
     * @param list<string>         $features
     * @throws \InvalidArgumentException when a detail is invalid or the slug is taken
     */
    public function register(
        string $slug,
        string $name,
        string $shortName,
        ConnectionString $connection,
        string $dbSchema = 'public',
        array $branding = [],
        array $features = [],
    ): Organization {
        $slug = trim($slug);
        $name = trim($name);
        $shortName = trim($shortName);

        if (preg_match('/^[a-z][a-z0-9-]{1,30}[a-z0-9]$/', $slug) !== 1) {
            throw new \InvalidArgumentException(
                'The slug must be 3 to 32 characters: lower-case letters, digits and hyphens, starting with a letter and not ending with a hyphen.',
            );
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is reserved and cannot be an organization slug.', $slug));
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('The name must be 2 to 120 characters.');
        }
        if (mb_strlen($shortName) < 1 || mb_strlen($shortName) > 30) {
            throw new \InvalidArgumentException('The short name must be 1 to 30 characters.');
        }
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $dbSchema) !== 1) {
            throw new \InvalidArgumentException('The schema name must be lower-case letters, digits and underscores.');
        }

        foreach ($features as $feature) {
            if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $feature) !== 1) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a valid feature key.', $feature));
            }
        }

        if ($this->repository->findBySlug($slug) !== null) {
            throw new \InvalidArgumentException(sprintf('An organization with the slug "%s" already exists.', $slug));
        }

        return $this->repository->create(
            $slug,
            $name,
            $shortName,
            $this->secrets->encrypt($connection->toUrl()),
            $dbSchema,
            $branding,
            array_values(array_unique($features)),
        );
    }
}
