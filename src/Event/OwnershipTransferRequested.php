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
 * Quem iniciou confirmou o código: a transferência agora espera a resposta de
 * quem vai receber o canal.
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
