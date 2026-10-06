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
 * Mantém o pedido de transferência no sino de quem recebe enquanto ele está
 * em aberto, avisa quem iniciou quando o pedido é recusado, e avisa quem
 * herdou um canal cujo dono saiu.
 *
 * Cada destinatário é conferido contra a visibilidade do canal antes de
 * receber qualquer coisa: o assunto da notificação é o canal, e um aviso
 * sobre um canal que a pessoa não pode ver não deve existir.
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
     * Uma transferência aceita só tira o pedido do sino. Uma sucessão avisa o
     * novo dono, que não pediu nada e precisa saber que agora responde pelo
     * canal.
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
     * Tira o pedido do sino de quem ia recebê-lo. Pela transferência, e não
     * pelo NotificationSyncer: o pedido pode ter sido feito por alguém que
     * nem existe mais como parte, e só há um por canal de cada vez.
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
