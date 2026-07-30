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
 * `filter[thread]=1` — every message in a thread, root included.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class MessageThreadFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'thread';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $threadId = (int) (is_array($value) ? reset($value) : $value);

        if ($threadId <= 0) {
            return;
        }

        $state->getQuery()->where('chat_messages.thread_id', $negate ? '!=' : '=', $threadId);
    }
}
