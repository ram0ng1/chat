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
 * `filter[participating]=1` — backs the "My Threads" link at the top of the chat
 * sidebar.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class ThreadParticipatingFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'participating';
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

        if (! $actor->exists) {
            if ($wanted) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $exists = function ($sub) use ($actor) {
            $sub->selectRaw(1)
                ->from('chat_thread_user')
                ->whereColumn('chat_thread_user.thread_id', 'chat_threads.id')
                ->where('chat_thread_user.user_id', $actor->id)
                // Level 0 means the user deliberately stopped tracking, so the
                // thread drops out of "My Threads".
                ->where('chat_thread_user.notification_level', '>', 0);
        };

        $wanted ? $query->whereExists($exists) : $query->whereNotExists($exists);
    }
}
