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
 * "X saiu de #canal, e agora você é o dono."
 *
 * Vai para o moderador que herdou o canal. Só ids nos dados; o nome vem do
 * canal, que é o assunto. O id de quem herdou separa uma sucessão da seguinte
 * no NotificationSyncer.
 */
class OwnershipInheritedBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(
        public Channel $channel,
        public User $owner,
        public ?User $previousOwner = null
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->channel;
    }

    public function getFromUser(): ?User
    {
        return $this->previousOwner;
    }

    public function getData(): array
    {
        return [
            'channelId' => (int) $this->channel->id,
            'userId'    => (int) $this->owner->id,
        ];
    }

    public static function getType(): string
    {
        return 'chatOwnershipInherited';
    }

    public static function getSubjectModel(): string
    {
        return Channel::class;
    }
}
