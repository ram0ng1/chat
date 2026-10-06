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
 * The invitee declined. `$inviter` is whoever sent the invite, when the
 * account still exists; who gets told is decided in Listener\NotifyInvitations.
 */
class InviteWasDeclined
{
    public function __construct(
        public Channel $channel,
        public User $user,
        public ?User $inviter = null
    ) {
    }
}
