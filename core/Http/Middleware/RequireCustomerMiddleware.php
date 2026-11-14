<?php

declare(strict_types=1);

namespace Gfh\Core\Http\Middleware;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Requires a signed-in customer. Run after SessionMiddleware('customer'). */
final class RequireCustomerMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (!$request->attribute('customer') instanceof CustomerAccount) {
            throw HttpException::unauthorized();
        }

        return $next($request);
    }
}
