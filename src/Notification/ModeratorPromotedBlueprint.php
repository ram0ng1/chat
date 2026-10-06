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
 * "X fez de você moderador de #canal."
 *
 * Só ids nos dados: quem recebe é membro do canal, então o nome vem do
 * próprio canal, que a lista de notificações carrega como assunto e filtra
 * pela visibilidade. O id de quem foi promovido entra nos dados para que a
 * notificação de uma pessoa nunca case com a de outra no NotificationSyncer.
 */
class ModeratorPromotedBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(
        public Channel $channel,
        public User $user,
        public ?User $actor = null
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->channel;
    }

    public function getFromUser(): ?User
    {
        return $this->actor;
    }

    public function getData(): array
    {
        return [
            'channelId' => (int) $this->channel->id,
            'userId'    => (int) $this->user->id,
        ];
    }

    public static function getType(): string
    {
        return 'chatModeratorPromoted';
    }

    public static function getSubjectModel(): string
    {
        return Channel::class;
    }
}
