<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Rank;

/**
 * The colors derived from a rank's color, so that any color the owner picks
 * stays readable.
 *
 * The chosen color is used as is for the badge background; the badge text is
 * white or near-black, whichever contrasts more. As a name color (badge off),
 * it is darkened on the light theme and lightened on the dark one, only as
 * much as needed to reach 4.5:1 against the conversation surface (WCAG AA for
 * text). A light yellow becomes mustard on light; a navy becomes sky blue on
 * dark. The mirror in js/src/forum/utils/rankPalette.ts follows this
 * calculation.
 */
final class RankPalette
{
    public const LIGHT_SURFACE = '#ffffff';

    public const DARK_SURFACE = '#222226';

    public const LIGHT_TEXT = '#ffffff';

    public const DARK_TEXT = '#16161a';

    public const MIN_CONTRAST = 4.5;

    /**
     * Badge text, name on the light theme and name on the dark theme. All null
     * for a null color, which is the theme's own and is left to the CSS.
     *
     * @return array{textColor: string|null, nameLight: string|null, nameDark: string|null}
     */
    public static function derive(?string $hex): array
    {
        if ($hex === null || ! self::isHex($hex)) {
            return ['textColor' => null, 'nameLight' => null, 'nameDark' => null];
        }

        $hex = strtolower($hex);

        return [
            'textColor' => self::badgeText($hex),
            'nameLight' => self::readableOn($hex, self::LIGHT_SURFACE),
            'nameDark'  => self::readableOn($hex, self::DARK_SURFACE),
        ];
    }

    public static function isHex(string $hex): bool
    {
        return (bool) preg_match('/\A#[0-9a-fA-F]{6}\z/', $hex);
    }

    /**
     * Relative luminance, as WCAG defines it.
     */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::channels($hex);

        $linear = static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function badgeText(string $hex): string
    {
        return self::contrast($hex, self::LIGHT_TEXT) >= self::contrast($hex, self::DARK_TEXT)
            ? self::LIGHT_TEXT
            : self::DARK_TEXT;
    }

    /**
     * The closest color to the given one that reads on the surface: gradually
     * mixed with black on a light surface, or with white on a dark one.
     */
    public static function readableOn(string $hex, string $surface): string
    {
        $hex = strtolower($hex);

        if (self::contrast($hex, $surface) >= self::MIN_CONTRAST) {
            return $hex;
        }

        $target = self::luminance($surface) > 0.5 ? '#000000' : '#ffffff';

        for ($step = 1; $step <= 20; $step++) {
            $candidate = self::mix($hex, $target, $step / 20);

            if (self::contrast($candidate, $surface) >= self::MIN_CONTRAST) {
                return $candidate;
            }
        }

        return $target;
    }

    public static function mix(string $from, string $to, float $amount): string
    {
        $a = self::channels($from);
        $b = self::channels($to);
        $out = '#';

        for ($i = 0; $i < 3; $i++) {
            $out .= sprintf('%02x', (int) round($a[$i] + ($b[$i] - $a[$i]) * $amount));
        }

        return $out;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function channels(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
