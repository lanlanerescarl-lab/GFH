<?php

declare(strict_types=1);

namespace Gfh\Core\Http\Middleware;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;

/** Looks up the organization named in the URL and stores it on the request as the "tenant" attribute. */
final class TenantMiddleware
{
    public function __construct(private readonly Services $services)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $tenant = $this->services->tenants()->resolve((string) $request->param('org'));

        return $next($request->withAttribute('tenant', $tenant));
    }
}
