<?php

declare(strict_types=1);

namespace Gfh\Core\Support;

/**
 * Product attributes that belong to an extension, not to the catalog.
 *
 * The product record has one place for per-product data an extension can use without a migration of its own: the
 * attributes JSON. Core pages treat a key that starts with "ext_" as private to extensions, so a note kept there
 *
 *   - is not an editable row in the Admin attribute list, and it is not lost when that list is saved;
 *   - is not a row in the Shop's attribute table and not a filter in the Shop's filter list;
 *   - stays in the record when the extension is switched off, and is simply not shown by anything.
 *
 * Only the extension that owns a key reads it (through the hook context) and writes it (through product.form.save).
 * It is not a place for anything secret: the public product answer carries these keys to the extension's hooks.
 */
final class ExtensionAttributes
{
    public const PREFIX = 'ext_';

    public static function isExtensionKey(string $key): bool
    {
        return str_starts_with($key, self::PREFIX);
    }

    /**
     * The attributes the catalog shows and edits (everything that is not extension-owned).
     *
     * @param array<array-key, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function visible(array $attributes): array
    {
        return array_filter($attributes, static fn (string|int $key): bool => !self::isExtensionKey((string) $key), ARRAY_FILTER_USE_KEY);
    }

    /**
     * The extension-owned attributes only.
     *
     * @param array<array-key, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function hidden(array $attributes): array
    {
        return array_filter($attributes, static fn (string|int $key): bool => self::isExtensionKey((string) $key), ARRAY_FILTER_USE_KEY);
    }
}
