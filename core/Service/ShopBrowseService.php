<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Support\Format;
use Gfh\Core\Support\ShopQuery;

/**
 * Gathers what the public Shop pages show. It adds no rule of its own: which products are visible, which tiers a
 * customer sees and how filters are checked all stay in CatalogService, so the Shop and the JSON API cannot drift
 * apart. This class only calls it in the combinations a page needs and runs the Shop's extension hook.
 */
final class ShopBrowseService
{
    public const HOME_NEWEST = 8;

    public function __construct(
        private readonly CatalogService $catalog,
        private readonly Hooks $hooks,
    ) {
    }

    /**
     * The home page: the top-level categories that have products, and the newest products.
     *
     * @return array{categories: list<array<string, mixed>>, newest: list<array<string, mixed>>}
     */
    public function home(): array
    {
        $options = $this->catalog->filterOptions([], null, true);
        $newest = $this->catalog->searchProducts([], ShopQuery::newest(self::HOME_NEWEST), true);

        return ['categories' => $options['categories'], 'newest' => $newest['items']];
    }

    /**
     * One page of the product listing with its filter options and the chips of the active filters.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>, options: array<string, mixed>, chips: list<array{label: string, param: string, value: ?string}>}
     */
    public function listing(ShopQuery $query): array
    {
        $result = $this->catalog->searchProducts($query->filters, $query->list, true);

        // A link to a page past the end (an old bookmark after products were removed) shows the last page instead.
        if ($result['items'] === [] && $result['pagination']['total'] > 0 && $query->list->page > $result['pagination']['pages']) {
            $result = $this->catalog->searchProducts($query->filters, $query->listQueryFor($result['pagination']['pages']), true);
        }

        $like = $query->list->likePattern();
        $options = $this->catalog->filterOptions($query->filters, $like, true);

        // Counts are taken over the products the current filters match, so the brand list would shrink to the chosen
        // brand and the category list to the chosen category, and a shopper could not widen the choice. The same
        // service answers again without that one filter for its own list.
        if (isset($query->filters['category_id'])) {
            $withoutCategory = $query->filters;
            unset($withoutCategory['category_id']);
            $options['categories'] = $this->catalog->filterOptions($withoutCategory, $like, true)['categories'];
        }
        if (!empty($query->filters['brand_ids'])) {
            $withoutBrand = $query->filters;
            unset($withoutBrand['brand_ids']);
            $options['brands'] = $this->catalog->filterOptions($withoutBrand, $like, true)['brands'];
        }

        // A chosen brand stays in the list (with 0) even when the other filters leave it no products, so the form keeps it.
        if (isset($query->state['brand_id'])) {
            $listed = array_map(static fn (array $brand): string => (string) $brand['id'], $options['brands']);
            foreach ($this->catalog->activeBrands() as $brand) {
                if (in_array((string) $brand['id'], $query->state['brand_id'], true) && !in_array((string) $brand['id'], $listed, true)) {
                    $options['brands'][] = ['id' => $brand['id'], 'name' => $brand['name'], 'product_count' => 0];
                }
            }
        }

        // Only attributes the address can carry are offered as filters, and only when they offer a choice.
        $options['attributes'] = array_values(array_filter(
            $options['attributes'],
            static fn (array $attribute): bool => ShopQuery::isAttributeKey((string) $attribute['key'])
                && ($attribute['type'] === 'number' ? $attribute['min'] !== null : $attribute['values'] !== []),
        ));

        return [
            'items' => $result['items'],
            'pagination' => $result['pagination'],
            'options' => $options,
            'chips' => $this->chips($query),
        ];
    }

    /**
     * One product page: the product (public fields only), the variant to show first, and the extension sections.
     * Returns null for an unknown, draft or discontinued product, or one with no active variant.
     *
     * @param array<string, mixed>|null $customer the organization's customer record when a customer is signed in
     * @return array{product: array<string, mixed>, selected_variant_id: string, sections: list<array{id: string, title: string, bodyHtml: string}>}|null
     */
    public function product(string $slug, ?array $customer, ?string $requestedVariantId): ?array
    {
        $product = $this->catalog->getPublicProduct($slug, $customer);
        if ($product === null) {
            return null;
        }

        // The first variant is the default; a valid ?variant= (the no-JavaScript way to pick another) overrides it.
        $selected = (string) $product['variants'][0]['id'];
        foreach ($product['variants'] as $variant) {
            if ($requestedVariantId !== null && strcasecmp((string) $variant['id'], $requestedVariantId) === 0) {
                $selected = (string) $variant['id'];
            }
        }

        // Extensions receive what the page itself can show: the variant fields below only, so no cost, no trade price,
        // no reorder level and no stock count (just whether it is in stock).
        $forExtensions = $product;
        $forExtensions['variants'] = array_map(
            static fn (array $variant): array => array_intersect_key($variant, array_flip([
                'id', 'sku', 'name', 'unit', 'unit_size', 'allow_decimal_qty', 'qty_step', 'min_qty', 'retail_price', 'in_stock', 'tiers',
            ])),
            $product['variants'],
        );
        $raw = $this->hooks->filter(Hooks::PRODUCT_DETAIL_SECTIONS, [], [
            'product' => $forExtensions,
            'selected_variant_id' => $selected,
            'signed_in' => $customer !== null,
        ]);

        return ['product' => $product, 'selected_variant_id' => $selected, 'sections' => $this->sections($raw)];
    }

