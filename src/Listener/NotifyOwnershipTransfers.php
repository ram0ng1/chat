<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Notification\Notification;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelTransfer;
use Ramon\Chat\Event\ChannelOwnershipTransferred;
use Ramon\Chat\Event\OwnershipTransferEnded;
use Ramon\Chat\Event\OwnershipTransferRequested;
use Ramon\Chat\Notification\OwnershipInheritedBlueprint;
use Ramon\Chat\Notification\OwnershipTransferBlueprint;
use Ramon\Chat\Notification\OwnershipTransferDeclinedBlueprint;

/**
 * Keeps the transfer request in the recipient's bell while it is open,
 * notifies the initiator when the request is declined, and notifies whoever
 * inherited a channel whose owner left.
 *
 * Each recipient is checked against the channel's visibility before receiving
 * anything: the notification's subject is the channel, and a notice about a
 * channel the person cannot see should not exist.
 */
class NotifyOwnershipTransfers
{
    public function __construct(
        protected NotificationSyncer $notifications
    ) {
    }

    public function whenRequested(OwnershipTransferRequested $event): void
    {
        if (! $event->to->can('view', $event->channel)) {
            return;
        }

        $this->notifications->sync(
            new OwnershipTransferBlueprint($event->channel, $event->transfer, $event->from),
            [$event->to]
        );
    }

    public function whenEnded(OwnershipTransferEnded $event): void
    {
        $this->withdraw($event->channel, $event->transfer);

        if ($event->reason !== OwnershipTransferEnded::DECLINED || $event->actor === null) {
            return;
        }

        /** @var User|null $from */
        $from = User::query()->find($event->transfer->from_user_id);

        if ($from === null || (int) $from->id === (int) $event->actor->id || ! $from->can('view', $event->channel)) {
            return;
        }

        $this->notifications->sync(
            new OwnershipTransferDeclinedBlueprint($event->channel, $event->transfer, $event->actor),
            [$from]
        );
    }

    /**
     * An accepted transfer only removes the request from the bell. A succession
     * notifies the new owner, who asked for nothing and needs to know they are
     * now responsible for the channel.
     */
    public function whenTransferred(ChannelOwnershipTransferred $event): void
    {
        if ($event->transfer !== null) {
            $this->withdraw($event->channel, $event->transfer);
        }

        if (! $event->inherited || ! $event->newOwner->can('view', $event->channel)) {
            return;
        }

        $this->notifications->sync(
            new OwnershipInheritedBlueprint($event->channel, $event->newOwner, $event->previousOwner),
            [$event->newOwner]
        );
    }

    /**
     * Removes the request from the bell of whoever was going to receive it. Via
     * the transfer, not the NotificationSyncer: the request may have been made
     * by someone who no longer exists as a party, and there is only one per
     * channel at a time.
     */
    protected function withdraw(Channel $channel, ChannelTransfer $transfer): void
    {
        Notification::query()
            ->where('user_id', $transfer->to_user_id)
            ->where('type', OwnershipTransferBlueprint::getType())
            ->where('subject_id', $channel->id)
            ->update(['is_deleted' => true]);
    }
}
