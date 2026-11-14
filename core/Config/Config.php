<?php

declare(strict_types=1);

namespace Gfh\Core\Config;

/**
 * Read-only settings from the .env file in the project root.
 * Real environment variables win over the file, so a host can override a value
 * without editing .env. Values are always strings; use the typed getters.
 */
final class Config
{
    /** Philippine time. Fixed on purpose: every date in the system is shown in this zone. */
    public const TIMEZONE = 'Asia/Manila';

    /**
     * @param array<string, string> $values
     */
    public function __construct(
        private readonly array $values = [],
        private readonly bool $readEnvironment = false,
    ) {
    }

    public static function load(string $rootPath): self
    {
        $file = rtrim($rootPath, '/\\') . '/.env';
        $values = [];

        if (is_file($file)) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new ConfigException('The .env file exists but cannot be read.');
            }
            $values = self::parseEnv($contents);
        }

        return new self($values, true);
    }

    /**
     * Parses KEY=value lines. Supports comments, "double quoted" values with \n \r \" \\ \$ escapes,
     * 'single quoted' literal values, and an optional "export " prefix. There is no variable expansion.
     *
     * @return array<string, string>
     */
    public static function parseEnv(string $contents): array
    {
        // Editors on Windows sometimes save a byte order mark at the start of the file.
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $values = [];
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            if (preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $match) !== 1) {
                throw new ConfigException(sprintf('Invalid line %d in .env: expected KEY=value.', $index + 1));
            }

            $values[$match[1]] = self::parseValue($match[2], $index + 1);
        }

        return $values;
    }

    private static function parseValue(string $raw, int $lineNumber): string
    {
        if ($raw === '') {
            return '';
        }

        if ($raw[0] === '"') {
            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*(?:#.*)?$/s', $raw, $match) !== 1) {
                throw new ConfigException(sprintf('Invalid quoted value on line %d of .env.', $lineNumber));
            }

            $map = ['n' => "\n", 'r' => "\r", '"' => '"', '\\' => '\\', '$' => '$'];

            return (string) preg_replace_callback(
                '/\\\\(["\\\\nr$])/',
                static fn (array $m): string => $map[$m[1]],
                $match[1],
            );
        }

        if ($raw[0] === "'") {
            if (preg_match('/^\'([^\']*)\'\s*(?:#.*)?$/', $raw, $match) !== 1) {
                throw new ConfigException(sprintf('Invalid quoted value on line %d of .env.', $lineNumber));
            }

            return $match[1];
        }

        // Unquoted: a space followed by # starts a comment.
        return trim((string) preg_replace('/\s+#.*$/', '', $raw));
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if ($this->readEnvironment) {
            $fromEnvironment = getenv($key);
            if (is_string($fromEnvironment)) {
                return $fromEnvironment;
            }
        }

        return $this->values[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        return $this->get($key) ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null || trim($value) === '') {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        $parsed = filter_var(trim($value), FILTER_VALIDATE_INT);

        return $parsed === false ? $default : $parsed;
    }

    /** A setting that must be present and non-empty. */
    public function required(string $key): string
    {
        $value = $this->get($key);
        if ($value === null || trim($value) === '') {
            throw new ConfigException(sprintf('Missing required setting %s. Copy .env.example to .env and set it.', $key));
        }

        return $value;
    }

    /** local, testing or production. A missing or misspelled value counts as production, the safe choice. */
    public function environment(): string
    {
        $value = strtolower(trim($this->string('APP_ENV', 'production')));

        return in_array($value, ['local', 'testing'], true) ? $value : 'production';
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    /** Debug output can never be switched on in production. */
    public function debug(): bool
    {
        return !$this->isProduction() && $this->bool('APP_DEBUG', false);
    }
}
