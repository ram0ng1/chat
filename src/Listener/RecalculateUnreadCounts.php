<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Event\MessageWasMoved;
use Ramon\Chat\Service\UnreadTracker;

/**
 * Keeps unread counters right after a delete or move.
 *
 * One message changes, so only the memberships still counting it change, by
 * one: a deleted message leaves the counters of the members who had not read
 * past it, and a moved one leaves its old channel's and joins its new one's the
 * same way. This used to recount every membership of the channel from source,
 * two COUNTs per member per event, which on a large channel turned each delete
 * into a burst of queries proportional to its size. See UnreadTracker for the
 * targeted updates.
 */
class RecalculateUnreadCounts
{
    public function __construct(
        protected UnreadTracker $unread
    ) {
    }

    public function handle(MessageWasDeleted|MessageWasMoved $event): void
    {
        $message = $event->message;

        if ($event instanceof MessageWasMoved) {
            if ((int) $event->from->id === (int) $event->to->id) {
                return;
            }

            $this->unread->forgetMessage($message, (int) $event->from->id);
            $this->unread->addMovedMessage($message, (int) $event->to->id);

            return;
        }

        $this->unread->forgetMessage($message, (int) $message->channel_id);
    }
}
