<?php

declare(strict_types=1);

namespace Gfh\Core\Http;

/**
 * An error the user is allowed to see. Controllers and services throw it;
 * the ErrorHandler turns it into an HTML page or a JSON error envelope.
 * Any other exception is treated as a server fault and shown as a generic message.
 */
final class HttpException extends \RuntimeException
{
    /**
     * @param array<string, string> $fields  field name => message, for validation errors
     * @param array<string, string> $headers extra response headers (for example Allow)
     */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly array $fields = [],
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @param array<string, string> $fields */
    public static function badRequest(string $message = 'The request could not be understood.', array $fields = []): self
    {
        return new self(400, 'bad_request', $message, $fields);
    }

    public static function unauthorized(string $message = 'Please sign in to continue.'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do that.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function csrfMismatch(): self
    {
        return new self(403, 'csrf_mismatch', 'Your session could not be verified. Reload the page and try again.');
    }

    public static function notFound(string $message = 'The page you asked for could not be found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(
            405,
            'method_not_allowed',
            'That action is not available at this address.',
            [],
            ['Allow' => implode(', ', $allowed)],
        );
    }

    /** @param array<string, string> $fields */
    public static function conflict(string $message, array $fields = []): self
    {
        return new self(409, 'conflict', $message, $fields);
    }

    public static function payloadTooLarge(): self
    {
        return new self(413, 'payload_too_large', 'The request is too large.');
    }

    /** @param array<string, string> $fields */
    public static function unprocessable(array $fields, string $message = 'Please correct the highlighted fields.'): self
    {
        return new self(422, 'validation_failed', $message, $fields);
    }

    public static function tooManyRequests(int $retryAfterSeconds, string $message = 'Too many attempts. Please wait and try again.'): self
    {
        return new self(429, 'too_many_requests', $message, [], ['Retry-After' => (string) max(1, $retryAfterSeconds)]);
    }
}
