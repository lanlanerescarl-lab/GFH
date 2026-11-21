<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Service\CatalogService;

/**
 * The state of one Shop listing page, read from the address bar.
 *
 * The URL carries the whole state (search, filters, sort, page), and a shopper can edit or mistype it, so this class
 * keeps only values of the right shape and remembers what it dropped. A bad value never reaches the catalog service
 * and never causes a server error: it is ignored (listed in $ignored) or, for the price fields, shown as a field
 * error. The rules themselves are not repeated here: what a valid filter is stays in CatalogService::parseFilters()
 * and ListQuery; this class only makes sure they are given text of the right shape, because nested or repeated
 * parameters (brand_id[][]=x) are possible in a URL and are not part of the JSON API's contract.
 *
 * It also builds the addresses of the page for a changed state (a page number, a removed filter), so a template
 * only prints them.
 */
final class ShopQuery
{
    /** Sort values the shopper can choose, with their labels. A leading "-" means descending. */
    public const SORT_CHOICES = [
        'name' => 'Name, A to Z',
        '-name' => 'Name, Z to A',
        'price' => 'Price, low to high',
        '-price' => 'Price, high to low',
        '-newest' => 'Newest first',
        'newest' => 'Oldest first',
    ];

    public const DEFAULT_SORT = 'name';

    private const MAX_BRANDS = 25;
    private const MAX_ATTRIBUTES = 10;
    private const PRICE_ERROR = 'Enter an amount like 150 or 150.50.';

    /**
     * @param array<string, mixed>  $state       only well-formed values: q, sort, page, per_page, brand_id (list), category_id, min_price, max_price, attr
     * @param array<string, string> $fieldErrors field name => message (the price fields)
     * @param array<string, string> $typed       what the shopper typed into a field that was refused, so the box can show it again
     * @param list<string>          $ignored     labels of the parts of the address that were dropped
     * @param array<string, mixed>  $filters     the repository filters from CatalogService::parseFilters()
     */
    private function __construct(
        public readonly array $state,
        public readonly array $fieldErrors,
        public readonly array $typed,
        public readonly array $ignored,
        public readonly ListQuery $list,
        public readonly array $filters,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $raw = $request->query();
        $state = [];
        $errors = [];
        $typed = [];
        $ignored = [];

        if (array_key_exists('q', $raw)) {
            if (is_string($raw['q'])) {
                $search = mb_substr(trim($raw['q']), 0, 100);
                if ($search !== '') {
                    $state['q'] = $search;
                }
            } else {
                $ignored['Search'] = true;
            }
        }

        if (array_key_exists('sort', $raw) && $raw['sort'] !== '') {
            if (is_string($raw['sort']) && isset(self::SORT_CHOICES[$raw['sort']])) {
                if ($raw['sort'] !== self::DEFAULT_SORT) {
                    $state['sort'] = $raw['sort'];
                }
            } else {
                $ignored['Sort order'] = true;
            }
        }

        foreach (['page' => 'Page', 'per_page' => 'Items per page'] as $key => $label) {
            if (!array_key_exists($key, $raw) || $raw[$key] === '') {
                continue;
            }
            if (is_string($raw[$key]) && preg_match('/^[1-9][0-9]{0,6}$/', $raw[$key]) === 1) {
                $state[$key] = $key === 'per_page' ? min(ListQuery::MAX_PER_PAGE, (int) $raw[$key]) : (int) $raw[$key];
            } else {
                $ignored[$label] = true;
            }
        }
        if (($state['page'] ?? 1) === 1) {
            unset($state['page']);
        }
        if (($state['per_page'] ?? ListQuery::DEFAULT_PER_PAGE) === ListQuery::DEFAULT_PER_PAGE) {
            unset($state['per_page']);
        }

        if (array_key_exists('brand_id', $raw) && $raw['brand_id'] !== '' && $raw['brand_id'] !== []) {
            $candidates = is_array($raw['brand_id']) ? $raw['brand_id'] : (is_string($raw['brand_id']) ? explode(',', $raw['brand_id']) : [null]);
            $brands = [];
            foreach ($candidates as $candidate) {
                $id = is_string($candidate) ? strtolower(trim($candidate)) : '';
                if ($id === '') {
                    continue;
                }
                if (Uuid::isValid($id) && count($brands) < self::MAX_BRANDS) {
                    $brands[$id] = $id;
                } else {
                    $ignored['Brand'] = true;
                }
            }
            if ($brands !== []) {
                $state['brand_id'] = array_values($brands);
            }
        }

        if (array_key_exists('category_id', $raw) && $raw['category_id'] !== '') {
            if (is_string($raw['category_id']) && Uuid::isValid(trim($raw['category_id']))) {
                $state['category_id'] = strtolower(trim($raw['category_id']));
            } else {
                $ignored['Category'] = true;
            }
        }

        foreach (['min_price', 'max_price'] as $key) {
            if (!array_key_exists($key, $raw) || $raw[$key] === '') {
                continue;
            }
            $units = is_string($raw[$key]) ? Decimal::parse($raw[$key], 2) : null;
            if ($units === null || $units < 0) {
                $errors[$key] = self::PRICE_ERROR;
                $typed[$key] = is_string($raw[$key]) ? mb_substr($raw[$key], 0, 20) : '';
                continue;
            }
            $state[$key] = Decimal::format($units, 2);
        }
        if (isset($state['min_price'], $state['max_price']) && Decimal::parse($state['min_price'], 2) > Decimal::parse($state['max_price'], 2)) {
            $errors['max_price'] = 'The highest price is lower than the lowest price.';
        }

        if (array_key_exists('attr', $raw)) {
            if (!is_array($raw['attr'])) {
                $ignored['Attribute'] = true;
            } else {
                $attributes = self::cleanAttributes($raw['attr'], $ignored);
                if ($attributes !== []) {
                    $state['attr'] = $attributes;
                }
            }
        }

        try {
            return self::build($state, $errors, $typed, array_keys($ignored));
        } catch (HttpException) {
            // Everything was checked above, so this is only a safety net: show the unfiltered list, never an error page.
            return self::build([], $errors, $typed, array_keys($ignored + ['Filters' => true]));
        }
    }

