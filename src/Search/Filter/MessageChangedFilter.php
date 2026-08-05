<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Search\Filter;

use Carbon\Carbon;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * `filter[updatedSince]=1754400000` — messages touched since a known point.
 *
 * The polling fallback's other cursor, `greaterThan`, only ever reaches forward
 * from the newest id, so it can carry a message that *arrived* but never one that
 * *changed*: a reaction, an edit, a deletion or a pin lands on a row the poll has
 * already passed, and none of them appeared until the page was reloaded. This
 * filter is what lets the poll ask the second question.
 *
 * The bound is inclusive because `updated_at` is stored to the second. An
 * exclusive `>` would drop any change made in the same second as the client's
 * cursor but after its request ran — and dropping it is permanent, since nothing
 * would ever ask for that second again. The cost of being inclusive is re-reading
 * the boundary row on each poll, which is one row.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class MessageChangedFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'updatedSince';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $timestamp = (int) (is_array($value) ? reset($value) : $value);

        if ($timestamp <= 0) {
            return;
        }

        // Explicitly UTC: the column is written from Carbon::now() under Flarum's
        // UTC default, and letting the timezone come from php.ini would shift the
        // comparison by whatever the host happens to be configured for.
        $state->getQuery()->where(
            'chat_messages.updated_at',
            $negate ? '<' : '>=',
            Carbon::createFromTimestamp($timestamp, 'UTC')
        );
    }
}
