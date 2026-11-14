<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

/**
 * Builds a WHERE clause from fixed SQL fragments with ? placeholders. The fragments are written in code;
 * every value from a request goes in as a bound parameter and never into the SQL text.
 */
final class SqlWhere
{
    /** @var list<string> */
    private array $clauses = [];
    /** @var list<mixed> */
    private array $params = [];

    public function add(string $clause, mixed ...$params): void
    {
        $this->clauses[] = '(' . $clause . ')';
        array_push($this->params, ...$params);
    }

    /** @param list<mixed> $values */
    public function addIn(string $column, array $values): void
    {
        if ($values === []) {
            $this->clauses[] = '(false)';

            return;
        }

        $this->clauses[] = '(' . $column . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . '))';
        array_push($this->params, ...array_values($values));
    }

    public function sql(): string
    {
        return $this->clauses === [] ? '' : 'WHERE ' . implode(' AND ', $this->clauses);
    }

    /** @return list<mixed> */
    public function params(): array
    {
        return $this->params;
    }
}
