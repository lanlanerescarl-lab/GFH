<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Pos;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Tenant;
use Gfh\Core\View\PosServiceWorker;

/**
 * GET /{org}/pos/sw.js: the POS service worker.
 *
 * It needs no session: the script holds no secret (no CSRF token, no name, no session value), and a browser fetches it
 * to check for updates without any page around it. The tenant middleware still runs, so an unknown organization is a 404.
 *
 * The page lives at /{org}/pos (no trailing slash). A script at /{org}/pos/sw.js would by default control only
 * /{org}/pos/..., so the response carries Service-Worker-Allowed with the scope /{org}/pos and the page registers with
 * that same explicit scope.
 *
 * Cache-Control is no-cache, so the browser asks again each time and finds out about a new version; the worker's own
 * caches (named after the files' contents) decide what is kept.
 */
final class PosServiceWorkerController
{
    public function __construct(private readonly Services $services)
    {
    }

    public function script(Request $request): Response
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new \LogicException('The service worker route needs TenantMiddleware.');
        }

        $worker = new PosServiceWorker($this->services->app()->path(), $request->basePath(), $tenant->organization->slug);

        return Response::bytes($worker->source(), 'application/javascript; charset=UTF-8', 200, [
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => $worker->scope(),
        ]);
    }
}