    /**
     * The newest products, for the home page: no filters, newest first, a fixed number of items.
     */
    public static function newest(int $limit): ListQuery
    {
        return ListQuery::fromRequest(
            new Request('GET', '/', query: ['sort' => '-newest', 'per_page' => (string) $limit]),
            CatalogService::PUBLIC_SORTS,
            self::DEFAULT_SORT,
        );
    }

    /** The same listing on another page (used when the requested page is past the last one). */
    public function listQueryFor(int $page): ListQuery
    {
        return self::listQuery([...$this->state, 'page' => max(1, $page)]);
    }

    public function search(): string
    {
        return (string) ($this->state['q'] ?? '');
    }

    public function sort(): string
    {
        return (string) ($this->state['sort'] ?? self::DEFAULT_SORT);
    }

    /** True when a filter (not just a search or a sort) is active. */
    public function hasFilters(): bool
    {
        return isset($this->state['category_id']) || isset($this->state['brand_id'])
            || isset($this->state['min_price']) || isset($this->state['max_price']) || isset($this->state['attr']);
    }

    /** True when anything narrows the list: a search or a filter. */
    public function isNarrowed(): bool
    {
        return $this->search() !== '' || $this->hasFilters();
    }

    public function hasBrand(string $id): bool
    {
        return in_array($id, (array) ($this->state['brand_id'] ?? []), true);
    }

    /** The current text or choice of an attribute filter: "" when none, or a string, or ['min' => ..., 'max' => ...]. */
    public function attribute(string $key): string|array
    {
        return $this->state['attr'][$key] ?? '';
    }

    /** What to show in a price box: the cleaned value, or the text the shopper typed when it was refused. */
    public function priceText(string $field): string
    {
        return (string) ($this->state[$field] ?? $this->typed[$field] ?? '');
    }

