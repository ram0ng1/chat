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
 * A message was removed outright, tombstone and all.
 *
 * Carries ids and the channel rather than the Message, unlike its siblings: by
 * the time anything can listen, the row is gone. The channel is passed because
 * the broadcaster needs it to work out who was in the conversation, and it can
 * no longer be reached through the message.
 */
class MessageWasPurged
{
    public function __construct(
        public int $messageId,
        public Channel $channel,
        public ?int $threadId = null,
        public ?User $actor = null
    ) {
    }
}
