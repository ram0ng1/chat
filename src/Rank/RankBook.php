<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Rank;

use Ramon\Chat\ChannelRank;

/**
 * A channel's rank book: the definitions in priority order, who is a
 * moderator, and who holds each owner-created rank.
 *
 * Priority is fixed at the top and free below: owner, moderator, then the
 * owner's ranks in the order they chose. A member with several ranks shows the
 * first one. The owner is not part of the cached book; it is added at read
 * time (`withOwner`), from the already-loaded channel's `creator_id`.
 *
 * The mirror in js/src/forum/utils/ranks.tsx follows this same priority.
 */
final class RankBook
{
    /**
     * The part of the book that goes into the cache.
     *
     * @param  iterable<ChannelRank>  $ranks
     * @param  iterable<object{user_id: int|string, is_moderator: int|string|bool, rank_id: int|string|null}>  $members  Visible, present members with a role or rank.
     * @return array{ranks: list<array<string, mixed>>, moderatorIds: list<int>, assignments: array<int, list<int>>}
     */
    public static function compile(iterable $ranks, iterable $members): array
    {
        $builtins = [];
        $custom = [];

        foreach ($ranks as $rank) {
            if ($rank->builtin !== null) {
                $builtins[$rank->builtin] = $rank;
            } else {
                $custom[] = $rank;
            }
        }

        usort($custom, fn (ChannelRank $a, ChannelRank $b) => [$a->position, $a->id] <=> [$b->position, $b->id]);

        $entries = [];

        foreach (ChannelRank::builtins() as $index => $builtin) {
            $entries[] = self::entry($builtin, $builtins[$builtin] ?? null, $index);
        }

        foreach ($custom as $rank) {
            $entries[] = self::entry(null, $rank, (int) $rank->position);
        }

        $known = array_flip(array_map(fn (ChannelRank $rank) => (int) $rank->id, $custom));

        $moderatorIds = [];
        $assignments = [];

        foreach ($members as $row) {
            $userId = (int) $row->user_id;

            if ((bool) $row->is_moderator) {
                $moderatorIds[$userId] = $userId;
            }

            if ($row->rank_id !== null && isset($known[(int) $row->rank_id])) {
                $assignments[$userId][] = (int) $row->rank_id;
            }
        }

        foreach ($assignments as &$ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
        }

        unset($ids);

        ksort($assignments);

        return [
            'ranks'        => $entries,
            'moderatorIds' => array_values($moderatorIds),
            'assignments'  => $assignments,
        ];
    }

    /**
     * @param  array{ranks: list<array<string, mixed>>, moderatorIds: list<int>, assignments: array<int, list<int>>}  $compiled
     * @return array{ranks: list<array<string, mixed>>, moderatorIds: list<int>, assignments: array<int, list<int>>, ownerId: int|null}
     */
    public static function withOwner(array $compiled, ?int $ownerId): array
    {
        return $compiled + ['ownerId' => $ownerId];
    }

    /**
     * The rank shown next to the user's name, or null.
     *
     * @param  array<string, mixed>  $book
     * @return array<string, mixed>|null
     */
    public static function displayed(array $book, ?int $userId): ?array
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        $key = self::displayedKey($book, $userId);

        if ($key === null) {
            return null;
        }

        foreach ($book['ranks'] ?? [] as $rank) {
            if ($rank['key'] === $key) {
                return $rank;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $book
     */
    public static function displayedKey(array $book, int $userId): ?string
    {
        if (isset($book['ownerId']) && (int) $book['ownerId'] === $userId) {
            return ChannelRank::BUILTIN_OWNER;
        }

        if (in_array($userId, array_map('intval', $book['moderatorIds'] ?? []), true)) {
            return ChannelRank::BUILTIN_MODERATOR;
        }

        $held = array_map('intval', $book['assignments'][$userId] ?? []);

        if ($held === []) {
            return null;
        }

        foreach ($book['ranks'] ?? [] as $rank) {
            if ($rank['builtin'] === null && in_array((int) $rank['id'], $held, true)) {
                return (string) $rank['key'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(?string $builtin, ?ChannelRank $rank, int $position): array
    {
        $color = $rank?->color !== null ? strtolower((string) $rank->color) : null;

        return [
            'key'       => $builtin ?? (string) $rank?->id,
            'id'        => $rank?->id !== null ? (int) $rank->id : null,
            'builtin'   => $builtin,
            'name'      => $rank?->name,
            'color'     => $color,
            'icon'      => $rank?->icon,
            'showBadge' => $rank === null ? true : (bool) $rank->show_badge,
            'position'  => $position,
        ] + RankPalette::derive($color);
    }
}
