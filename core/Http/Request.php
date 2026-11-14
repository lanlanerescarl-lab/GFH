<?php

declare(strict_types=1);

namespace Gfh\Core\Http;

/**
 * One incoming HTTP request. Built once from the PHP globals (or from arrays in tests).
 * The path is normalised: decoded, duplicate slashes collapsed, trailing slash removed,
 * and the folder the app is installed in (if any) removed, so routes always see "/health".
 */
final class Request
{
    /** JSON bodies larger than this are refused. File uploads do not pass through here. */
    public const MAX_BODY_BYTES = 1048576;

    /** @var array<string, string> lower-case header name => value */
    private array $headers = [];
    private string $path;
    private string $basePath;
    private string $id;
    private ?string $rawBody = null;
    /** @var array<string, mixed>|null */
    private ?array $parsedBody = null;
    /** @var array<string, string> */
    private array $params = [];
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        private readonly string $method,
        string $uri,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly array $server = [],
        private readonly ?\Closure $bodyReader = null,
    ) {
        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $this->headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $this->headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        $this->id = bin2hex(random_bytes(4));
        $this->basePath = self::detectBasePath((string) ($server['SCRIPT_NAME'] ?? ''));
        $this->path = self::normalisePath($uri, $this->basePath);
    }

    public static function fromGlobals(): self
    {
        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES,
            $_SERVER,
            static fn (): string => (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1),
        );
    }

    private static function detectBasePath(string $scriptName): string
    {
        $directory = str_replace('\\', '/', dirname($scriptName));

        return ($directory === '.' || $directory === '/') ? '' : rtrim($directory, '/');
    }

    private static function normalisePath(string $uri, string $basePath): string
    {
        // Cut at the first ? or # by hand; parse_url() mishandles paths that start with "//".
        $path = (string) strtok($uri, '?#');
        $path = rawurldecode($path);

        // Control characters or invalid UTF-8 can never match a route; make sure the router answers 404.
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || preg_match('//u', $path) !== 1) {
            return '/_invalid';
        }

        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }

        $path = (string) preg_replace('#/{2,}#', '/', $path);

        return '/' . trim($path, '/');
    }

    /** Unique id for this request. Shown on error pages, sent as X-Request-Id and written to the log. */
    public function id(): string
    {
        return $this->id;
    }

    public function method(): string
    {
        return strtoupper($this->method);
    }

    /** Path without the install folder, for example "/fnb/shop". */
    public function path(): string
    {
        return $this->path;
    }

    /** Folder the app is installed in, "" when it runs at the root (as with php -S). Prefix it to every URL you build. */
    public function basePath(): string
    {
        return $this->basePath;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->query;
    }

    /** @return array<string, mixed> */
    public function files(): array
    {
        return $this->files;
    }

    /** True when the client sent a JSON body. */
    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type') ?? ''), 'json');
    }

    /**
     * Whether errors should be returned as the JSON envelope instead of an HTML page:
     * API and health routes, or a client that asks for JSON and not HTML.
     */
    public function wantsJson(): bool
    {
        if (preg_match('#^/(?:health|(?:[^/]+/)?api)(?:/|$)#', $this->path) === 1) {
            return true;
        }

        $accept = strtolower($this->header('accept') ?? '');

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    public function rawBody(): string
    {
        if ($this->rawBody === null) {
            $declared = (int) ($this->header('content-length') ?? 0);
            if ($declared > self::MAX_BODY_BYTES) {
                throw HttpException::payloadTooLarge();
            }

            $body = $this->bodyReader !== null ? ($this->bodyReader)() : '';
            if (strlen($body) > self::MAX_BODY_BYTES) {
                throw HttpException::payloadTooLarge();
            }

            $this->rawBody = $body;
        }

        return $this->rawBody;
    }

    /**
     * The submitted data: the decoded JSON object for JSON requests, otherwise the form fields.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        if ($this->parsedBody === null) {
            $this->parsedBody = $this->isJson() ? $this->decodeJson() : $this->post;
        }

        return $this->parsedBody;
    }

    /**
     * Query string and body together; the body wins when a name appears in both.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body());
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @return array<string, mixed> */
    private function decodeJson(): array
    {
        $raw = trim($this->rawBody());
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw HttpException::badRequest('The request body is not valid JSON.');
        }

        if (!is_array($decoded)) {
            throw HttpException::badRequest('The request body must be a JSON object.');
        }

        return $decoded;
    }

    /** Address of the connecting client. X-Forwarded-For is deliberately ignored until trusted proxies are configured. */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));

        return ($https !== '' && $https !== 'off') || (string) ($this->server['SERVER_PORT'] ?? '') === '443';
    }

    /**
     * Copy of this request with the route parameters filled in (set by the Router).
     *
     * @param array<string, string> $params
     */
    public function withParams(array $params): self
    {
        $copy = clone $this;
        $copy->params = $params;

        return $copy;
    }

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->params[$name] ?? $default;
    }

    /** Copy with one attribute set. Middleware uses this to hand data (such as the resolved organization) to controllers. */
    public function withAttribute(string $name, mixed $value): self
    {
        $copy = clone $this;
        $copy->attributes[$name] = $value;

        return $copy;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }
}
