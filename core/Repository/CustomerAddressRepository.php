<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/**
 * All SQL for a customer's saved addresses (core customer_addresses table).
 *
 * Every query takes the customer id and filters by it. An address id on its own never selects, changes or deletes
 * a row, so one customer can never reach another customer's address, whatever id they send.
 */
final class CustomerAddressRepository
{
    private const COLUMNS = 'id, label, line1, barangay, city, province, postal_code, is_default, created_at, updated_at';

    public function __construct(private readonly \PDO $core)
    {
    }

    /**
     * The default address first, then the newest.
     *
     * @return list<array<string, mixed>>
     */
    public function listForCustomer(string $customerId): array
    {
        $statement = $this->core->prepare(
            'SELECT ' . self::COLUMNS . ' FROM customer_addresses WHERE customer_id = :customer_id '
            . 'ORDER BY is_default DESC, created_at DESC, id DESC',
        );
        $statement->execute(['customer_id' => $customerId]);

        return array_values($statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findForCustomer(string $customerId, string $id): ?array
    {
        $statement = $this->core->prepare(
            'SELECT ' . self::COLUMNS . ' FROM customer_addresses WHERE id = :id AND customer_id = :customer_id',
        );
        $statement->execute(['id' => $id, 'customer_id' => $customerId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function countForCustomer(string $customerId): int
    {
        $statement = $this->core->prepare('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = :customer_id');
        $statement->execute(['customer_id' => $customerId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{label: ?string, line1: string, barangay: ?string, city: string, province: string, postal_code: ?string} $fields
     * @return string the new address id
     */
    public function create(string $customerId, array $fields, bool $isDefault): string
    {
        // clock_timestamp(), not now(): now() is frozen for a whole transaction, which would give two addresses
        // saved in one transaction the same created_at and make "newest" ambiguous.
        $statement = $this->core->prepare(
            'INSERT INTO customer_addresses (customer_id, label, line1, barangay, city, province, postal_code, is_default, created_at) '
            . 'VALUES (:customer_id, :label, :line1, :barangay, :city, :province, :postal_code, :is_default, clock_timestamp()) RETURNING id',
        );
        $statement->bindValue('customer_id', $customerId);
        $statement->bindValue('label', $fields['label']);
        $statement->bindValue('line1', $fields['line1']);
        $statement->bindValue('barangay', $fields['barangay']);
        $statement->bindValue('city', $fields['city']);
        $statement->bindValue('province', $fields['province']);
        $statement->bindValue('postal_code', $fields['postal_code']);
        $statement->bindValue('is_default', $isDefault, \PDO::PARAM_BOOL);
        $statement->execute();

        return (string) $statement->fetchColumn();
    }

    /**
     * Replaces the six editable fields. Whether the address is the default is never changed here.
     *
     * @param array{label: ?string, line1: string, barangay: ?string, city: string, province: string, postal_code: ?string} $fields
     * @return bool false when the customer has no such address
     */
    public function update(string $customerId, string $id, array $fields): bool
    {
        $statement = $this->core->prepare(
            'UPDATE customer_addresses SET label = :label, line1 = :line1, barangay = :barangay, city = :city, '
            . 'province = :province, postal_code = :postal_code WHERE id = :id AND customer_id = :customer_id',
        );
        $statement->bindValue('label', $fields['label']);
        $statement->bindValue('line1', $fields['line1']);
        $statement->bindValue('barangay', $fields['barangay']);
        $statement->bindValue('city', $fields['city']);
        $statement->bindValue('province', $fields['province']);
        $statement->bindValue('postal_code', $fields['postal_code']);
        $statement->bindValue('id', $id);
        $statement->bindValue('customer_id', $customerId);
        $statement->execute();

        return $statement->rowCount() === 1;
    }

    /** @return bool false when the customer has no such address */
    public function delete(string $customerId, string $id): bool
    {
        $statement = $this->core->prepare('DELETE FROM customer_addresses WHERE id = :id AND customer_id = :customer_id');
        $statement->execute(['id' => $id, 'customer_id' => $customerId]);

        return $statement->rowCount() === 1;
    }

    /** Clears the customer's default flag. Must run before another address is made the default (one default per customer). */
    public function clearDefault(string $customerId): void
    {
        $statement = $this->core->prepare('UPDATE customer_addresses SET is_default = false WHERE customer_id = :customer_id AND is_default');
        $statement->execute(['customer_id' => $customerId]);
    }

    /** @return bool false when the customer has no such address */
    public function markDefault(string $customerId, string $id): bool
    {
        $statement = $this->core->prepare('UPDATE customer_addresses SET is_default = true WHERE id = :id AND customer_id = :customer_id');
        $statement->execute(['id' => $id, 'customer_id' => $customerId]);

        return $statement->rowCount() === 1;
    }

    /** The id of the customer's most recently created address, or null when they have none. */
    public function newestId(string $customerId): ?string
    {
        $statement = $this->core->prepare(
            'SELECT id FROM customer_addresses WHERE customer_id = :customer_id ORDER BY created_at DESC, id DESC LIMIT 1',
        );
        $statement->execute(['customer_id' => $customerId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (string) $id;
    }
}
