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
use Ramon\Chat\Thread;

class ThreadSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        return Thread::whereVisibleTo($actor)->select('chat_threads.*');
    }
}
