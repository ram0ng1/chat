<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UserLeftChannel;
use Ramon\Chat\Service\MessageDispatcher;

/**
 * Posts a system message when someone joins or leaves a channel.
 *
 * Without this, a departure was invisible: the other party kept messaging a
 * conversation the recipient had walked out of, with no indication anything had
 * changed. The system rows are rendered from a locale key rather than user prose —
 * see Message::TYPE_SYSTEM and ChatMessage.systemRow.
 *
 * ## What is and is not announced
 *
 * Arrivals and departures are both announced, in every kind of channel. They are
 * the same fact about who is in the room, and a room that narrates only exits
 * reads as though people keep leaving a channel nobody ever joined.
 *
 * Two exceptions, and both are about the join not being an event at all:
 *
 *  - a hidden join, and the departure that ends it. The point of joining unseen
 *    is that nobody learns of it, which has to include learning of it afterwards.
 *  - an automatic join. `auto_join_on_reply` puts somebody in a channel for
 *    replying in the category it is bound to; announcing those would make a
 *    system row out of every first reply and bury the conversation. This is the
 *    reason category joins went unannounced entirely for a while — the fix is to
 *    tell the two kinds of join apart, not to silence both.
 *
 * The bulk paths never reach here at all: JoinAutoJoinChannels (registration) and
 * AutoJoinUsers (a channel created with `auto_join`) add memberships without
 * dispatching the event, so a channel created with auto-join cannot narrate
 * itself into thousands of rows.
 */
class AnnounceMembershipChanges
{
    public function __construct(
        protected MessageDispatcher $dispatcher,
        protected LoggerInterface $log
    ) {
    }

    public function whenJoined(UserJoinedChannel $event): void
    {
        // The entire point of a hidden join is that it goes unremarked.
        if ($event->hidden) {
            return;
        }

        // A side effect of replying elsewhere is not an arrival.
        if ($event->automatic) {
            return;
        }

        $this->announce($event->channel, 'user_joined', $event->user->display_name);
    }

    public function whenLeft(UserLeftChannel $event): void
    {
        // Somebody whose arrival was never announced cannot have their departure
        // announced either: the room would learn from the exit line that they had
        // been there all along.
        if ($event->hidden) {
            return;
        }

        $this->announce($event->channel, 'user_left', $event->user->display_name);
    }

    protected function announce(Channel $channel, string $key, string $username): void
    {
        // A frozen channel takes no new rows, system or otherwise.
        if (! $channel->acceptsMessages()) {
            return;
        }

        try {
            $this->dispatcher->sendSystem($channel, $key, ['username' => $username]);
        } catch (\Throwable $e) {
            // The membership change already committed. Failing to narrate it must
            // not roll that back or surface as an error to the user who left.
            $this->log->warning('[ramon/chat] could not announce '.$key.': '.$e->getMessage());
        }
    }
}
