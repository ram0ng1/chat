<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Rank;

/**
 * As cores que derivam da cor de um cargo, para que qualquer cor escolhida
 * pelo dono continue legível.
 *
 * A cor escolhida vale como está no fundo do selo; o texto do selo é branco
 * ou quase preto, o que contrastar mais. Como cor do nome (selo desligado),
 * ela é escurecida no tema claro e clareada no escuro, só o necessário para
 * chegar a 4.5:1 contra a superfície da conversa (WCAG AA para texto). Um
 * amarelo-claro vira mostarda no claro; um azul-marinho vira azul-céu no
 * escuro. O espelho em js/src/forum/utils/rankPalette.ts segue este cálculo.
 */
final class RankPalette
{
    public const LIGHT_SURFACE = '#ffffff';

    public const DARK_SURFACE = '#222226';

    public const LIGHT_TEXT = '#ffffff';

    public const DARK_TEXT = '#16161a';

    public const MIN_CONTRAST = 4.5;

    /**
     * Texto do selo, nome no tema claro e nome no tema escuro. Tudo nulo para
     * uma cor nula, que é a do tema e fica a cargo do CSS.
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
     * Luminância relativa, como a WCAG define.
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
     * A cor mais próxima da dada que se lê sobre a superfície: misturada aos
     * poucos com preto numa superfície clara, ou com branco numa escura.
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
