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
 * `filter[resolved]=1` — reports a moderator has already dealt with.
 *
 * Absent, the queue shows only what is still open: the resolved ones are history,
 * worth keeping and not worth putting in front of someone every time they look.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class FlagResolvedFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'resolved';
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

        // `resolved=1` widens rather than narrows: a moderator asking to see the
        // handled ones wants the whole history, not only the closed half of it.
        // Narrowing would leave them looking at a list with the open work missing.
        //
        // Which is why the *client* always sends this, `0` or `1`, and the searcher
        // applies no default of its own. A default in `getQuery()` could not be
        // undone here — filters run after it — so the alternative was a bare
        // listing that quietly disagreed with the filtered one.
        if ($wanted) {
            return;
        }

        $state->getQuery()->whereNull('chat_message_flags.resolved_at');
    }
}
