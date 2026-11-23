<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Shop;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/**
 * The cart and checkout pages. Both are shells: the browser keeps the cart, and a page script asks the JSON API
 * (the same one a phone app would use) to price it and to place the order. Nothing about prices, stock or fees is
 * decided or even known here.
 */
final class CartPagesController extends ShopPageController
{
    /** GET /{org}/shop/cart. Open to guests; the page script needs the session's CSRF token to ask for a price. */
    public function cart(Request $request): Response
    {
        $tenant = $this->tenant($request);

        return $this->noStore($this->render($request, $tenant, 'cart', [
            'signedIn' => $this->customerAccount($request) !== null,
            'checkoutUrl' => $this->shopUrl($request, $tenant, '/checkout'),
            'productsUrl' => $this->shopUrl($request, $tenant, '/products'),
        ]));
    }

    /** GET /{org}/shop/checkout. A guest signs in first and comes back here. */
    public function checkout(Request $request): Response
    {
        $tenant = $this->tenant($request);
        if ($this->customerAccount($request) === null) {
            return $this->toSignIn($request, $tenant);
        }

        return $this->noStore($this->render($request, $tenant, 'checkout', [
            'cartUrl' => $this->shopUrl($request, $tenant, '/cart'),
            'ordersBase' => $this->shopUrl($request, $tenant, '/orders/'),
            'productsUrl' => $this->shopUrl($request, $tenant, '/products'),
        ]));
    }
}
