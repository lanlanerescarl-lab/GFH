<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

/**
 * Appends one line per event to a log file: time, level, request id, message and a JSON context.
 * Stack traces and other technical details go here, never to the user.
 */
final class Logger
{
    private const LEVELS = ['debug' => 100, 'info' => 200, 'warning' => 300, 'error' => 400];

    private int $threshold;
    private string $requestId = '-';

    public function __construct(private readonly string $file, string $minimumLevel = 'warning')
    {
        $this->threshold = self::LEVELS[strtolower($minimumLevel)] ?? self::LEVELS['warning'];
    }

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context): void
    {
        if (self::LEVELS[$level] < $this->threshold) {
            return;
        }

        $json = '';
        if ($context !== []) {
            $encoded = json_encode(
                $context,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
            );
            $json = $encoded === false ? '' : ' ' . $encoded;
        }

        $line = sprintf(
            "%s %s [%s] %s%s\n",
            (new \DateTimeImmutable('now'))->format('c'),
            strtoupper($level),
            $this->requestId,
            str_replace(["\r", "\n"], ' ', $message),
            $json,
        );

        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        // If the log file cannot be written, fall back to PHP's own error log so nothing is lost.
        if (@file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }
}
