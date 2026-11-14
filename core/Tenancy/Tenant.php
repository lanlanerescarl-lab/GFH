<?php

declare(strict_types=1);

namespace Gfh\Core\Tenancy;

use Gfh\Core\Database\Database;

/**
 * The organization a request is about, plus (for signed-in staff) their membership in it.
 * The organization database connection is opened only when first asked for, from the encrypted
 * connection stored in the registry.
 */
final class Tenant
{
    private ?\PDO $connection = null;

    /** @param array<string, mixed>|null $membership */
    public function __construct(
        public readonly Organization $organization,
        private readonly Database $database,
        public readonly ?array $membership = null,
    ) {
    }

    /** Role of the signed-in staff member in this organization, or null. */
    public function role(): ?string
    {
        return $this->membership === null ? null : (string) $this->membership['role'];
    }

    /** @param array<string, mixed> $membership */
    public function withMembership(array $membership): self
    {
        $copy = new self($this->organization, $this->database, $membership);
        $copy->connection = $this->connection;

        return $copy;
    }

    /** Connection to this organization's own database (limited web-request role). */
    public function database(): \PDO
    {
        return $this->connection ??= $this->database->organization($this->organization);
    }
}
