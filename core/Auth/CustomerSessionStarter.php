<?php

declare(strict_types=1);

namespace Gfh\Core\Auth;

/**
 * Starts a signed-in customer session. The JSON sign-in and the Shop's sign-in page both call this, so the two cannot
 * differ: a new session id (against session fixation), the customer's id in the session, and a fresh CSRF token.
 */
final class CustomerSessionStarter
{
    /** @return string the new CSRF token */
    public static function start(Session $session, Csrf $csrf, CustomerAccount $account): string
    {
        $session->regenerate();
        $session->put('user_id', $account->id);

        return $csrf->rotate();
    }
}
