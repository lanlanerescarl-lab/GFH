<?php

declare(strict_types=1);

namespace Gfh\Core\Auth;

/**
 * Cross-site request forgery protection. Each session holds one random token. Forms send it as the hidden
 * field _csrf, and JavaScript sends it in the X-CSRF-Token header. Every state-changing request is checked.
 */
final class Csrf
{
    private const KEY = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->put(self::KEY, $token);
        }

        return $token;
    }

    public function verify(?string $submitted): bool
    {
        $token = $this->session->get(self::KEY);

        return is_string($token) && $token !== '' && is_string($submitted) && hash_equals($token, $submitted);
    }

    /** A fresh token, used after sign-in. */
    public function rotate(): string
    {
        $this->session->forget(self::KEY);

        return $this->token();
    }
}
