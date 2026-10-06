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
 * The initiator confirmed the code: the transfer now waits for the answer of
 * whoever is going to receive the channel.
 */
class OwnershipTransferRequested
{
    public function __construct(
        public Channel $channel,
        public ChannelTransfer $transfer,
        public User $from,
        public User $to
    ) {
    }
}
