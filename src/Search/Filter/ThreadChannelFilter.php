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
 * `filter[channel]=1` — threads belonging to one channel.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class ThreadChannelFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'channel';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $channelId = (int) (is_array($value) ? reset($value) : $value);

        if ($channelId <= 0) {
            return;
        }

        $state->getQuery()->where('chat_threads.channel_id', $negate ? '!=' : '=', $channelId);
    }
}
