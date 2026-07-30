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
 * `filter[type]=category|direct`
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class ChannelTypeFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'type';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $values = array_values(array_intersect((array) $value, ['category', 'direct']));

        if ($values === []) {
            return;
        }

        $state->getQuery()->{$negate ? 'whereNotIn' : 'whereIn'}('chat_channels.type', $values);
    }
}
