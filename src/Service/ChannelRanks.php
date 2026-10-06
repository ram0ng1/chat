<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Query\JoinClause;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelRank;
use Ramon\Chat\ChannelRankUser;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\ChannelRanksChanged;
use Ramon\Chat\Rank\RankBook;
use WeakMap;

/**
 * A channel's ranks: the book read next to each name, and the writes of
 * whoever manages them.
 *
 * The book does not depend on who reads it, so it is cached per channel and
 * cleared on every change that affects it: the writes here and, through
 * Listener\ForgetChannelRanks, promotions, transfers, joins and leaves. Within
 * a request it is remembered on the channel instance itself, which the
 * messages of a page share, so a page of fifty messages reads the cache once.
 */
class ChannelRanks
{
    public const CACHE_TTL = 86400;

    /**
     * @var WeakMap<Channel, array<string, mixed>>
     */
    protected WeakMap $memo;

    public function __construct(
        protected Cache $cache,
        protected Events $events,
        protected Translator $translator
    ) {
        $this->memo = new WeakMap();
    }

    /**
     * Null for a direct conversation, which has no ranks.
     *
     * @return array<string, mixed>|null
     */
    public function book(Channel $channel): ?array
    {
        if (! $channel->exists || ! $channel->isCategory()) {
            return null;
        }

        if (! isset($this->memo[$channel])) {
            $this->memo[$channel] = $this->cache->remember(
                $this->key((int) $channel->id),
                self::CACHE_TTL,
                fn () => $this->compile((int) $channel->id)
            );
        }

        /** @var array{ranks: list<array<string, mixed>>, moderatorIds: list<int>, assignments: array<int, list<int>>} $compiled */
        $compiled = $this->memo[$channel];

        return RankBook::withOwner($compiled, $channel->creator_id !== null ? (int) $channel->creator_id : null);
    }

    /**
     * The rank shown next to the author's name, or null.
     *
     * @return array<string, mixed>|null
     */
    public function forUser(Channel $channel, ?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $book = $this->book($channel);

        return $book === null ? null : RankBook::displayed($book, $userId);
    }

