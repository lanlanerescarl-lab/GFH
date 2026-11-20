<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/**
 * All SQL for discount rules in an organization database. It holds no business rule: DiscountRuleService decides.
 *
 * This is the admin side of the table. The POS and the pricing code read it through their own queries
 * (PosRepository::activeDiscountRules(), CatalogRepository::discountRuleByKey()), which already skip inactive rules.
 */
final class DiscountRuleRepository
{
    private const COLUMNS = 'r.id, r.key, r.label, r.percent, r.vat_exempt, r.active, r.created_at, r.updated_at';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /** Every rule with the number of POS sales that used it, active ones first. @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->rows(
            'SELECT ' . self::COLUMNS . ', (SELECT COUNT(*) FROM pos_sale_discounts d WHERE d.rule_id = r.id) AS sales_count '
            . 'FROM discount_rules r ORDER BY r.active DESC, r.label ASC, r.key ASC',
        );
    }

    /**
     * One rule with its sales count. With $lock the row is locked for a change.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, bool $lock = false): ?array
    {
        return $this->rows(
            'SELECT ' . self::COLUMNS . ', (SELECT COUNT(*) FROM pos_sale_discounts d WHERE d.rule_id = r.id) AS sales_count '
            . 'FROM discount_rules r WHERE r.id = ?' . ($lock ? ' FOR UPDATE OF r' : ''),
            [$id],
        )[0] ?? null;
    }

    public function idByKey(string $key): ?string
    {
        $id = $this->scalar('SELECT id FROM discount_rules WHERE key = ?', [$key]);

        return $id === false ? null : (string) $id;
    }

    public function insert(string $key, string $label, string $percent, bool $vatExempt): string
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO discount_rules (key, label, percent, vat_exempt, active) VALUES (:key, :label, :percent, :vat_exempt, true) RETURNING id',
        );
        $statement->bindValue('key', $key);
        $statement->bindValue('label', $label);
        $statement->bindValue('percent', $percent);
        $statement->bindValue('vat_exempt', $vatExempt, \PDO::PARAM_BOOL);
        $statement->execute();

        return (string) $statement->fetchColumn();
    }

    /** The key never changes and neither does the active flag: see setActive(). */
    public function update(string $id, string $label, string $percent, bool $vatExempt): void
    {
        $statement = $this->pdo->prepare('UPDATE discount_rules SET label = :label, percent = :percent, vat_exempt = :vat_exempt WHERE id = :id');
        $statement->bindValue('label', $label);
        $statement->bindValue('percent', $percent);
        $statement->bindValue('vat_exempt', $vatExempt, \PDO::PARAM_BOOL);
        $statement->bindValue('id', $id);
        $statement->execute();
    }

    public function setActive(string $id, bool $active): void
    {
        $statement = $this->pdo->prepare('UPDATE discount_rules SET active = :active WHERE id = :id');
        $statement->bindValue('active', $active, \PDO::PARAM_BOOL);
        $statement->bindValue('id', $id);
        $statement->execute();
    }
}
