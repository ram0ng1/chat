<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Event;

use Flarum\User\User;
use Ramon\Chat\Channel;

class UserLeftChannel
{
    public function __construct(
        public Channel $channel,
        public User $user,
        public ?User $actor = null,
        /**
         * Whether the membership being ended was a hidden one.
         *
         * The mirror of the same flag on UserJoinedChannel, and it has to travel
         * the same way: the departure of someone whose arrival was never announced
         * must not be announced either, or the room learns after the fact that
         * somebody had been in it — which is precisely what a hidden join exists
         * to avoid.
         *
         * Read from the membership row before it is closed, because afterwards
         * there is no way to tell a hidden member from a visible one who left.
         */
        public bool $hidden = false
    ) {
    }
}
