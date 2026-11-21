<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Shop;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** GET /{org}/shop/products/{slug}: one product. An unknown or inactive product is a 404 page. */
final class ProductController extends ShopPageController
{
    public function show(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $services = $this->services->forTenant($tenant);

        $variant = $request->query()['variant'] ?? null;
        $page = $services->shop()->product(
            (string) $request->param('slug', ''),
            $this->customerRecord($request, $services),
            is_string($variant) ? $variant : null,
        );

        if ($page === null) {
            return $this->notFound($request, $tenant, 'That product is not available.');
        }

        return $this->render($request, $tenant, 'product', $page);
    }
}
