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
use Ramon\Chat\Event\ChannelModeratorChanged;
use Ramon\Chat\Notification\ModeratorPromotedBlueprint;

/**
 * Notifies whoever just became a moderator of a channel.
 *
 * Only promotion notifies. A "you are no longer a moderator" in the bell would
 * read as a reprimand, and the members tab already shows the role; demotion
 * only removes the promotion notice, if it is still there. The previous notice
 * is deleted before a new one, so a second promotion arrives as new, not as an
 * old, already-read row coming back to the list.
 */
class NotifyModeratorChanges
{
    public function __construct(
        protected NotificationSyncer $notifications
    ) {
    }

    public function handle(ChannelModeratorChanged $event): void
    {
        Notification::query()
            ->where('user_id', $event->user->id)
            ->where('type', ModeratorPromotedBlueprint::getType())
            ->where('subject_id', $event->channel->id)
            ->delete();

        if (! $event->isModerator) {
            return;
        }

        if ($event->actor !== null && (int) $event->actor->id === (int) $event->user->id) {
            return;
        }

        if (! $event->user->can('view', $event->channel)) {
            return;
        }

        $this->notifications->sync(
            new ModeratorPromotedBlueprint($event->channel, $event->user, $event->actor),
            [$event->user]
        );
    }
}
