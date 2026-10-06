<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Ramon\Chat\Event\ChannelModeratorChanged;
use Ramon\Chat\Event\ChannelOwnershipTransferred;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UserLeftChannel;
use Ramon\Chat\Service\ChannelRanks;

/**
 * Drops a channel's cached rank book when who is a moderator, who is the
 * owner, or who is in the channel changes: only present members show a rank.
 *
 * Registered before realtime, which reads the already rebuilt book to notify
 * the room.
 */
class ForgetChannelRanks
{
    public function __construct(
        protected ChannelRanks $ranks
    ) {
    }

    public function handle(ChannelModeratorChanged|ChannelOwnershipTransferred|UserJoinedChannel|UserLeftChannel $event): void
    {
        $this->ranks->forget($event->channel);
    }
}
