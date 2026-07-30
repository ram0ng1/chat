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

class UserJoinedChannel
{
    public function __construct(
        public Channel $channel,
        public User $user,
        public ?User $actor = null,
        /**
         * A hidden join is one nobody else should learn about, so listeners that
         * announce or notify have to be able to tell the difference. Carried on the
         * event rather than re-read from the membership row: by the time a listener
         * runs, the row is the *current* state, and an announcement decision has to
         * be made about the transition that just happened.
         */
        public bool $hidden = false
    ) {
    }
}
