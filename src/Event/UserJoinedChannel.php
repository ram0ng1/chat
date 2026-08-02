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
        public bool $hidden = false,
        /**
         * Whether the join was a side effect rather than something the user did.
         * `auto_join_on_reply` puts people in a channel for replying in the
         * category it is bound to, which is not an arrival anyone in the room
         * asked to hear about — announcing those turns every first reply into a
         * system row and buries the conversation.
         *
         * On the event rather than inferred from the channel's settings: a
         * channel with `auto_join_on_reply` still receives deliberate joins, and
         * those are worth announcing.
         */
        public bool $automatic = false
    ) {
    }
}
