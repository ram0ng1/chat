<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Rank;

use PHPUnit\Framework\TestCase;
use Ramon\Chat\ChannelRank;
use Ramon\Chat\Rank\RankBook;

/**
 * Which rank shows next to each name: owner, then moderator, then the owner's
 * ranks in their order.
 */
class RankBookTest extends TestCase
{
    private const OWNER = 1;
    private const MOD = 2;
    private const MEMBER = 3;
    private const NOBODY = 4;

    public function test_the_owner_outranks_everything_they_also_hold(): void
    {
        $book = $this->book([self::OWNER => [true, [10]]]);

        $this->assertSame('owner', RankBook::displayedKey($book, self::OWNER));
    }

    public function test_a_moderator_outranks_their_custom_ranks(): void
    {
        $book = $this->book([self::MOD => [true, [10, 11]]]);

        $this->assertSame('moderator', RankBook::displayedKey($book, self::MOD));
    }

    public function test_among_custom_ranks_the_owners_order_wins_not_the_id(): void
    {
        $book = $this->book([self::MEMBER => [false, [10, 11]]], [10 => 2, 11 => 1]);

        $this->assertSame('11', RankBook::displayedKey($book, self::MEMBER));
        $this->assertSame(['owner', 'moderator', '11', '10'], array_column($book['ranks'], 'key'));
    }

    public function test_equal_positions_fall_back_to_the_older_rank(): void
    {
        $book = $this->book([self::MEMBER => [false, [11, 10]]], [10 => 1, 11 => 1]);

        $this->assertSame('10', RankBook::displayedKey($book, self::MEMBER));
    }

    public function test_someone_without_a_role_or_rank_shows_nothing(): void
    {
        $book = $this->book([self::MEMBER => [false, [10]]]);

        $this->assertNull(RankBook::displayed($book, self::NOBODY));
        $this->assertNull(RankBook::displayed($book, null));
        $this->assertNull(RankBook::displayed($book, 0));
    }

    public function test_a_hold_on_a_rank_that_no_longer_exists_is_ignored(): void
    {
        $book = $this->book([self::MEMBER => [false, [99]]]);

        $this->assertNull(RankBook::displayedKey($book, self::MEMBER));
        $this->assertSame([], $book['assignments']);
    }

    public function test_a_channel_without_an_owner_shows_no_owner(): void
    {
        $book = RankBook::withOwner(RankBook::compile([], []), null);

        $this->assertNull(RankBook::displayedKey($book, self::OWNER));
        $this->assertCount(2, $book['ranks'], 'the built-in ranks exist even untouched');
    }

    public function test_untouched_built_ins_carry_defaults_and_customised_ones_their_settings(): void
    {
        $owner = $this->rank(5, 'owner', 0, 'Founder', '#8E44AD');
        $owner->show_badge = false;

        $book = RankBook::compile([$owner], []);

        $this->assertSame(['Founder', '#8e44ad', false, 5], [
            $book['ranks'][0]['name'],
            $book['ranks'][0]['color'],
            $book['ranks'][0]['showBadge'],
            $book['ranks'][0]['id'],
        ]);
        $this->assertSame([null, null, true, null], [
            $book['ranks'][1]['name'],
            $book['ranks'][1]['color'],
            $book['ranks'][1]['showBadge'],
            $book['ranks'][1]['textColor'],
        ]);
    }

    public function test_the_displayed_entry_carries_its_derived_colours(): void
    {
        $book = $this->book([self::MEMBER => [false, [10]]]);

        $rank = RankBook::displayed($book, self::MEMBER);

        $this->assertNotNull($rank);
        $this->assertSame('#ffffff', $rank['textColor']);
        $this->assertNotNull($rank['nameLight']);
        $this->assertNotNull($rank['nameDark']);
    }

    /**
     * @param  array<int, array{0: bool, 1: list<int>}>  $members  user => [moderator, rank ids]
     * @param  array<int, int>  $positions  rank id => position
     * @return array<string, mixed>
     */
    private function book(array $members, array $positions = [10 => 1, 11 => 2]): array
    {
        $ranks = [];

        foreach ($positions as $id => $position) {
            $ranks[] = $this->rank($id, null, $position, 'Rank '.$id, '#2e4057');
        }

        $rows = [];

        foreach ($members as $userId => [$moderator, $rankIds]) {
            foreach ($rankIds === [] ? [null] : $rankIds as $rankId) {
                $rows[] = (object) ['user_id' => $userId, 'is_moderator' => $moderator ? 1 : 0, 'rank_id' => $rankId];
            }
        }

        return RankBook::withOwner(RankBook::compile($ranks, $rows), self::OWNER);
    }

    private function rank(int $id, ?string $builtin, int $position, ?string $name, ?string $color): ChannelRank
    {
        $rank = new ChannelRank();
        $rank->id = $id;
        $rank->builtin = $builtin;
        $rank->position = $position;
        $rank->name = $name;
        $rank->color = $color;
        $rank->show_badge = true;

        return $rank;
    }
}
