<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

/** UUID helpers. Version 4 for new ids, version 5 style for ids that must be repeatable (retry-safe references). */
final class Uuid
{
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::format($bytes);
    }

    /** The same namespace and name always give the same id. Used so a retried upload cannot create a second stock movement. */
    public static function derive(string $namespace, string $name): string
    {
        $hash = sha1(strtolower($namespace) . '|' . $name, true);
        $bytes = substr($hash, 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::format($bytes);
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($value)) === 1;
    }

    private static function format(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
