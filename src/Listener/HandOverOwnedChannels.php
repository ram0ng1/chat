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
 * A deleted account stops owning its channels before it disappears: each one
 * goes to the oldest moderator, as if the owner had left. Without this the
 * foreign key would only null the owner, and the moderators would not know
 * the channel now has nobody in charge.
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
