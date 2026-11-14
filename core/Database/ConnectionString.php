<?php

declare(strict_types=1);

namespace Gfh\Core\Database;

/**
 * A validated PostgreSQL connection: host, port, database, user, password and ssl mode.
 * Parsed from a URL such as postgresql://user:pass@host:5432/dbname?sslmode=require.
 * Special characters in the user name or password must be percent-encoded (for example @ becomes %40).
 * Error messages never repeat the URL, because it contains a password.
 */
final class ConnectionString
{
    private const SSL_MODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $user,
        public readonly string $password,
        public readonly string $sslMode,
    ) {
    }

    public static function fromParts(
        string $host,
        int $port,
        string $database,
        string $user,
        string $password,
        string $sslMode = 'prefer',
    ): self {
        // Strict patterns also keep these values safe to place inside a PDO DSN.
        if (preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $host) !== 1) {
            throw new \InvalidArgumentException('The database host is missing or contains characters that are not allowed.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('The database port must be between 1 and 65535.');
        }
        if (preg_match('/^[A-Za-z0-9_.\-]{1,63}$/', $database) !== 1) {
            throw new \InvalidArgumentException('The database name is missing or contains characters that are not allowed.');
        }
        if ($user === '') {
            throw new \InvalidArgumentException('The database user is missing.');
        }
        if (!in_array($sslMode, self::SSL_MODES, true)) {
            throw new \InvalidArgumentException('The sslmode must be one of: ' . implode(', ', self::SSL_MODES) . '.');
        }

        return new self($host, $port, $database, $user, $password, $sslMode);
    }

    public static function parse(string $url): self
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['postgresql', 'postgres', 'pgsql'], true)) {
            throw new \InvalidArgumentException(
                'The connection string must look like postgresql://user:password@host:5432/database?sslmode=require.',
            );
        }

        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $name) {
            if ($name !== 'sslmode') {
                throw new \InvalidArgumentException('Only the sslmode option is supported in a connection string.');
            }
        }

        return self::fromParts(
            (string) ($parts['host'] ?? ''),
            (int) ($parts['port'] ?? 5432),
            ltrim((string) ($parts['path'] ?? ''), '/'),
            rawurldecode((string) ($parts['user'] ?? '')),
            rawurldecode((string) ($parts['pass'] ?? '')),
            is_string($query['sslmode'] ?? null) ? $query['sslmode'] : 'prefer',
        );
    }

    /** The PDO DSN. User and password are passed to PDO separately. */
    public function dsn(): string
    {
        return sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $this->host, $this->port, $this->database, $this->sslMode);
    }

    /** Normalised URL, with the user and password percent-encoded. This is what gets encrypted and stored. */
    public function toUrl(): string
    {
        return sprintf(
            'postgresql://%s:%s@%s:%d/%s?sslmode=%s',
            rawurlencode($this->user),
            rawurlencode($this->password),
            $this->host,
            $this->port,
            $this->database,
            $this->sslMode,
        );
    }

    /** Safe text for logs and messages: no password. */
    public function describe(): string
    {
        return sprintf('%s@%s:%d/%s', $this->user, $this->host, $this->port, $this->database);
    }
}
