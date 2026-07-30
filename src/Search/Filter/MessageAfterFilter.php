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
 * `filter[greaterThan]=1234` — the tail of a channel since a known message.
 *
 * Used by the polling fallback when flarum/realtime is absent, so each poll
 * transfers only what arrived rather than re-fetching the last page.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class MessageAfterFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'greaterThan';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $id = (int) (is_array($value) ? reset($value) : $value);

        if ($id <= 0) {
            return;
        }

        $state->getQuery()->where('chat_messages.id', $negate ? '<=' : '>', $id);
    }
}
