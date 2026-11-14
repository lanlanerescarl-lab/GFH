<?php

declare(strict_types=1);

namespace Gfh\Core\View;

/**
 * Turns an organization's stored branding into a small stylesheet that only sets CSS variables.
 *
 * The branding comes from the registry as JSON, so nothing in it is trusted. Each known key has a strict
 * format, and a value that does not match is dropped (and reported in "ignored") instead of being written
 * into the stylesheet. That makes it impossible to smuggle other CSS in through a color or font name.
 * Colors must also keep enough contrast (4.5 to 1) for white text on the primary colors and for body text
 * on the page background, so branding cannot make a screen unreadable.
 *
 * Keys: primary, primary_strong, background, ink (colors as #RGB or #RRGGBB), font_body, font_heading
 * (a font name, which only shows if that font is vendored under public/assets/fonts), radius_card and
 * radius_control (0 to 24 pixels).
 */
final class BrandingCss
{
    private const COLORS = [
        'primary' => '--color-primary',
        'primary_strong' => '--color-primary-strong',
        'background' => '--color-bg',
        'ink' => '--color-ink',
    ];

    /** key => [variable, fallback stack added after the font name] */
    private const FONTS = [
        'font_body' => ['--font-body', 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif'],
        'font_heading' => ['--font-heading', 'Georgia, "Times New Roman", serif'],
    ];

    private const RADII = [
        'radius_card' => '--radius-card',
        'radius_control' => '--radius-control',
    ];

    // Must match the defaults in public/assets/css/tokens.css.
    private const DEFAULT_INK = '#1a2a3a';
    private const DEFAULT_BACKGROUND = '#eff3ff';
    private const WHITE = '#ffffff';
    private const MIN_CONTRAST = 4.5;
    private const MAX_RADIUS = 24;

    /**
     * @param array<mixed> $branding
     * @return array{css: string, ignored: list<string>} the stylesheet, and the keys that were not used
     */
    public static function build(array $branding): array
    {
        $ignored = [];
        $known = [...array_keys(self::COLORS), ...array_keys(self::FONTS), ...array_keys(self::RADII)];
        foreach (array_keys($branding) as $key) {
            if (!in_array((string) $key, $known, true)) {
                $ignored[] = (string) $key;
            }
        }

        $colors = [];
        foreach (array_keys(self::COLORS) as $key) {
            if (!array_key_exists($key, $branding)) {
                continue;
            }
            $hex = self::normalizeColor($branding[$key]);
            if ($hex === null) {
                $ignored[] = $key;
                continue;
            }
            $colors[$key] = $hex;
        }

        // The primary colors carry white text (buttons) and appear on white surfaces (links, headings).
        foreach (['primary', 'primary_strong'] as $key) {
            if (isset($colors[$key]) && self::contrast($colors[$key], self::WHITE) < self::MIN_CONTRAST) {
                $ignored[] = $key;
                unset($colors[$key]);
            }
        }

        // Text and page background are judged as a pair, using the defaults for whichever one is not set.
        if (isset($colors['ink']) || isset($colors['background'])) {
            $ink = $colors['ink'] ?? self::DEFAULT_INK;
            $background = $colors['background'] ?? self::DEFAULT_BACKGROUND;
            if (self::contrast($ink, $background) < self::MIN_CONTRAST) {
                foreach (['ink', 'background'] as $key) {
                    if (isset($colors[$key])) {
                        $ignored[] = $key;
                        unset($colors[$key]);
                    }
                }
            }
        }

        $variables = [];
        foreach (self::COLORS as $key => $variable) {
            if (isset($colors[$key])) {
                $variables[$variable] = $colors[$key];
            }
        }

        foreach (self::FONTS as $key => [$variable, $fallback]) {
            if (!array_key_exists($key, $branding)) {
                continue;
            }
            $name = $branding[$key];
            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9 -]{0,39}$/D', $name) !== 1) {
                $ignored[] = $key;
                continue;
            }
            $variables[$variable] = '"' . $name . '", ' . $fallback;
        }

        foreach (self::RADII as $key => $variable) {
            if (!array_key_exists($key, $branding)) {
                continue;
            }
            $pixels = self::radius($branding[$key]);
            if ($pixels === null) {
                $ignored[] = $key;
                continue;
            }
            $variables[$variable] = $pixels . 'px';
        }

        $lines = ["/* Branding variables generated from the organization's settings. Do not edit. */"];
        if ($variables !== []) {
            $lines[] = ':root {';
            foreach ($variables as $name => $value) {
                $lines[] = '  ' . $name . ': ' . $value . ';';
            }
            $lines[] = '}';
        }

        return ['css' => implode("\n", $lines) . "\n", 'ignored' => $ignored];
    }

    /** WCAG contrast ratio between two colors, from 1 (identical) to 21 (black on white). */
    public static function contrast(string $first, string $second): float
    {
        $a = self::luminance($first);
        $b = self::luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** @return string|null a lower-case #rrggbb color, or null when the value is not a color */
    private static function normalizeColor(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D', $value, $match) !== 1) {
            return null;
        }

        $digits = strtolower($match[1]);
        if (strlen($digits) === 3) {
            $digits = $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2];
        }

        return '#' . $digits;
    }

    private static function radius(mixed $value): ?int
    {
        if (is_int($value)) {
            $pixels = $value;
        } elseif (is_string($value) && preg_match('/^(\d{1,2})px$/D', $value, $match) === 1) {
            $pixels = (int) $match[1];
        } else {
            return null;
        }

        return $pixels >= 0 && $pixels <= self::MAX_RADIUS ? $pixels : null;
    }

    private static function luminance(string $color): float
    {
        $hex = self::normalizeColor($color);
        if ($hex === null) {
            throw new \InvalidArgumentException('Not a color.');
        }

        $total = 0.0;
        foreach ([[1, 0.2126], [3, 0.7152], [5, 0.0722]] as [$offset, $weight]) {
            $channel = hexdec(substr($hex, $offset, 2)) / 255;
            $linear = $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
            $total += $weight * $linear;
        }

        return $total;
    }
}
