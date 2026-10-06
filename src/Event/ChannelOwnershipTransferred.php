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
 * O canal mudou de dono. Disparado depois do commit: `channel` já aponta para
 * o novo dono.
 *
 * Por uma transferência aceita (`transfer` presente; o dono anterior, se ainda
 * era membro, virou moderador do canal) ou por sucessão (`inherited`): o dono
 * saiu e o moderador mais antigo assumiu.
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
