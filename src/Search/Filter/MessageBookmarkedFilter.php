<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * `filter[bookmarked]=1` — the actor's bookmarked messages.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class MessageBookmarkedFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'bookmarked';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $wanted = filter_var(
            is_array($value) ? reset($value) : $value,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($wanted === null) {
            return;
        }

        if ($negate) {
            $wanted = ! $wanted;
        }

        $actor = $state->getActor();
        $query = $state->getQuery();

        // Guests hold no bookmarks, so the filter resolves to "nothing" rather
        // than being silently ignored.
        if (! $actor->exists) {
            if ($wanted) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $exists = function ($sub) use ($actor) {
            $sub->selectRaw(1)
                ->from('chat_bookmarks')
                ->whereColumn('chat_bookmarks.message_id', 'chat_messages.id')
                ->where('chat_bookmarks.user_id', $actor->id);
        };

        $wanted ? $query->whereExists($exists) : $query->whereNotExists($exists);
    }
}
