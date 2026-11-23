<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Shop;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Auth\Session;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Core\Support\ShopRedirect;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Tenancy\Tenant;

/**
 * Shared base of the public Shop page controllers. A Shop page needs the organization (TenantMiddleware) and, if the
 * visitor already has a customer session, that customer (the optional session middleware), so guests can browse and
 * signed-in customers see their tier prices. Controllers read the request, call the services and hand the result
 * to a template; they hold no SQL and no business rule.
 */
abstract class ShopPageController
{
    private const TAGLINE_MAX = 160;

    public function __construct(protected readonly Services $services)
    {
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new \LogicException('This page needs TenantMiddleware.');
        }

        return $tenant;
    }

    /** The organization's customer record for a signed-in customer, or null for a guest (used for tier prices). */
    protected function customerRecord(Request $request, TenantServices $services): ?array
    {
        $customer = $request->attribute('customer');

        return $customer instanceof CustomerAccount ? $services->orders()->customerForAccount($customer->id) : null;
    }

    protected function shopUrl(Request $request, Tenant $tenant, string $path = ''): string
    {
        return $request->basePath() . '/' . $tenant->organization->slug . '/shop' . $path;
    }

    /** The signed-in customer's account, or null for a guest. */
    protected function customerAccount(Request $request): ?CustomerAccount
    {
        $customer = $request->attribute('customer');

        return $customer instanceof CustomerAccount ? $customer : null;
    }

    /** The session's CSRF token for forms and scripts, or "" when the visitor has no session (a guest on a public page). */
    protected function csrfToken(Request $request): string
    {
        $session = $request->attribute('session');

        return $session instanceof Session ? $this->services->csrf($session)->token() : '';
    }

    /**
     * The "next" value to carry to sign-in: this page's own address, but only if ShopRedirect accepts it.
     * A page that is not safe to come back to (it cannot be, for a path inside the Shop) simply gets no "next".
     */
    protected function currentNext(Request $request, Tenant $tenant): ?string
    {
        $query = $request->query();
        $address = $request->basePath() . $request->path() . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        return ShopRedirect::safeNext($address, $this->shopUrl($request, $tenant));
    }

    /** Where a form's "next" field (or query value) leads, or the Shop home when it is missing or not acceptable. */
    protected function nextOrHome(Request $request, Tenant $tenant): string
    {
        return ShopRedirect::safeNext($request->input('next'), $this->shopUrl($request, $tenant)) ?? $this->shopUrl($request, $tenant);
    }

    /** The redirect that sends a guest to sign-in and brings them back to this page afterwards. */
    protected function toSignIn(Request $request, Tenant $tenant): Response
    {
        $next = $this->currentNext($request, $tenant);

        return Response::redirect($this->shopUrl($request, $tenant, '/sign-in') . ($next === null ? '' : '?next=' . rawurlencode($next)));
    }

    /** Pages that show one customer's data must never be stored by a browser or a shared cache. */
    protected function noStore(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $data */
    protected function render(Request $request, Tenant $tenant, string $template, array $data = [], int $status = 200): Response
    {
        $basePath = $request->basePath();
        $slug = $tenant->organization->slug;
        $search = $request->query()['q'] ?? '';

        $view = $this->services->appView($tenant, 'shop', $request, [
            'navigation' => $this->services->shopNavigation($tenant)->items($basePath, $slug, $basePath . $request->path()),
            'productsUrl' => $this->shopUrl($request, $tenant, '/products'),
            'searchText' => is_string($search) ? mb_substr(trim($search), 0, 100) : '',
            'mediaUrl' => $basePath . '/media/' . $slug,
            // The header's Cart, Sign in, Register, My orders and Sign out links, and what the page scripts need.
            'shopAccount' => [
                'signedIn' => $this->customerAccount($request) !== null,
                'csrf' => $this->csrfToken($request),
                'cartUrl' => $this->shopUrl($request, $tenant, '/cart'),
                'signInUrl' => $this->shopUrl($request, $tenant, '/sign-in'),
                'registerUrl' => $this->shopUrl($request, $tenant, '/register'),
                'ordersUrl' => $this->shopUrl($request, $tenant, '/orders'),
                'signOutUrl' => $this->shopUrl($request, $tenant, '/sign-out'),
                'apiBase' => $basePath . '/' . $slug . '/api/v1',
                'slug' => $slug,
            ],
        ]);

        return Response::html($view->render($template, $data), $status);
    }

    /** The Shop's own "not found" page, inside the Shop layout (so the visitor can carry on browsing). */
    protected function notFound(Request $request, Tenant $tenant, string $message): Response
    {
        return $this->render($request, $tenant, 'not-found', ['message' => $message], 404);
    }

    /** A short line for the home page hero from the organization's registry settings; empty when none is set. */
    protected function tagline(Tenant $tenant): string
    {
        $value = $tenant->organization->settings['shop_tagline'] ?? '';

        return is_string($value) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, self::TAGLINE_MAX) : '';
    }
}
