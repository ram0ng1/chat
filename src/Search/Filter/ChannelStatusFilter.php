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
 * `filter[status]=open|closed|archived`
 *
 * No default is applied: the browse page asks for closed and archived channels
 * explicitly, while the sidebar asks for open ones.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class ChannelStatusFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'status';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $values = array_values(array_intersect((array) $value, ['open', 'closed', 'archived']));

        if ($values === []) {
            return;
        }

        $state->getQuery()->{$negate ? 'whereNotIn' : 'whereIn'}('chat_channels.status', $values);
    }
}
