<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;
use Ramon\Chat\Channel;

/**
 * "X recusou o seu convite para #canal."
 *
 * Vai para o dono do canal e para quem convidou. Sem isso o convite recusado
 * simplesmente sumia da lista de pendentes, e quem convidou ficava esperando
 * uma resposta que já tinha sido dada.
 */
class ChannelInviteDeclinedBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(
        public Channel $channel,
        public User $decliner
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->channel;
    }

    public function getFromUser(): ?User
    {
        return $this->decliner;
    }

    /**
     * Só ids e o nome: o nome é carregado porque um canal privado não é
     * legível pela lista de notificações de quem já saiu dele.
     */
    public function getData(): array
    {
        return [
            'channelId'   => (int) $this->channel->id,
            'channelName' => (string) ($this->channel->name ?? ''),
            'isPrivate'   => (bool) $this->channel->is_private,
        ];
    }

    public static function getType(): string
    {
        return 'chatChannelInviteDeclined';
    }

    public static function getSubjectModel(): string
    {
        return Channel::class;
    }
}
