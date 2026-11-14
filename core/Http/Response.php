<?php

declare(strict_types=1);

namespace Gfh\Core\Http;

/**
 * One outgoing HTTP response. Immutable: the with*() methods return a copy.
 * Every response carries safe default headers. The Content-Security-Policy only allows
 * resources from the same origin, which also guarantees that no page calls an external host.
 */
final class Response
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @var array<string, array{0: string, 1: string}> lower-case name => [name, value] */
    private array $headers = [];

    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        array $headers = [],
    ) {
        foreach (self::defaultHeaders() as $name => $value) {
            $this->store($name, $value);
        }
        foreach ($headers as $name => $value) {
            $this->store($name, $value);
        }
    }

    /** @return array<string, string> */
    private static function defaultHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; "
                . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
        ];
    }

    private function store(string $name, string $value): void
    {
        // Refuse line breaks so a user-supplied value can never inject another header.
        if (preg_match('/[\r\n]/', $name . $value) === 1) {
            throw new \InvalidArgumentException('Header names and values cannot contain line breaks.');
        }

        $this->headers[strtolower($name)] = [$name, $value];
    }

    /** @param array<string, string> $headers */
    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers);
    }

    /** @param array<string, string> $headers */
    public static function text(string $text, int $status = 200, array $headers = []): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8'] + $headers);
    }

    /**
     * Raw bytes with a content type, for files such as product photos. Pass Cache-Control in $headers to
     * override the no-store default.
     *
     * @param array<string, string> $headers
     */
    public static function bytes(string $bytes, string $contentType, int $status = 200, array $headers = []): self
    {
        return new self($bytes, $status, ['Content-Type' => $contentType] + $headers);
    }

    /** A 304 reply with no body, for a browser that already has the current file. @param array<string, string> $headers */
    public static function notModified(array $headers = []): self
    {
        return new self('', 304, $headers);
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $payload, int $status = 200, array $headers = []): self
    {
        return new self(
            json_encode($payload, self::JSON_FLAGS),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'] + $headers,
        );
    }

    /** Success envelope: {"ok": true, "data": ...} */
    public static function ok(mixed $data = null, int $status = 200): self
    {
        return self::json(['ok' => true, 'data' => $data], $status);
    }

    /**
     * Failure envelope: {"ok": false, "error": {"code", "message", "fields"}}
     *
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public static function error(string $code, string $message, int $status, array $fields = [], array $headers = []): self
    {
        return self::json([
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                // An empty PHP array would encode as [], but the contract says fields is an object.
                'fields' => $fields === [] ? new \stdClass() : $fields,
            ],
        ], $status, $headers);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public function withHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->store($name, $value);

        return $copy;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        return ($this->headers[strtolower($name)] ?? [null, null])[1];
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        $result = [];
        foreach ($this->headers as [$name, $value]) {
            $result[$name] = $value;
        }

        return $result;
    }

    /** Sends the status, headers and body. HEAD requests get the headers only. */
    public function send(bool $omitBody = false): void
    {
        $hasBody = !in_array($this->status, [204, 304], true);

        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as [$name, $value]) {
                header($name . ': ' . $value, true);
            }
            if ($hasBody) {
                header('Content-Length: ' . strlen($this->body), true);
            }
        }

        if ($hasBody && !$omitBody) {
            echo $this->body;
        }
    }
}
