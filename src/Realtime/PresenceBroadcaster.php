<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime;

use Flarum\User\User;
use Ramon\Chat\Channel;

/**
 * Typing and presence signals.
 *
 * Kept separate from BroadcastListener because it is wired unconditionally —
 * TypingController is always routable — and therefore has to be a safe no-op when
 * flarum/realtime is absent. ChatBroadcaster queues nothing without a Pusher
 * binding, so no extension check is needed here.
 */
class PresenceBroadcaster
{
    public const EVENT_TYPING = 'ramonChat.typing';

    public function __construct(
        protected ChatBroadcaster $broadcaster
    ) {
    }

    public function typing(Channel $channel, User $actor, bool $typing = true): void
    {
        $this->broadcaster->toChannelMembers(
            $channel,
            self::EVENT_TYPING,
            [
                'channelId' => (int) $channel->id,
                'userId'    => (int) $actor->id,
                'username'  => $actor->display_name,
                'typing'    => $typing,
                // The client expires stale indicators on its own; a "stopped
                // typing" event can be lost and the dots must not stick.
                'expiresIn' => 6,
            ],
            (int) $actor->id
        );
    }
}
