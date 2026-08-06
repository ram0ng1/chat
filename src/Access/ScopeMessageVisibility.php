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
use Ramon\Chat\Message;

/**
 * A message is visible when its channel is visible. Deleted messages remain
 * visible to their author and to moderators so the tombstone can be rendered
 * with its original content on request, matching discussion post behaviour.
 *
 * The rule is written twice — once as SQL in `__invoke`, once in PHP in
 * `rowVisibleTo` — and the two must stay in step. They live in the same file for
 * that reason: the PHP form is what lets MessagePolicy answer for a message
 * already in memory without asking the database whether a row it is holding
 * exists, which was fifty `EXISTS` queries on every page of a channel.
 */
class ScopeMessageVisibility
{
    /**
     * The row-level half of the scope, for a message already loaded.
     *
     * `$channelVisible` is passed in rather than resolved here: it is the same
     * answer for every message in a channel, so the caller resolves it once.
     */
    public static function rowVisibleTo(User $actor, Message $message, bool $channelVisible): bool
    {
        if (! $channelVisible) {
            return false;
        }

        if ($actor->can('ramon-chat.moderate')) {
            return true;
        }

        if ($message->deleted_at === null) {
            return true;
        }

        return $actor->exists && (int) $message->user_id === (int) $actor->id;
    }

    public function __invoke(User $actor, Builder $query): void
    {
        $query->whereIn('chat_messages.channel_id', function ($sub) use ($actor) {
            Channel::query()
                ->setQuery($sub->from('chat_channels'))
                ->whereVisibleTo($actor)
                ->select('chat_channels.id');
        });

        if ($actor->can('ramon-chat.moderate')) {
            return;
        }

        $query->where(function (Builder $query) use ($actor) {
            $query->whereNull('chat_messages.deleted_at');

            if ($actor->exists) {
                $query->orWhere('chat_messages.user_id', $actor->id);
            }
        });
    }
}
