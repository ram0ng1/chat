<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\User\Event\Registered;
use Ramon\Chat\Channel;
use Ramon\Chat\Service\MembershipManager;

/**
 * Joins a newly registered user to every auto-join channel, so "add all new and
 * existing users" holds for accounts created after the channel.
 */
class JoinAutoJoinChannels
{
    public function __construct(
        protected MembershipManager $memberships
    ) {
    }

    public function handle(Registered $event): void
    {
        $channels = Channel::query()
            ->where('auto_join', true)
            ->where('type', Channel::TYPE_CATEGORY)
            ->where('status', Channel::STATUS_OPEN)
            ->whereNull('deleted_at')
            ->get();

        foreach ($channels as $channel) {
            // Visibility is still checked: an auto-join channel bound to a
            // restricted tag must not pull in users who cannot see that tag.
            if (! Channel::whereVisibleTo($event->user)->whereKey($channel->id)->exists()) {
                continue;
            }

            $this->memberships->join($channel, $event->user);
            $channel->refreshMetadata()->save();
        }
    }
}
