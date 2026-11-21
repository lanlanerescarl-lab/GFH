<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Shop;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\ShopQuery;

/** GET /{org}/shop/products: the product listing with search, filters, sorting and pagination. */
final class ProductListController extends ShopPageController
{
    public function index(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $query = ShopQuery::fromRequest($request);
        $listing = $this->services->forTenant($tenant)->shop()->listing($query);

        return $this->render($request, $tenant, 'products', [
            'query' => $query,
            'listing' => $listing,
        ]);
    }
}
