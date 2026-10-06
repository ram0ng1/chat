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
 * Os cargos de um canal mudaram: um foi criado, editado, apagado ou
 * reordenado, ou um membro ganhou ou perdeu cargos.
 */
class ChannelRanksChanged
{
    public function __construct(
        public Channel $channel,
        public ?User $actor = null
    ) {
    }
}
