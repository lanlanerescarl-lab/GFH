<?php

declare(strict_types=1);

namespace Gfh\Core\Auth;

use Gfh\Core\Config\Config;

/**
 * One PHP session for one kind of person. Staff and customers use different cookie names (gfh_staff, gfh_customer),
 * so they never share a session. Only an id is kept in the session; the account itself is reloaded from the database
 * on every request, so deactivating someone takes effect immediately. Authentication is never kept in browser storage.
 *
 * Cookies are HttpOnly and SameSite=Lax, and Secure on HTTPS and in production. A session ends after
 * SESSION_IDLE_MINUTES without use or SESSION_ABSOLUTE_HOURS after it began.
 */
final class Session
{
    public const SCOPES = ['staff', 'customer'];

    private bool $started = false;
    /** @var array<string, mixed> */
    private array $memory = [];

    public function __construct(
        private readonly string $scope,
        private readonly Config $config,
        private readonly string $savePath,
        private readonly bool $secureCookie,
        private readonly bool $inMemory = false,
    ) {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('Unknown session scope.');
        }
    }

    /**
     * Test-only session backed by an in-memory array so Router tests in CLI (where PHPUnit has already
     * written output) can still execute SessionMiddleware and CSRF checks without touching $_SESSION or cookies.
     *
     * @param array<string, mixed> $initial
     */
    public static function inMemory(string $scope, Config $config, array $initial = []): self
    {
        $session = new self($scope, $config, sys_get_temp_dir(), false, true);
        $session->memory = $initial;

        return $session;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        if ($this->inMemory) {
            $this->started = true;
            $this->memory['_created'] ??= time();
            $this->memory['_seen'] = time();

            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new \LogicException('Another session is already open in this request.');
        }

        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0770, true);
        }

        $absolute = max(1, $this->config->int('SESSION_ABSOLUTE_HOURS', 12)) * 3600;

        session_name('gfh_' . $this->scope);
        session_save_path($this->savePath);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) $absolute);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new \RuntimeException('The session could not be started.');
        }
        $this->started = true;

        $now = time();
        $idle = max(1, $this->config->int('SESSION_IDLE_MINUTES', 30)) * 60;
        $created = (int) ($_SESSION['_created'] ?? 0);
        $seen = (int) ($_SESSION['_seen'] ?? 0);

        if ($created > 0 && ($now - $created > $absolute || $now - $seen > $idle)) {
            $this->clear();
            session_regenerate_id(true);
        }

        $_SESSION['_created'] ??= $now;
        $_SESSION['_seen'] = $now;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inMemory ? ($this->memory[$key] ?? $default) : ($_SESSION[$key] ?? $default);
    }

    public function put(string $key, mixed $value): void
    {
        if ($this->inMemory) {
            $this->memory[$key] = $value;

            return;
        }

        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        if ($this->inMemory) {
            unset($this->memory[$key]);

            return;
        }

        unset($_SESSION[$key]);
    }

    /** Called right after a successful sign-in: a new id defeats session fixation, and the absolute clock restarts. */
    public function regenerate(): void
    {
        if ($this->inMemory) {
            $this->memory['_created'] = time();
            $this->memory['_seen'] = time();

            return;
        }

        session_regenerate_id(true);
        $_SESSION['_created'] = time();
        $_SESSION['_seen'] = time();
    }

    /** Ends the session and removes the cookie (sign-out). */
    public function destroy(): void
    {
        $this->clear();

        if ($this->inMemory) {
            $this->started = false;

            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        if (!headers_sent()) {
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $this->secureCookie,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $this->started = false;
    }

    private function clear(): void
    {
        if ($this->inMemory) {
            $this->memory = [];

            return;
        }

        $_SESSION = [];
    }
}
