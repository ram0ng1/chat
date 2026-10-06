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
use Ramon\Chat\ChannelTransfer;

/**
 * The channel changed owner. Fired after commit: `channel` already points to
 * the new owner.
 *
 * Either by an accepted transfer (`transfer` present; the previous owner, if
 * still a member, became a moderator of the channel) or by succession
 * (`inherited`): the owner left and the longest-serving moderator took over.
 */
class ChannelOwnershipTransferred
{
    public function __construct(
        public Channel $channel,
        public User $newOwner,
        public ?User $previousOwner,
        public ?ChannelTransfer $transfer,
        public ?User $initiator = null,
        public bool $inherited = false
    ) {
    }
}
