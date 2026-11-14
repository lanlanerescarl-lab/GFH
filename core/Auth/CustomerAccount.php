<?php

declare(strict_types=1);

namespace Gfh\Core\Auth;

/** A shop customer. One account works in all three shops. Never holds the password hash. */
final class CustomerAccount
{
    public function __construct(
        public readonly string $id,
        public readonly string $fullName,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly string $status,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id'],
            (string) $row['full_name'],
            (string) $row['email'],
            isset($row['phone']) ? (string) $row['phone'] : null,
            (string) $row['status'],
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
