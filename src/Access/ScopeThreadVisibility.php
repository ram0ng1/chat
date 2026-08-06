<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\Channel;
use Ramon\Chat\Thread;

/**
 * Threads inherit their channel's visibility.
 */
class ScopeThreadVisibility
{
    /**
     * The row-level half of the scope, for a thread already loaded.
     *
     * Written twice — as SQL below, in PHP here — and the two must stay in step;
     * they share this file for that reason. See ScopeMessageVisibility, which
     * carries the same pair for the same reason.
     */
    public static function rowVisibleTo(Thread $thread, bool $channelVisible): bool
    {
        return $channelVisible && $thread->deleted_at === null;
    }

    public function __invoke(User $actor, Builder $query): void
    {
        $query
            ->whereNull('chat_threads.deleted_at')
            ->whereIn('chat_threads.channel_id', function ($sub) use ($actor) {
                Channel::query()
                    ->setQuery($sub->from('chat_channels'))
                    ->whereVisibleTo($actor)
                    ->select('chat_channels.id');
            });
    }
}