    public function forget(Channel $channel): void
    {
        $this->cache->forget($this->key((int) $channel->id));

        foreach ($this->memo as $cached => $ignored) {
            if ((int) $cached->id === (int) $channel->id) {
                unset($this->memo[$cached]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Channel $channel, User $actor, array $attributes): ChannelRank
    {
        $count = ChannelRank::query()
            ->where('channel_id', $channel->id)
            ->whereNull('builtin')
            ->count();

        if ($count >= ChannelRank::MAX_PER_CHANNEL) {
            throw new ValidationException([
                'name' => $this->translator->trans('ramon-chat.api.rank_limit', ['max' => ChannelRank::MAX_PER_CHANNEL]),
            ]);
        }

        $rank = new ChannelRank();
        $rank->channel_id = (int) $channel->id;
        $rank->builtin = null;
        $rank->show_badge = true;
        $rank->position = (int) ChannelRank::query()
            ->where('channel_id', $channel->id)
            ->whereNull('builtin')
            ->max('position') + 1;

        $this->applyAttributes($rank, $attributes, creating: true);
        $rank->save();

        $this->changed($channel, $actor);

        return $rank;
    }

    /**
     * Edits an owner-created rank, or customizes a built-in one. `$target` is
     * the id of one, or 'owner' / 'moderator'.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Channel $channel, User $actor, int|string $target, array $attributes): ChannelRank
    {
        $rank = $this->find($channel, $target);

        $this->applyAttributes($rank, $attributes, creating: false);

        if (! $rank->exists || $rank->isDirty()) {
            $rank->save();
            $this->changed($channel, $actor);
        }

        return $rank;
    }

    /**
     * Deletes an owner-created rank, and with it whoever held it. On a built-in
     * one, it reverts to the default.
     */
    public function delete(Channel $channel, User $actor, int|string $target): void
    {
        $rank = $this->find($channel, $target);

        if ($rank->exists) {
            // The foreign key cascades too; deleting the holds by hand keeps a
            // database without enforced keys from keeping orphans.
            $channel->getConnection()->transaction(function () use ($rank) {
                ChannelRankUser::query()->where('rank_id', $rank->id)->delete();
                $rank->delete();
            });

            $this->changed($channel, $actor);
        }
    }

    /**
     * The new order of the owner-created ranks: all of them, each once.
     *
     * @param  array<int, mixed>  $ids
     */
    public function reorder(Channel $channel, User $actor, array $ids): void
    {
        $ids = array_map(fn ($id) => is_numeric($id) ? (int) $id : 0, array_values($ids));

        $existing = ChannelRank::query()
            ->where('channel_id', $channel->id)
            ->whereNull('builtin')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $sorted = $ids;
        sort($sorted);
        sort($existing);

        if ($sorted !== $existing) {
            throw new ValidationException([
                'rankIds' => $this->translator->trans('ramon-chat.api.rank_order_invalid'),
            ]);
        }

        $channel->getConnection()->transaction(function () use ($channel, $ids) {
            foreach ($ids as $index => $id) {
                ChannelRank::query()
                    ->where('channel_id', $channel->id)
                    ->whereKey($id)
                    ->update(['position' => $index + 1]);
            }
        });

        $this->changed($channel, $actor);
    }

    /**
     * A member's ranks, all at once: the ones not in the list are removed.
     *
     * Only for someone who is in the channel and shows up in it: a hidden
     * member gets the same response as a non-member.
     *
     * @param  array<int, mixed>  $rankIds
     */
    public function assign(Channel $channel, User $actor, User $user, array $rankIds): void
    {
        $membership = $channel->membershipFor($user);

        if ($membership === null || $membership->hasLeft() || $membership->isHidden()) {
            throw new ValidationException([
                'userId' => $this->translator->trans('ramon-chat.api.not_a_member'),
            ]);
        }

        $rankIds = array_values(array_unique(array_map(fn ($id) => is_numeric($id) ? (int) $id : 0, $rankIds)));

        $valid = $rankIds === [] ? [] : ChannelRank::query()
            ->where('channel_id', $channel->id)
            ->whereNull('builtin')
            ->whereIn('id', $rankIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($valid) !== count($rankIds)) {
            throw new ValidationException([
                'rankIds' => $this->translator->trans('ramon-chat.api.rank_not_found'),
            ]);
        }

        $channel->getConnection()->transaction(function () use ($channel, $user, $valid) {
            ChannelRankUser::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $user->id)
                ->when($valid !== [], fn ($query) => $query->whereNotIn('rank_id', $valid))
                ->delete();

            $held = ChannelRankUser::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $user->id)
                ->pluck('rank_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $now = Carbon::now();
            $rows = [];

            foreach (array_diff($valid, $held) as $rankId) {
                $rows[] = [
                    'rank_id'    => $rankId,
                    'user_id'    => (int) $user->id,
                    'channel_id' => (int) $channel->id,
                    'created_at' => $now,
                ];
            }

            if ($rows !== []) {
                ChannelRankUser::query()->insertOrIgnore($rows);
            }
        });

        $this->changed($channel, $actor);
    }

    /**
     * Reads only the known fields, each validated. The rest are ignored.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function applyAttributes(ChannelRank $rank, array $attributes, bool $creating): void
    {
        $errors = [];
        $builtin = $rank->isBuiltin();

        if ($creating || array_key_exists('name', $attributes)) {
            $name = $attributes['name'] ?? null;
            $name = is_string($name) ? trim((string) preg_replace('/\s+/u', ' ', $name)) : $name;

            if (($name !== null && ! is_string($name))
                || ($name === null || $name === '') && ! $builtin
                || is_string($name) && mb_strlen($name) > ChannelRank::NAME_MAX) {
                $errors['name'] = $this->translator->trans('ramon-chat.api.rank_name_invalid', ['max' => ChannelRank::NAME_MAX]);
            } else {
                $rank->name = $name === '' ? null : $name;
            }
        }

        if ($creating || array_key_exists('color', $attributes)) {
            $color = $attributes['color'] ?? null;

            if (($color === null || $color === '') && $builtin) {
                $rank->color = null;
            } elseif (! is_string($color) || ! preg_match(ChannelRank::COLOR_PATTERN, $color)) {
                $errors['color'] = $this->translator->trans('ramon-chat.api.rank_color_invalid');
            } else {
                $rank->color = strtolower($color);
            }
        }

        if (array_key_exists('icon', $attributes)) {
            $icon = $attributes['icon'];
            $icon = is_string($icon) ? trim($icon) : $icon;

            if ($icon === null || $icon === '') {
                $rank->icon = null;
            } elseif (! is_string($icon) || ! preg_match(ChannelRank::ICON_PATTERN, $icon)) {
                $errors['icon'] = $this->translator->trans('ramon-chat.api.rank_icon_invalid');
            } else {
                $rank->icon = $icon;
            }
        }

        if (array_key_exists('showBadge', $attributes)) {
            $show = $attributes['showBadge'];

            if (! is_bool($show) && ! in_array($show, [0, 1, '0', '1'], true)) {
                $errors['showBadge'] = $this->translator->trans('ramon-chat.api.rank_flag_invalid');
            } else {
                $rank->show_badge = (bool) $show;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * A built-in without a row comes back as a new model with the defaults;
     * saving it is what customizes it.
     */
    protected function find(Channel $channel, int|string $target): ChannelRank
    {
        if (is_string($target) && in_array($target, ChannelRank::builtins(), true)) {
            /** @var ChannelRank|null $rank */
            $rank = ChannelRank::query()
                ->where('channel_id', $channel->id)
                ->where('builtin', $target)
                ->first();

            if ($rank === null) {
                $rank = new ChannelRank();
                $rank->channel_id = (int) $channel->id;
                $rank->builtin = $target;
                $rank->show_badge = true;
                $rank->position = 0;
            }

            return $rank;
        }

        $id = is_numeric($target) ? (int) $target : 0;

        /** @var ChannelRank|null $rank */
        $rank = $id > 0
            ? ChannelRank::query()
                ->whereKey($id)
                ->where('channel_id', $channel->id)
                ->whereNull('builtin')
                ->first()
            : null;

        if ($rank === null) {
            throw new ValidationException([
                'rankId' => $this->translator->trans('ramon-chat.api.rank_not_found'),
            ]);
        }

        return $rank;
    }

    protected function changed(Channel $channel, User $actor): void
    {
        $this->forget($channel);

        $this->events->dispatch(new ChannelRanksChanged($channel, $actor));
    }

    /**
     * Two queries: the ranks (which RankBook orders), and the present, visible
     * members who are moderators or hold some rank, already with each one's
     * ranks.
     *
     * @return array{ranks: list<array<string, mixed>>, moderatorIds: list<int>, assignments: array<int, list<int>>}
     */
    protected function compile(int $channelId): array
    {
        $ranks = ChannelRank::query()
            ->where('channel_id', $channelId)
            ->get();

        $members = ChannelUser::query()
            ->toBase()
            ->leftJoin('chat_channel_rank_user', function (JoinClause $join) {
                $join->on('chat_channel_rank_user.channel_id', '=', 'chat_channel_user.channel_id')
                    ->on('chat_channel_rank_user.user_id', '=', 'chat_channel_user.user_id');
            })
            ->where('chat_channel_user.channel_id', $channelId)
            ->whereNull('chat_channel_user.left_at')
            ->where('chat_channel_user.hidden', false)
            ->where(function ($query) {
                $query->where('chat_channel_user.is_moderator', true)
                    ->orWhereNotNull('chat_channel_rank_user.rank_id');
            })
            ->get([
                'chat_channel_user.user_id',
                'chat_channel_user.is_moderator',
                'chat_channel_rank_user.rank_id',
            ]);

        return RankBook::compile($ranks->all(), $members->all());
    }

    protected function key(int $channelId): string
    {
        return 'ramon-chat.ranks.'.$channelId;
    }
}
