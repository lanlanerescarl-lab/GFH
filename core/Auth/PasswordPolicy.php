<?php

declare(strict_types=1);

namespace Gfh\Core\Auth;

use Gfh\Core\Http\HttpException;

/** Password rules and hashing. Passwords are stored only as password_hash() output. */
final class PasswordPolicy
{
    public const MIN_BYTES = 10;
    /** bcrypt ignores everything after 72 bytes, so longer passwords are refused rather than silently cut. */
    public const MAX_BYTES = 72;

    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm());
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm());
    }

    /** @throws HttpException 422 with a message on the password field */
    public function assertValid(string $password, ?string $email = null, string $field = 'password'): void
    {
        $length = strlen($password);

        if ($length < self::MIN_BYTES) {
            throw HttpException::unprocessable([$field => sprintf('Use at least %d characters.', self::MIN_BYTES)]);
        }
        if ($length > self::MAX_BYTES) {
            throw HttpException::unprocessable([$field => sprintf('Use at most %d characters.', self::MAX_BYTES)]);
        }
        if (trim($password) === '') {
            throw HttpException::unprocessable([$field => 'The password cannot be only spaces.']);
        }
        if ($email !== null && strtolower($password) === strtolower($email)) {
            throw HttpException::unprocessable([$field => 'The password cannot be your email address.']);
        }
    }

    private function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
