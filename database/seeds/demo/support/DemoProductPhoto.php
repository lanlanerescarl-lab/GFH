<?php

declare(strict_types=1);

namespace Gfh\Seeds\Demo;

/**
 * Draws a product picture with GD: flat colours, a big badge with the product's initials and its name underneath.
 * Nothing is downloaded and no stock image is used. The output depends only on the arguments, so a rerun draws the
 * same picture. The lettering uses GD's built-in bitmap fonts scaled up, so no font file is needed on any machine.
 */
final class DemoProductPhoto
{
    public const SIZE = 600;

    /** @var array<string, list<array{int, int, int}>> dark, light and accent colour per product line */
    private const PALETTES = [
        'fb' => [[0x8C5A2B, 0xF3E3C8, 0xC98B3E], [0x4F7A3A, 0xE6F0D8, 0xA7C46B], [0xB5532F, 0xFBE6D6, 0xE59866]],
        'ha' => [[0x1F5F8B, 0xDCEBF5, 0x5BA3CF], [0x2E7D7A, 0xD9F0EE, 0x66BDB8], [0x45526B, 0xE2E6EE, 0x8F9BB5]],
        'fd' => [[0x8A4B3C, 0xF5E4DD, 0xCF8D78], [0x6B6B2E, 0xEEEED6, 0xB5B56A], [0x5E3F6B, 0xEADFEF, 0xA98ABA]],
    ];

    private const SKIP = ['the', 'of', 'and', 'for', 'with', 'by', 'a', 'an'];

    /** @param string $line fb (food and beverage), ha (home appliances) or fd (furniture and decor) */
    public static function render(string $name, string $line, int $variant = 0): string
    {
        $palettes = self::PALETTES[$line] ?? self::PALETTES['fb'];
        [$dark, $light, $accent] = $palettes[crc32($name) % count($palettes)];
        $size = self::SIZE;

        $image = imagecreatetruecolor($size, $size);
        $cDark = self::color($image, $dark);
        $cLight = self::color($image, $light);
        $cAccent = self::color($image, $accent);

        if ($variant === 0) {
            imagefilledrectangle($image, 0, 0, $size, $size, $cDark);
            imagefilledellipse($image, 300, 230, 350, 350, $cLight);
            self::motif($image, $line, $cAccent);
            self::text($image, self::initials($name), 5, 12, 300, 230, $cDark);
            $textColor = $cLight;
        } else {
            imagefilledrectangle($image, 0, 0, $size, $size, $cAccent);
            imagefilledrectangle($image, 70, 70, 530, 400, $cLight);
            imagefilledrectangle($image, 0, 440, $size, $size, $cDark);
            self::text($image, self::initials($name), 5, 12, 300, 235, $cDark);
            $textColor = $cLight;
        }

        $lines = explode("\n", wordwrap($name, 20, "\n", true));
        $lines = array_slice($lines, 0, 2);
        $top = $variant === 0 ? 480 : 500;
        foreach ($lines as $index => $text) {
            self::text($image, $text, 3, 3, 300, $top + $index * 46, $textColor);
        }

        ob_start();
        imagejpeg($image, null, 82);

        return (string) ob_get_clean();
    }

    public static function initials(string $name): string
    {
        $letters = '';
        foreach (preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (in_array(strtolower($word), self::SKIP, true) || ctype_digit($word[0])) {
                continue;
            }
            $letters .= strtoupper($word[0]);
            if (strlen($letters) === 2) {
                break;
            }
        }

        return $letters === '' ? 'GF' : $letters;
    }

    private static function color(\GdImage $image, int $rgb): int
    {
        return (int) imagecolorallocate($image, ($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255);
    }

    /** A small shape in the top left corner that hints at the product line. */
    private static function motif(\GdImage $image, string $line, int $color): void
    {
        if ($line === 'ha') {
            imagefilledrectangle($image, 40, 40, 150, 100, $color);
            imagefilledellipse($image, 95, 70, 26, 26, 0xFFFFFF);
        } elseif ($line === 'fd') {
            imagefilledrectangle($image, 40, 40, 150, 62, $color);
            imagefilledrectangle($image, 48, 62, 62, 100, $color);
            imagefilledrectangle($image, 128, 62, 142, 100, $color);
        } else {
            foreach ([58, 98, 138] as $x) {
                imagefilledellipse($image, $x, 70, 28, 28, $color);
            }
        }
    }

    /** Draws $text centred on ($cx, $cy), every pixel of the bitmap font as a $scale x $scale square. */
    private static function text(\GdImage $image, string $text, int $font, int $scale, int $cx, int $cy, int $color): void
    {
        $width = imagefontwidth($font) * strlen($text);
        $height = imagefontheight($font);
        $mask = imagecreate($width, $height);
        imagecolorallocate($mask, 255, 255, 255);
        $ink = (int) imagecolorallocate($mask, 0, 0, 0);
        imagestring($mask, $font, 0, 0, $text, $ink);

        $x0 = $cx - intdiv($width * $scale, 2);
        $y0 = $cy - intdiv($height * $scale, 2);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (imagecolorat($mask, $x, $y) === $ink) {
                    imagefilledrectangle($image, $x0 + $x * $scale, $y0 + $y * $scale, $x0 + ($x + 1) * $scale - 1, $y0 + ($y + 1) * $scale - 1, $color);
                }
            }
        }
    }
}
