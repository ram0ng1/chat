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
 * `filter[pinned]=1` — only pinned messages.
 *
 * Combined with `filter[channel]` to build a channel's pinned list. Negated
 * (`filter[-pinned]=1`) it excludes them, which is what makes it composable rather
 * than a special-case endpoint.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class MessagePinnedFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'pinned';
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

        // `pinned_at IS NOT NULL` is the single source of truth for a pin, so the
        // filter reads that column rather than `pinned_by_id`, which is attribution
        // and may be nulled by a user deletion.
        $query = $state->getQuery();

        if ($wanted xor $negate) {
            $query->whereNotNull('chat_messages.pinned_at');
        } else {
            $query->whereNull('chat_messages.pinned_at');
        }
    }
}
