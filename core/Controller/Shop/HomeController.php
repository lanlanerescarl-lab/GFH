<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Shop;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** GET /{org}/shop: the Shop home page. */
final class HomeController extends ShopPageController
{
    public function index(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $home = $this->services->forTenant($tenant)->shop()->home();

        return $this->render($request, $tenant, 'home', [
            'tagline' => $this->tagline($tenant),
            'categories' => $home['categories'],
            'newest' => $home['newest'],
        ]);
    }
}
