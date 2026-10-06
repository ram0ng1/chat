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

/**
 * A member was made a moderator of a channel, or stopped being one.
 */
class ChannelModeratorChanged
{
    public function __construct(
        public Channel $channel,
        public User $user,
        public bool $isModerator,
        public ?User $actor = null
    ) {
    }
}
