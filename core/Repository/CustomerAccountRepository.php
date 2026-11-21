<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/** All SQL for shop customer accounts (core customers table): one account across all shops. */
final class CustomerAccountRepository
{
    private const COLUMNS = 'id, full_name, email, phone, password_hash, status';

    public function __construct(private readonly \PDO $core)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        $statement = $this->core->prepare('SELECT ' . self::COLUMNS . ' FROM customers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->core->prepare('SELECT ' . self::COLUMNS . ' FROM customers WHERE lower(email) = lower(:email)');
        $statement->execute(['email' => $email]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function create(string $fullName, string $email, ?string $phone, string $passwordHash): string
    {
        $statement = $this->core->prepare(
            'INSERT INTO customers (full_name, email, phone, password_hash) VALUES (:full_name, :email, :phone, :password_hash) RETURNING id',
        );
        $statement->execute([
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone === '' ? null : $phone,
            'password_hash' => $passwordHash,
        ]);

        return (string) $statement->fetchColumn();
    }

    public function setPassword(string $id, string $passwordHash): void
    {
        $statement = $this->core->prepare('UPDATE customers SET password_hash = :hash WHERE id = :id');
        $statement->execute(['hash' => $passwordHash, 'id' => $id]);
    }

    /**
     * Changes the two profile fields a customer may edit. The email, status and password hash are deliberately not
     * in this statement, so no caller can change them through it.
     */
    public function updateProfile(string $id, string $fullName, ?string $phone): void
    {
        $statement = $this->core->prepare('UPDATE customers SET full_name = :full_name, phone = :phone WHERE id = :id');
        $statement->execute([
            'full_name' => $fullName,
            'phone' => ($phone === null || $phone === '') ? null : $phone,
            'id' => $id,
        ]);
    }

    /**
     * Locks the customer row until the surrounding transaction ends. Address changes take this lock first, so two
     * requests from the same customer cannot both pass the 10-address limit or both create a "first" address.
     */
    public function lock(string $id): bool
    {
        $statement = $this->core->prepare('SELECT 1 FROM customers WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }
}
