<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\InviteWasCancelled;
use Ramon\Chat\Event\InviteWasDeclined;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UserWasInvited;
use Ramon\Chat\Notification\ChannelInviteBlueprint;
use Ramon\Chat\Notification\ChannelInviteDeclinedBlueprint;
use Ramon\Chat\Service\InvitationManager;

/**
 * Keeps the invite notification in sync with the invites table.
 *
 * The NotificationSyncer receives the full list of who should have the
 * notification (type + channel + inviter): it creates for those who entered
 * the list and deletes for those who left. Calling it with the still-pending
 * invites is what makes an accepted, declined or cancelled invite vanish from
 * the bell without dedicated code for each case.
 */
class NotifyInvitations
{
    public function __construct(
        protected NotificationSyncer $notifications,
        protected InvitationManager $invitations
    ) {
    }

    public function whenInvited(UserWasInvited $event): void
    {
        $this->syncInvites($event->channel, $event->inviter);
    }

    public function whenJoined(UserJoinedChannel $event): void
    {
        if (! $event->acceptedInvite) {
            return;
        }

        $this->syncInvites($event->channel, $event->invitedBy);
    }

    public function whenDeclined(InviteWasDeclined $event): void
    {
        $this->syncInvites($event->channel, $event->inviter);

        $recipients = [];

        foreach ([$event->channel->creator, $event->inviter] as $user) {
            if ($user === null || (int) $user->id === (int) $event->user->id) {
                continue;
            }

            $recipients[(int) $user->id] = $user;
        }

        if ($recipients === []) {
            return;
        }

        $this->notifications->sync(
            new ChannelInviteDeclinedBlueprint($event->channel, $event->user),
            array_values($recipients)
        );
    }

    public function whenCancelled(InviteWasCancelled $event): void
    {
        $this->syncInvites($event->channel, $event->inviter);
    }

    protected function syncInvites(Channel $channel, ?User $inviter): void
    {
        $this->notifications->sync(
            new ChannelInviteBlueprint($channel, $inviter),
            $this->invitations->pendingUsersInvitedBy($channel, $inviter)
        );
    }
}
