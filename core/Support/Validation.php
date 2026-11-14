<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

/** Small server-side checks shared by the services. Each returns the cleaned value, or null when it is not acceptable. */
final class Validation
{
    /** Lower-cased email, or null when it is not a valid address. */
    public static function email(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $email = strtolower(trim($value));

        return (strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) ? $email : null;
    }

    /** A person's name, 2 to 120 characters, without control characters. */
    public static function personName(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $value));
        $length = mb_strlen($name);

        if ($length < 2 || $length > 120 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return null;
        }

        return $name;
    }

    /** A URL-safe slug: lower-case letters, digits and hyphens. */
    public static function slug(string $text): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');

        return $slug === '' ? 'item' : substr($slug, 0, 60);
    }

    /** A Philippine-style phone number: digits, spaces, + - ( ), 7 to 20 characters. Empty means none. */
    public static function phone(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return '';
        }
        if (!is_string($value)) {
            return null;
        }

        $phone = trim($value);

        return preg_match('/^[0-9+\-() ]{7,20}$/', $phone) === 1 ? $phone : null;
    }
}
