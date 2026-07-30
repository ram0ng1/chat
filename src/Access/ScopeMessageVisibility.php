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

/**
 * A message is visible when its channel is visible. Deleted messages remain
 * visible to their author and to moderators so the tombstone can be rendered
 * with its original content on request, matching discussion post behaviour.
 */
class ScopeMessageVisibility
{
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
