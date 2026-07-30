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
 * `filter[q]=term` — name and description match for the browse page.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class ChannelNameFilter implements FilterInterface
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
        // not degenerate into matching every channel.
        $like = '%'.addcslashes($term, '\%_').'%';

        $state->getQuery()->where(function ($query) use ($like, $negate) {
            $op = $negate ? 'not like' : 'like';

            $query->where('chat_channels.name', $op, $like)
                ->orWhere('chat_channels.description', $op, $like);
        });
    }
}
