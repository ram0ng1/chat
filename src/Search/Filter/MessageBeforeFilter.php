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
 * `filter[lessThan]=1234` — cursor pagination for scrolling a channel upwards.
 *
 * Offset pagination is wrong for a chat stream: messages arriving at the bottom
 * shift every offset, so paging backwards through a live channel would skip or
 * repeat rows. An id cursor is stable under insertion.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class MessageBeforeFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'lessThan';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $id = (int) (is_array($value) ? reset($value) : $value);

        if ($id <= 0) {
            return;
        }

        $state->getQuery()->where('chat_messages.id', $negate ? '>=' : '<', $id);
    }
}
