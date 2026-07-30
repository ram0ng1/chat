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
use Ramon\Chat\Message;

/**
 * Exposes chat messages to Flarum's global search driver, so a forum-wide search
 * can surface chat alongside discussions.
 *
 * Deleted and system messages are excluded at the searcher level rather than per
 * filter: neither is something a user ever means to find, and excluding them once
 * here keeps every filter from having to remember.
 */
class MessageSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        return Message::whereVisibleTo($actor)
            ->select('chat_messages.*');
    }
}
