<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Event\MessageWasMoved;
use Ramon\Chat\Service\UnreadTracker;

/**
 * Rebuilds unread counters after a delete or move.
 *
 * These are the two operations where decrementing would drift: a deleted message
 * may already have been read by some members and not others, and a moved message
 * changes which channel it counts against. Recomputing from source is the only
 * way to stay correct, so it is done per affected membership rather than in bulk.
 */
class RecalculateUnreadCounts
{
    public function __construct(
        protected UnreadTracker $unread
    ) {
    }

    public function handle(MessageWasDeleted|MessageWasMoved $event): void
    {
        $channelIds = [$event->message->channel_id];

        if ($event instanceof MessageWasMoved) {
            $channelIds[] = $event->from->id;
            $channelIds[] = $event->to->id;
        }

        $channelIds = array_values(array_unique(array_filter($channelIds)));

        ChannelUser::query()
            ->whereIn('channel_id', $channelIds)
            ->whereNull('left_at')
            ->where('muted', false)
            ->chunkById(200, function ($memberships) {
                foreach ($memberships as $membership) {
                    $this->unread->recalculate($membership)->save();
                }
            });
    }
}
