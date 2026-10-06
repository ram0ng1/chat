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
 * Avisa quem acabou de virar moderador de um canal.
 *
 * Só a promoção avisa. Um "você deixou de ser moderador" no sino soaria como
 * repreensão, e a aba de membros já mostra o papel; o rebaixamento só retira
 * o aviso da promoção, se ainda estiver lá. O aviso anterior é apagado antes
 * de um novo, para que uma segunda promoção chegue como nova, e não como uma
 * linha antiga e já lida que volta à lista.
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
