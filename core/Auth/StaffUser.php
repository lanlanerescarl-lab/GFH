<?php

declare(strict_types=1);

namespace Gfh\Core\Auth;

/** A signed-in or looked-up staff account from the core users table. Never holds the password hash. */
final class StaffUser
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $fullName,
        public readonly string $email,
        public readonly ?string $orgRole,
        public readonly string $status,
        public readonly bool $isSystem = false,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id'],
            (string) $row['full_name'],
            (string) $row['email'],
            isset($row['org_role']) ? (string) $row['org_role'] : null,
            (string) $row['status'],
        );
    }

    /** The acting identity of bin/ scripts: treated like a Super Admin, recorded in the audit log with no user id. */
    public static function system(): self
    {
        return new self(null, 'System', 'system@localhost', 'super_admin', 'active', true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuperAdmin(): bool
    {
        return $this->orgRole === 'super_admin';
    }

    /** Super Admin and Group Viewer. They oversee; they never change organization operations. */
    public function isGroupLevel(): bool
    {
        return $this->orgRole !== null;
    }
}
