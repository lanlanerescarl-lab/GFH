<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

/**
 * Decides where a group user may be sent after signing in. Only a path inside /superadmin
 * is accepted (exact "/superadmin", "/superadmin/...", or "/superadmin?..."), never another
 * host, another app, or the sign-in / sign-out routes themselves.
 */
final class SuperAdminRedirect
{
    private const MAX_LENGTH = 300;

    /** Paths that must never be a post-sign-in destination because they would loop or end the session. */
    private const NEVER = ['/sign-in', '/sign-out'];

    /**
     * @param mixed  $candidate the raw "next" value from the request
     * @param string $base      the Super Admin prefix, such as "/superadmin" (install folder included)
     * @return string|null the candidate when safe, otherwise null
     */
    public static function safeNext(mixed $candidate, string $base): ?string
    {
        if (!is_string($candidate) || $candidate === '' || strlen($candidate) > self::MAX_LENGTH) {
            return null;
        }

        if (preg_match('#^/[A-Za-z0-9/_\-.~?=&%+]*$#', $candidate) !== 1) {
            return null;
        }
        if (!self::withinBase($candidate, $base)) {
            return null;
        }

        // Decode repeatedly so percent-encoded slashes, backslashes, or dot segments cannot bypass checks.
        $decoded = $candidate;
        for ($round = 0; $round < 3; ++$round) {
            $decoded = rawurldecode($decoded);
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded) === 1 || str_contains($decoded, '//') || str_contains($decoded, '..')) {
            return null;
        }
        if (!self::withinBase($decoded, $base)) {
            return null;
        }

        $path = explode('?', $candidate, 2)[0];
        foreach (self::NEVER as $suffix) {
            if ($path === $base . $suffix) {
                return null;
            }
        }

        return $candidate;
    }

    private static function withinBase(string $value, string $base): bool
    {
        return $value === $base
            || str_starts_with($value, $base . '/')
            || str_starts_with($value, $base . '?');
    }
}
