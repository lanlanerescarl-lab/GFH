<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/** All SQL for staff accounts (core users table). Emails are compared case-insensitively. */
final class UserRepository
{
    private const COLUMNS = 'id, full_name, email, password_hash, org_role, status, last_login_at';

    public function __construct(private readonly \PDO $core)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        $statement = $this->core->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->core->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE lower(email) = lower(:email)');
        $statement->execute(['email' => $email]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function create(string $fullName, string $email, ?string $passwordHash, ?string $orgRole, string $status): string
    {
        $statement = $this->core->prepare(
            'INSERT INTO users (full_name, email, password_hash, org_role, status) '
            . 'VALUES (:full_name, :email, :password_hash, :org_role, :status) RETURNING id',
        );
        $statement->execute([
            'full_name' => $fullName,
            'email' => $email,
            'password_hash' => $passwordHash,
            'org_role' => $orgRole,
            'status' => $status,
        ]);

        return (string) $statement->fetchColumn();
    }

    public function updateProfile(string $id, string $fullName, string $email): void
    {
        $statement = $this->core->prepare('UPDATE users SET full_name = :full_name, email = :email WHERE id = :id');
        $statement->execute(['full_name' => $fullName, 'email' => $email, 'id' => $id]);
    }

    /** Sets a new password. A pending (invited) account becomes active; an inactive account stays inactive. */
    public function setPassword(string $id, string $passwordHash): void
    {
        $statement = $this->core->prepare(
            "UPDATE users SET password_hash = :hash, status = CASE WHEN status = 'pending' THEN 'active' ELSE status END WHERE id = :id",
        );
        $statement->execute(['hash' => $passwordHash, 'id' => $id]);
    }

    public function replacePasswordHash(string $id, string $passwordHash): void
    {
        $statement = $this->core->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $statement->execute(['hash' => $passwordHash, 'id' => $id]);
    }

    public function setStatus(string $id, string $status): void
    {
        $statement = $this->core->prepare('UPDATE users SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $id]);
    }

    public function touchLogin(string $id): void
    {
        $statement = $this->core->prepare('UPDATE users SET last_login_at = now() WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
