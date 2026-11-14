<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;

/**
 * The same list parameters on every endpoint: page, per_page (default 20, at most 100), sort (a field name,
 * "-" in front for descending) and q (search text). Other filters are read by each endpoint from filters().
 * Sort fields are looked up in an allow-list, so no part of the SQL ever comes from the request.
 */
final class ListQuery
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    /**
     * @param array<string, mixed> $filters
     */
    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $orderBy,
        public readonly string $sortLabel,
        public readonly ?string $search,
        public readonly array $filters,
    ) {
    }

    /**
     * @param array<string, string> $sortable public sort name => SQL expression (never from the request)
     * @param list<string>          $filterKeys query parameters this endpoint accepts as filters
     * @param list<string>          $uuidKeys   filters that must be ids (checked, so a bad value is a 422 and never a database error)
     * @param list<string>          $dateKeys   filters that must be dates like 2026-11-12
     */
    public static function fromRequest(Request $request, array $sortable, string $defaultSort, array $filterKeys = [], array $uuidKeys = [], array $dateKeys = []): self
    {
        $query = $request->query();

        $page = self::positiveInt($query['page'] ?? 1, 'page');
        $perPage = min(self::MAX_PER_PAGE, self::positiveInt($query['per_page'] ?? self::DEFAULT_PER_PAGE, 'per_page'));

        $sort = is_string($query['sort'] ?? null) && $query['sort'] !== '' ? $query['sort'] : $defaultSort;
        $descending = str_starts_with($sort, '-');
        $name = ltrim($sort, '-');
        if (!isset($sortable[$name])) {
            throw HttpException::unprocessable(['sort' => 'Sort by one of: ' . implode(', ', array_keys($sortable)) . '.']);
        }

        $search = null;
        if (isset($query['q']) && is_string($query['q']) && trim($query['q']) !== '') {
            $search = mb_substr(trim($query['q']), 0, 100);
        }

        $filters = [];
        $errors = [];
        foreach ($filterKeys as $key) {
            if (!array_key_exists($key, $query)) {
                continue;
            }
            $value = $query[$key];
            if ($value !== '' && in_array($key, $uuidKeys, true) && !Uuid::isValid($value)) {
                $errors[$key] = 'Use an id.';
            }
            if ($value !== '' && in_array($key, $dateKeys, true)) {
                $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if ($date === false || $date->format('Y-m-d') !== $value) {
                    $errors[$key] = 'Use a date like 2026-11-12.';
                }
            }
            $filters[$key] = $value;
        }
        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        $orderBy = $sortable[$name] . ($descending ? ' DESC' : ' ASC');

        return new self($page, $perPage, $orderBy, ($descending ? '-' : '') . $name, $search, $filters);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** Pattern for ILIKE with the user's own % and _ made literal. */
    public function likePattern(): ?string
    {
        return $this->search === null ? null : '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search) . '%';
    }

    public function filter(string $key): mixed
    {
        return $this->filters[$key] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string}
     */
    public function result(array $items, int $total): array
    {
        return [
            'items' => $items,
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $this->perPage)),
            ],
            'sort' => $this->sortLabel,
        ];
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,6}$/', $value) === 1) {
            return (int) $value;
        }
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        throw HttpException::unprocessable([$field => 'Use a whole number of 1 or more.']);
    }
}
