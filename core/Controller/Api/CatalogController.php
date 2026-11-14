<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Api;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\CatalogService;
use Gfh\Core\Support\ListQuery;

/** Public catalog reads for the Shop: anyone can browse. Only active products appear, and never costs. */
final class CatalogController extends ApiController
{
    private const SORTS = ['name' => 'p.name', 'price' => 'price_from', 'newest' => 'p.created_at'];
    private const FILTERS = ['brand_id', 'category_id', 'min_price', 'max_price', 'attr'];

    /** GET /{org}/api/v1/catalog/products */
    public function products(Request $request): Response
    {
        $services = $this->publicServices($request);
        $query = ListQuery::fromRequest($request, self::SORTS, 'name', self::FILTERS, ['category_id']);

        return Response::ok($services->catalog()->searchProducts(CatalogService::parseFilters($query->filters), $query, true));
    }

    /** GET /{org}/api/v1/catalog/filters: the options for the filter panel of the same listing. */
    public function filters(Request $request): Response
    {
        $services = $this->publicServices($request);
        $query = ListQuery::fromRequest($request, self::SORTS, 'name', self::FILTERS, ['category_id']);

        return Response::ok($services->catalog()->filterOptions(CatalogService::parseFilters($query->filters), $query->likePattern(), true));
    }

    /**
     * GET /{org}/api/v1/catalog/products/{slug}
     * Tier prices are shown only to a signed-in customer, and only the tiers that customer qualifies for.
     */
    public function product(Request $request): Response
    {
        $services = $this->publicServices($request);
        $customer = $request->attribute('customer');
        $record = $customer instanceof CustomerAccount ? $services->orders()->customerForAccount($customer->id) : null;

        $product = $services->catalog()->getProduct((string) $request->param('slug', ''), ['public' => true, 'tiers' => $record !== null]);
        if ($product === null) {
            throw HttpException::notFound('That product could not be found.');
        }

        foreach ($product['variants'] as &$variant) {
            $variant['tiers'] = array_values(array_filter($variant['tiers'], static fn (array $tier): bool => match ($tier['basis']) {
                'quantity' => true,
                'customer_tier' => $record !== null && strcasecmp((string) $tier['tier_key'], (string) ($record['tier'] ?? '')) === 0,
                'customer_type' => $record !== null && strcasecmp((string) $tier['tier_key'], (string) ($record['customer_type'] ?? '')) === 0,
                default => false,
            }));
        }
        unset($variant);

        return Response::ok($product);
    }

    /** GET /{org}/api/v1/catalog/categories */
    public function categories(Request $request): Response
    {
        return Response::ok(['categories' => $this->publicServices($request)->catalog()->categoryTree()]);
    }

    /** GET /{org}/api/v1/catalog/brands */
    public function brands(Request $request): Response
    {
        $services = $this->publicServices($request);
        $query = ListQuery::fromRequest($request, ['name' => 'b.name'], 'name');

        return Response::ok($services->catalog()->listBrands($query, false));
    }
}
