<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Search;

use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\Channel;

/**
 * Flarum 2 has no JSON:API resource filters — `AbstractDatabaseResource::filters()`
 * is final and throws, directing extensions to the search driver instead. Every
 * `filter[...]` the client sends therefore has to arrive through a searcher and
 * its registered FilterInterface implementations.
 */
class ChannelSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        return Channel::whereVisibleTo($actor)->select('chat_channels.*');
    }
}
