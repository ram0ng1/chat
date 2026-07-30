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
 * Leaving is announced everywhere: it changes who can read what, so it is never
 * noise.
 *
 * Joining is announced only in direct channels, where being added to a group
 * conversation is a real event. In a category channel, joins are routine — and
 * `auto_join_on_reply` would turn every first reply in the category into a system
 * message, burying the actual conversation.
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

        if (! $event->channel->isDirect()) {
            return;
        }

        $this->announce($event->channel, 'user_joined', $event->user->display_name);
    }

    public function whenLeft(UserLeftChannel $event): void
    {
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
