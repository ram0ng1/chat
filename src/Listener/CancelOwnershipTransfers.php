<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Ramon\Chat\Event\ChannelWasArchived;
use Ramon\Chat\Event\ChannelWasDeleted;
use Ramon\Chat\Event\UserLeftChannel;
use Ramon\Chat\Service\OwnershipTransfers;

/**
 * Ends the pending transfer when it stops making sense: one of the parties
 * left or was removed from the channel, or the channel was deleted or
 * archived.
 */
class CancelOwnershipTransfers
{
    public function __construct(
        protected OwnershipTransfers $transfers
    ) {
    }

    public function whenLeft(UserLeftChannel $event): void
    {
        $this->transfers->cancelFor($event->channel, $event->user, $event->actor);
    }

    public function whenDeleted(ChannelWasDeleted $event): void
    {
        $this->transfers->cancelFor($event->channel, null, $event->actor);
    }

    public function whenArchived(ChannelWasArchived $event): void
    {
        $this->transfers->cancelFor($event->channel, null, $event->actor);
    }
}
