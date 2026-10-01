<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;
use Ramon\Chat\Service\UnreadTracker;

/**
 * Unread counters on the user resource. Only ever exposed to the user themselves,
 * since unread state is private. Each counts through the channels the actor can
 * currently see; see UnreadTracker::visibleMemberships() for why a stored counter
 * is not enough on its own.
 */
class UserResourceFields
{
    public function __construct(
        protected UnreadTracker $unread
    ) {
    }

    public function __invoke(): array
    {
        $isSelf = fn (User $user, Context $context) => $context->getActor()->is($user);

        return [
            Schema\Integer::make('chatUnreadChannelsCount')
                ->visible($isSelf)
                ->get(fn (User $user) => $this->unread->totalUnreadFor($user)),

            // The message count, not the channel count: the drawer header shows
            // "how much am I behind", which is a number of messages.
            Schema\Integer::make('chatUnreadMessagesCount')
                ->visible($isSelf)
                ->get(fn (User $user) => $this->unread->totalUnreadMessagesFor($user)),

            Schema\Integer::make('chatUnreadMentionsCount')
                ->visible($isSelf)
                ->get(fn (User $user) => $this->unread->totalUnreadMentionsFor($user)),
        ];
    }
}
