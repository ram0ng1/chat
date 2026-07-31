<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Search;

use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\MessageFlag;

/**
 * Backs the moderation queue's listing and its one filter.
 *
 * Flarum 2 has no resource-level filters — `AbstractDatabaseResource::filters()`
 * is final and throws — so every `filter[x]` a client sends has to arrive through
 * a searcher. Without one the API rejects the parameter outright, which is what a
 * plain `?resolved=1` ran into.
 *
 * The authorisation is repeated here rather than left to the resource's `scope()`.
 * `Endpoint\Index` builds its query from the searcher and **discards** the scoped
 * one entirely (see Index::setUp), so a searcher that trusted `scope()` would be
 * an unguarded listing — MessageSearcher repeats `whereVisibleTo` for the same
 * reason.
 */
class MessageFlagSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        // Built as statements rather than one chain: `select`, `whereRaw` and
        // friends are typed as returning the lower-level query builder, and this
        // method owes its caller an Eloquent one.
        $query = MessageFlag::query();
        $query->select('chat_message_flags.*');

        if (! $actor->hasPermission('ramon-chat.moderate')) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        // The queue inherits message visibility rather than defining its own: it
        // must never show a report about something the actor could not have read
        // in the channel it was posted in.
        $query->whereHas('message', function (Builder $sub) use ($actor) {
            // @phpstan-ignore method.notFound (Flarum model scope)
            $sub->whereVisibleTo($actor);
        });

        return $query;
    }
}
