<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\User\Event\Deleting;
use Ramon\Chat\Service\OwnershipSuccession;

/**
 * Uma conta apagada deixa de ser dona dos seus canais antes de sumir: cada um
 * passa para o moderador mais antigo, como se o dono tivesse saído. Sem isto
 * a chave estrangeira só zeraria o dono, e quem moderava ficaria sem saber
 * que agora o canal não tem ninguém.
 */
class HandOverOwnedChannels
{
    public function __construct(
        protected OwnershipSuccession $succession
    ) {
    }

    public function handle(Deleting $event): void
    {
        $this->succession->handOverAll($event->user);
    }
}
