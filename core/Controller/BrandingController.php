<?php

declare(strict_types=1);

namespace Gfh\Core\Controller;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Tenancy\Tenant;
use Gfh\Core\View\BrandingCss;

/**
 * GET /{org}/branding.css: the organization's colors, fonts and corner radii as CSS variables.
 * Public on purpose (it is a stylesheet), carries no data beyond the values BrandingCss accepts,
 * and does not open the organization database.
 */
final class BrandingController
{
    public function css(Request $request): Response
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new \LogicException('This route needs TenantMiddleware.');
        }

        $built = BrandingCss::build($tenant->organization->branding);

        // A short cache keeps page loads fast while a branding change still shows within minutes.
        return Response::bytes($built['css'], 'text/css; charset=UTF-8', 200, ['Cache-Control' => 'public, max-age=300']);
    }
}
