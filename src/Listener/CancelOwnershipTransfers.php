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
 * Encerra a transferência pendente quando ela perde o sentido: uma das partes
 * saiu ou foi removida do canal, ou o canal foi apagado ou arquivado.
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