    /**
     * The extension sections, cleaned: an id of letters, digits and hyphens, a text title, and the section's own HTML.
     * An item without a title is dropped. The HTML is the extension's own output and is printed as it is (the same
     * trust as the Admin's order panels), so an extension must escape anything it prints that a customer or a
     * product record supplied. Documented in docs/extensions.md.
     *
     * @return list<array{id: string, title: string, bodyHtml: string}>
     */
    private function sections(mixed $raw): array
    {
        $sections = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            if (!is_array($item) || !isset($item['title']) || !is_scalar($item['title']) || trim((string) $item['title']) === '') {
                continue;
            }
            $id = strtolower((string) preg_replace('/[^A-Za-z0-9-]+/', '-', (string) ($item['id'] ?? '')));
            $id = trim($id, '-');
            $sections[] = [
                'id' => $id === '' ? 'section-' . (count($sections) + 1) : $id,
                'title' => trim((string) $item['title']),
                'bodyHtml' => is_scalar($item['bodyHtml'] ?? $item['contentHtml'] ?? '') ? (string) ($item['bodyHtml'] ?? $item['contentHtml'] ?? '') : '',
            ];
        }

        return array_slice($sections, 0, 10);
    }

    /**
     * One removable chip per active filter, labelled with the names the shopper knows (a brand's name, not its id).
     *
     * @return list<array{label: string, param: string, value: ?string}>
     */
    private function chips(ShopQuery $query): array
    {
        $chips = [];
        $state = $query->state;

        if (isset($state['q'])) {
            $chips[] = ['label' => 'Search: ' . $state['q'], 'param' => 'q', 'value' => null];
        }

        if (isset($state['category_id'])) {
            $name = $this->categoryName((string) $state['category_id']);
            if ($name !== null) {
                $chips[] = ['label' => 'Category: ' . $name, 'param' => 'category_id', 'value' => null];
            }
        }

        if (isset($state['brand_id'])) {
            $names = [];
            foreach ($this->catalog->activeBrands() as $brand) {
                $names[(string) $brand['id']] = (string) $brand['name'];
            }
            foreach ($state['brand_id'] as $id) {
                if (isset($names[$id])) {
                    $chips[] = ['label' => 'Brand: ' . $names[$id], 'param' => 'brand_id', 'value' => $id];
                }
            }
        }

        if (isset($state['min_price'])) {
            $chips[] = ['label' => 'From ' . Format::peso($state['min_price']), 'param' => 'min_price', 'value' => null];
        }
        if (isset($state['max_price'])) {
            $chips[] = ['label' => 'Up to ' . Format::peso($state['max_price']), 'param' => 'max_price', 'value' => null];
        }

        foreach ((array) ($state['attr'] ?? []) as $key => $rule) {
            $label = Format::attributeLabel((string) $key);
            if (is_array($rule)) {
                $text = match (true) {
                    isset($rule['min'], $rule['max']) => $rule['min'] . ' to ' . $rule['max'],
                    isset($rule['min']) => $rule['min'] . ' or more',
                    default => 'up to ' . ($rule['max'] ?? ''),
                };
            } else {
                $text = $rule === 'true' ? 'Yes' : ($rule === 'false' ? 'No' : (string) $rule);
            }
            $chips[] = ['label' => $label . ': ' . $text, 'param' => 'attr', 'value' => (string) $key];
        }

        return $chips;
    }

    private function categoryName(string $id): ?string
    {
        foreach ($this->catalog->categoryTree() as $top) {
            if ((string) $top['id'] === $id) {
                return (string) $top['name'];
            }
            foreach ($top['children'] as $child) {
                if ((string) $child['id'] === $id) {
                    return (string) $child['name'];
                }
            }
        }

        return null;
    }
}
