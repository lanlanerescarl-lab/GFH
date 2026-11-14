<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/**
 * SQL on an ORGANIZATION database about its own customer records.
 * An organization customer is linked to the shared core account through core_customer_id.
 */
final class OrganizationCustomerRepository
{
    public function __construct(private readonly \PDO $organization)
    {
    }

    /** @return array{id: string, core_customer_id: string}|null */
    public function findByCoreId(string $coreCustomerId): ?array
    {
        $statement = $this->organization->prepare('SELECT id, core_customer_id FROM customers WHERE core_customer_id = :core_id');
        $statement->execute(['core_id' => $coreCustomerId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** Creates the link for a core customer who is new to this organization; does nothing if it exists. Returns the local id. */
    public function ensureLinked(string $coreCustomerId, string $fullName, string $email, ?string $phone): string
    {
        $statement = $this->organization->prepare(
            'INSERT INTO customers (core_customer_id, full_name, email, phone) VALUES (:core_id, :full_name, :email, :phone) '
            . 'ON CONFLICT (core_customer_id) DO UPDATE SET core_customer_id = EXCLUDED.core_customer_id RETURNING id',
        );
        $statement->execute([
            'core_id' => $coreCustomerId,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => ($phone === null || $phone === '') ? null : $phone,
        ]);

        return (string) $statement->fetchColumn();
    }

    /** True when the order belongs to the customer linked to this core account. */
    public function ownsOrder(string $coreCustomerId, string $orderId): bool
    {
        $statement = $this->organization->prepare(
            'SELECT 1 FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.id = :order_id AND c.core_customer_id = :core_id',
        );
        $statement->execute(['order_id' => $orderId, 'core_id' => $coreCustomerId]);

        return $statement->fetchColumn() !== false;
    }
}
