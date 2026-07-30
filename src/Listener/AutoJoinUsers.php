<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\User\User;
use Ramon\Chat\Event\ChannelWasCreated;
use Ramon\Chat\Service\MembershipManager;

/**
 * Adds every existing user to a channel created with `auto_join` set — the
 * "default channel" behaviour Discourse exposes when creating a channel.
 *
 * Only existing users are handled here. Future users are joined by
 * JoinAutoJoinChannels when their account is created.
 */
class AutoJoinUsers
{
    public function __construct(
        protected MembershipManager $memberships
    ) {
    }

    public function handle(ChannelWasCreated $event): void
    {
        $channel = $event->channel;

        if (! $channel->auto_join || $channel->isDirect()) {
            return;
        }

        // Chunked through the query rather than pluck()->all(): a forum with
        // 100k users must not materialise every id to add them.
        User::query()
            ->select('id')
            ->orderBy('id')
            ->chunk(500, function ($users) use ($channel) {
                $this->memberships->addMany($channel, $users->pluck('id')->all());
            });

        $channel->refreshMetadata()->save();
    }
}
