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
use Ramon\Chat\Message;

/**
 * `filter[q]=term` — message search, within one channel when combined with
 * `filter[channel]`, or across every channel the actor can see otherwise.
 *
 * The searcher's visibility scope already constrains the query, so cross-channel
 * search cannot reach channels the actor has no access to.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class MessageTextFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'q';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $term = trim((string) (is_array($value) ? reset($value) : $value));

        if ($term === '') {
            return;
        }

        // addcslashes over the LIKE metacharacters, so searching for "100%" does
        // not degenerate into matching every message.
        $like = '%'.addcslashes($term, '\\%_').'%';

        $state->getQuery()
            // Neither a tombstone nor a system message is something a user means
            // to find, so search never returns them.
            ->whereNull('chat_messages.deleted_at')
            ->where('chat_messages.type', Message::TYPE_TEXT)
            ->where('chat_messages.content', $negate ? 'not like' : 'like', $like);
    }
}
