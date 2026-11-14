<?php

declare(strict_types=1);

namespace Gfh\Core\Http\Middleware;

use Gfh\Core\Auth\Csrf;
use Gfh\Core\Auth\Session;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/**
 * Rejects state-changing requests (anything but GET, HEAD and OPTIONS) that do not carry the session's CSRF token,
 * in the X-CSRF-Token header or the _csrf form field. Must run after SessionMiddleware.
 */
final class CsrfMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $session = $request->attribute('session');
            if (!$session instanceof Session) {
                throw new \LogicException('CsrfMiddleware needs SessionMiddleware before it.');
            }

            $submitted = $request->header('x-csrf-token') ?? $request->input('_csrf');
            if (!(new Csrf($session))->verify(is_string($submitted) ? $submitted : null)) {
                throw HttpException::csrfMismatch();
            }
        }

        return $next($request);
    }
}
