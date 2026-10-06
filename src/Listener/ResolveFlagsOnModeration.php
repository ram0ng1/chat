<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Carbon\Carbon;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Ramon\Chat\Event\FlagsChanged;
use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\MessageFlag;

/**
 * Closes the open reports about a message once it is deleted.
 *
 * Without this the queue keeps showing work already done: a moderator deletes the
 * offending message and the report about it stays open, so the next moderator opens
 * a tombstone and has to work out whether anyone dealt with it. The deletion *is*
 * the resolution.
 *
 * The rows are marked, not removed. A message reported by five people and deleted
 * is part of that author's history, and history that disappears when the evidence
 * does is not history.
 */
class ResolveFlagsOnModeration
{
    public function __construct(
        protected Events $events
    ) {
    }

    public function handle(MessageWasDeleted $event): void
    {
        $closed = MessageFlag::query()
            ->where('message_id', $event->message->id)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at'    => Carbon::now(),
                // Null when the deletion came from somewhere without an actor —
                // retention pruning, for one. The report is still closed; it just
                // has nobody to attribute the decision to.
                'resolved_by_id' => $event->actor?->id,
            ]);

        // Most deletions close nothing, and those must not cost every
        // moderator a recount.
        if ($closed > 0) {
            $this->events->dispatch(new FlagsChanged($event->actor));
        }
    }
}
