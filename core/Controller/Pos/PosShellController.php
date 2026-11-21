<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Pos;

use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Tenant;

/**
 * The pages of the POS app: the one-page shell, and a sign-in that looks like the POS.
 *
 * The shell is only a frame. Everything the cashier does goes through the POS JSON API, which asks can() itself,
 * and through the same AuthService and PricingService and PosService as the other apps. This controller reads
 * input, checks permission, calls a service and returns a response; it holds no rule and no SQL.
 *
 * It does not use RequireStaffMiddleware, because that middleware sends a signed-out visitor to the Admin
 * sign-in. A cashier must land on the POS sign-in instead, so the controller does that redirect itself.
 */
final class PosShellController
{
    public function __construct(private readonly Services $services)
    {
    }

    /** GET /{org}/pos */
    public function shell(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $staff = $request->attribute('staff');
        if (!$staff instanceof StaffUser) {
            return Response::redirect($this->url($request, $tenant, '/pos/login'));
        }

        // Signed in but not allowed to use the POS (for example Staff): the 403 page, not the sign-in again.
        $this->services->permissions()->assert($staff, $tenant->organization, 'pos', 'view');

        $slug = $tenant->organization->slug;
        $config = [
            'organization' => $tenant->organization->name,
            // The id is not a secret (the sign-in response and /auth/staff/me return it); the app compares it with
            // the owner of an open shift to tell "my shift" from "another cashier's shift".
            'user' => ['id' => $staff->id, 'name' => $staff->fullName],
            'csrf' => $this->csrfToken($request),
            'apiBase' => $this->url($request, $tenant, '/api/v1'),
            'posBase' => $this->url($request, $tenant, '/pos'),
            'mediaBase' => $request->basePath() . '/media/' . $slug,
            'loginUrl' => $this->url($request, $tenant, '/pos/login'),
            'receiptTemplate' => 'receipt-template',
        ];

        $view = $this->services->appView($tenant, 'pos', $request);

        return Response::html($view->render('shell', [
            // Escaped for use inside <script type="application/json">: a "</script>" in a name cannot end the block.
            'configJson' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'logoutUrl' => $this->url($request, $tenant, '/pos/logout'),
            'csrf' => $config['csrf'],
        ]));
    }

    /** GET /{org}/pos/login */
    public function loginForm(Request $request): Response
    {
        $staff = $request->attribute('staff');
        $tenant = $this->tenant($request);

        // Already signed in with access: go straight to the app.
        if ($staff instanceof StaffUser && $this->services->permissions()->activeMembership($staff, $tenant->organization) !== null) {
            return Response::redirect($this->url($request, $tenant, '/pos'));
        }

        return $this->loginPage($request, $tenant);
    }

    /** POST /{org}/pos/login: the same checks, lockout and message as the Admin sign-in. */
    public function loginSubmit(Request $request): Response
    {
        $tenant = $this->tenant($request);

        try {
            $user = $this->services->auth()->authenticateStaff($this->text($request, 'email'), $this->text($request, 'password'), $request->ip());
            $this->services->tenants()->authorizeStaff($tenant, $user);
        } catch (HttpException $exception) {
            if ($exception->status() >= 500) {
                throw $exception;
            }

            return $this->loginPage($request, $tenant, $exception->getMessage(), $exception->status());
        }

        $session = $this->session($request);
        $session->regenerate();
        $session->put('user_id', $user->id);
        $this->services->csrf($session)->rotate();

        return Response::redirect($this->url($request, $tenant, '/pos'));
    }

    /** POST /{org}/pos/logout */
    public function logout(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $this->session($request)->destroy();

        return Response::redirect($this->url($request, $tenant, '/pos/login'));
    }

    private function loginPage(Request $request, Tenant $tenant, ?string $error = null, int $status = 200): Response
    {
        $view = $this->services->appView($tenant, 'pos', $request);

        return Response::html($view->render('login', [
            'action' => $this->url($request, $tenant, '/pos/login'),
            'error' => $error,
            'email' => $this->text($request, 'email'),
            'csrf' => $this->csrfToken($request),
        ]), $status);
    }

    private function url(Request $request, Tenant $tenant, string $path): string
    {
        return $request->basePath() . '/' . $tenant->organization->slug . $path;
    }

    private function csrfToken(Request $request): string
    {
        return $this->services->csrf($this->session($request))->token();
    }

    private function session(Request $request): Session
    {
        $session = $request->attribute('session');
        if (!$session instanceof Session) {
            throw new \LogicException('The POS pages need SessionMiddleware.');
        }

        return $session;
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new \LogicException('The POS pages need TenantMiddleware.');
        }

        return $tenant;
    }

    private function text(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return is_string($value) ? $value : '';
    }
}
