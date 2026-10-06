<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Rank;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramon\Chat\Rank\RankPalette;

/**
 * Qualquer cor escolhida para um cargo continua legível: no texto do selo, e
 * como cor do nome nos temas claro e escuro.
 */
class RankPaletteTest extends TestCase
{
    public function test_contrast_matches_the_wcag_reference_values(): void
    {
        $this->assertEqualsWithDelta(21.0, RankPalette::contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, RankPalette::contrast('#777777', '#777777'), 0.001);
        $this->assertEqualsWithDelta(4.48, RankPalette::contrast('#777777', '#ffffff'), 0.01);
    }

    public function test_a_tag_takes_whichever_text_reads_better(): void
    {
        $this->assertSame(RankPalette::LIGHT_TEXT, RankPalette::badgeText('#1e3799'));
        $this->assertSame(RankPalette::DARK_TEXT, RankPalette::badgeText('#f1c40f'));
        $this->assertSame(RankPalette::DARK_TEXT, RankPalette::badgeText('#ffffff'));
        $this->assertSame(RankPalette::LIGHT_TEXT, RankPalette::badgeText('#000000'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function colours(): array
    {
        return [
            'white'       => ['#ffffff'],
            'black'       => ['#000000'],
            'yellow'      => ['#f1c40f'],
            'navy'        => ['#1e3799'],
            'mid grey'    => ['#777777'],
            'pure red'    => ['#ff0000'],
            'pale pink'   => ['#ffd1dc'],
            'dark purple' => ['#2c003e'],
            'blurple'     => ['#5865f2'],
        ];
    }

    #[DataProvider('colours')]
    public function test_every_colour_becomes_a_readable_name_in_both_themes(string $colour): void
    {
        $derived = RankPalette::derive($colour);

        $this->assertGreaterThanOrEqual(4.5, RankPalette::contrast((string) $derived['nameLight'], RankPalette::LIGHT_SURFACE));
        $this->assertGreaterThanOrEqual(4.5, RankPalette::contrast((string) $derived['nameDark'], RankPalette::DARK_SURFACE));
        $this->assertGreaterThanOrEqual(
            max(RankPalette::contrast($colour, '#ffffff'), RankPalette::contrast($colour, '#16161a')) - 0.001,
            RankPalette::contrast($colour, (string) $derived['textColor'])
        );
    }

    public function test_a_colour_already_readable_is_left_alone(): void
    {
        $this->assertSame('#1e3799', RankPalette::readableOn('#1E3799', RankPalette::LIGHT_SURFACE));
        $this->assertSame('#f1c40f', RankPalette::readableOn('#f1c40f', RankPalette::DARK_SURFACE));
    }

    public function test_an_adjusted_colour_keeps_its_hue_rather_than_turning_grey(): void
    {
        $light = (string) RankPalette::derive('#f1c40f')['nameLight'];

        [$r, $g, $b] = sscanf($light, '#%02x%02x%02x');

        $this->assertGreaterThan($b, $r, 'still a yellow: red above blue');
        $this->assertGreaterThan($b, $g, 'still a yellow: green above blue');
    }

    public function test_no_colour_derives_nothing(): void
    {
        $this->assertSame(['textColor' => null, 'nameLight' => null, 'nameDark' => null], RankPalette::derive(null));
        $this->assertSame(['textColor' => null, 'nameLight' => null, 'nameDark' => null], RankPalette::derive('red'));
    }
}
