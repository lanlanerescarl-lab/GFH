<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/** All SQL for POS terminals in an organization database. It holds no business rule: TerminalService decides. */
final class TerminalRepository
{
    private const COLUMNS = 't.id, t.branch_id, t.code, t.name, t.status, t.last_seen_at, t.created_at, t.updated_at, '
        . 'b.code AS branch_code, b.name AS branch_name, b.status AS branch_status';

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

    /** Every terminal with its branch, active ones first. @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->rows(
            'SELECT ' . self::COLUMNS . ' FROM terminals t JOIN branches b ON b.id = t.branch_id '
            . "ORDER BY (t.status = 'active') DESC, b.name ASC, t.code ASC",
        );
    }

    /**
     * One terminal with its branch. With $lock the terminal row is locked for a change and its branch row is locked
     * against changes too, so the branch cannot be deactivated between the check and the write.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, bool $lock = false): ?array
    {
        return $this->rows(
            'SELECT ' . self::COLUMNS . ' FROM terminals t JOIN branches b ON b.id = t.branch_id WHERE t.id = ?'
            . ($lock ? ' FOR UPDATE OF t FOR SHARE OF b' : ''),
            [$id],
        )[0] ?? null;
    }

    public function idByCode(string $code): ?string
    {
        $id = $this->scalar('SELECT id FROM terminals WHERE code = ?', [$code]);

        return $id === false ? null : (string) $id;
    }

    public function insert(string $branchId, string $code, string $name): string
    {
        return (string) $this->scalar('INSERT INTO terminals (branch_id, code, name) VALUES (?, ?, ?) RETURNING id', [$branchId, $code, $name]);
    }

    /** Only the name can change: the code prefixes receipt numbers and the branch is fixed at creation. */
    public function updateName(string $id, string $name): void
    {
        $statement = $this->pdo->prepare('UPDATE terminals SET name = ? WHERE id = ?');
        $statement->execute([$name, $id]);
    }

    public function setStatus(string $id, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE terminals SET status = ? WHERE id = ?');
        $statement->execute([$status, $id]);
    }

    /** Shifts still open on the terminal (migration 0004 allows at most one). */
    public function countOpenShifts(string $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM pos_shifts WHERE terminal_id = ? AND status = 'open'", [$id]);
    }
}
