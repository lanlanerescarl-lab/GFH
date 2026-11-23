<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

/**
 * Decides where a visitor may be sent after signing in. Only a path inside this organization's own Shop is accepted
 * (it must start with "{shop base}/"), so a crafted link cannot send a customer to another site, to the Admin or to
 * a page that would loop straight back. Everything else is ignored and the caller uses its default.
 *
 * The rule lives here, in one function, so the sign-in page, the registration page and the tests all share it.
 */
final class ShopRedirect
{
    private const MAX_LENGTH = 300;

    /** Pages that must never be a destination: sign-in and registration send a signed-in visitor on to "next". */
    private const NEVER = ['/sign-in', '/register', '/sign-out'];

    /**
     * @param mixed  $candidate the raw "next" value from the request
     * @param string $shopBase  the Shop's own path, such as "/demo/shop" (install folder included)
     * @return string|null the candidate when it is safe, otherwise null
     */
    public static function safeNext(mixed $candidate, string $shopBase): ?string
    {
        if (!is_string($candidate) || $candidate === '' || strlen($candidate) > self::MAX_LENGTH) {
            return null;
        }

        // A short allow-list of characters. This alone rules out backslashes, spaces, control characters, "#", "@",
        // ":" (so no scheme) and every non-ASCII look-alike.
        if (preg_match('#^/[A-Za-z0-9/_\-.~?=&%+]*$#', $candidate) !== 1) {
            return null;
        }
        if (!str_starts_with($candidate, $shopBase . '/')) {
            return null;
        }

        // Percent-encoding must not hide anything: look at the decoded text (twice, for double encoding).
        $decoded = $candidate;
        for ($round = 0; $round < 3; ++$round) {
            $decoded = rawurldecode($decoded);
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded) === 1 || str_contains($decoded, '//') || str_contains($decoded, '..')) {
            return null;
        }
        if (!str_starts_with($decoded, $shopBase . '/')) {
            return null;
        }

        $path = explode('?', $candidate, 2)[0];
        foreach (self::NEVER as $suffix) {
            if ($path === $shopBase . $suffix) {
                return null;
            }
        }

        return $candidate;
    }
}