    /** True when an attribute key can be used as a filter in the address (lowercase letters, digits and underscores). */
    public static function isAttributeKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) === 1;
    }

    /** The address of this listing on one page of the results. */
    public function hrefPage(string $path, int $page): string
    {
        return self::href($path, [...$this->state, 'page' => $page]);
    }

    /**
     * The address of this listing with one filter removed (and back on page 1).
     *
     * @param string      $param brand_id, category_id, min_price, max_price, attr or q
     * @param string|null $value the brand id for brand_id, the attribute key for attr
     */
    public function hrefWithout(string $path, string $param, ?string $value = null): string
    {
        $state = $this->state;
        unset($state['page']);

        if ($param === 'brand_id') {
            $state['brand_id'] = array_values(array_filter((array) ($state['brand_id'] ?? []), static fn (string $id): bool => $id !== $value));
            if ($state['brand_id'] === []) {
                unset($state['brand_id']);
            }
        } elseif ($param === 'attr') {
            unset($state['attr'][(string) $value]);
            if (($state['attr'] ?? []) === []) {
                unset($state['attr']);
            }
        } else {
            unset($state[$param]);
        }

        return self::href($path, $state);
    }

    /** The address with every filter removed; the search and the sort stay. */
    public function hrefClearFilters(string $path): string
    {
        return self::href($path, array_intersect_key($this->state, ['q' => true, 'sort' => true, 'per_page' => true]));
    }

    /** @param array<string, mixed> $state */
    private static function href(string $path, array $state): string
    {
        $query = self::queryString($state);

        return $query === '' ? $path : $path . '?' . $query;
    }

    /** @param array<string, mixed> $state */
    private static function queryString(array $state): string
    {
        $parts = [];
        foreach (['q', 'category_id'] as $key) {
            if (isset($state[$key])) {
                $parts[] = $key . '=' . rawurlencode((string) $state[$key]);
            }
        }
        foreach ((array) ($state['brand_id'] ?? []) as $id) {
            $parts[] = 'brand_id%5B%5D=' . rawurlencode((string) $id);
        }
        foreach (['min_price', 'max_price'] as $key) {
            if (isset($state[$key])) {
                $parts[] = $key . '=' . rawurlencode((string) $state[$key]);
            }
        }
        foreach ((array) ($state['attr'] ?? []) as $key => $rule) {
            if (is_array($rule)) {
                foreach ($rule as $bound => $value) {
                    $parts[] = 'attr%5B' . rawurlencode((string) $key) . '%5D%5B' . rawurlencode((string) $bound) . '%5D=' . rawurlencode((string) $value);
                }
            } else {
                $parts[] = 'attr%5B' . rawurlencode((string) $key) . '%5D=' . rawurlencode((string) $rule);
            }
        }
        foreach (['sort', 'per_page', 'page'] as $key) {
            if (isset($state[$key])) {
                $parts[] = $key . '=' . rawurlencode((string) $state[$key]);
            }
        }

        return implode('&', $parts);
    }

    /**
     * @param array<mixed>        $attributes the raw attr[...] value
     * @param array<string, true> $ignored    collected labels (this method adds to it)
     * @return array<string, string|array<string, string>>
     */
    private static function cleanAttributes(array $attributes, array &$ignored): array
    {
        $clean = [];
        foreach ($attributes as $key => $rule) {
            $key = (string) $key;
            if (!self::isAttributeKey($key) || count($clean) >= self::MAX_ATTRIBUTES) {
                $ignored['Attribute'] = true;
                continue;
            }

            if (is_array($rule)) {
                $bounds = [];
                foreach (['min', 'max'] as $bound) {
                    if (!isset($rule[$bound]) || $rule[$bound] === '') {
                        continue;
                    }
                    $value = $rule[$bound];
                    if (is_string($value) && preg_match('/^-?\d{1,12}(?:\.\d{1,6})?$/', trim($value)) === 1) {
                        $bounds[$bound] = trim($value);
                    } else {
                        $ignored['Attribute'] = true;
                    }
                }
                if ($bounds !== []) {
                    $clean[$key] = $bounds;
                }
            } elseif (is_string($rule) && $rule !== '') {
                if (mb_strlen($rule) <= 200) {
                    $clean[$key] = $rule;
                } else {
                    $ignored['Attribute'] = true;
                }
            }
        }

        return $clean;
    }

    /**
     * @param array<string, mixed>  $state
     * @param array<string, string> $errors
     * @param array<string, string> $typed
     * @param list<string>          $ignored
     */
    private static function build(array $state, array $errors, array $typed, array $ignored): self
    {
        $list = self::listQuery($state);

        return new self($state, $errors, $typed, $ignored, $list, CatalogService::parseFilters($list->filters));
    }

    /** @param array<string, mixed> $state */
    private static function listQuery(array $state): ListQuery
    {
        $query = [];
        foreach (['q', 'sort', 'brand_id', 'category_id', 'min_price', 'max_price', 'attr'] as $key) {
            if (isset($state[$key])) {
                $query[$key] = $state[$key];
            }
        }
        foreach (['page', 'per_page'] as $key) {
            if (isset($state[$key])) {
                $query[$key] = (string) $state[$key];
            }
        }
        // A price that was refused is left out, so the list shows everything else.

        return ListQuery::fromRequest(
            new Request('GET', '/', query: $query),
            CatalogService::PUBLIC_SORTS,
            self::DEFAULT_SORT,
            CatalogService::PUBLIC_FILTER_KEYS,
            ['category_id'],
        );
    }
}
