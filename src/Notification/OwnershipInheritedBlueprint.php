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
 * "X left #channel, and you are now the owner."
 *
 * Goes to the moderator who inherited the channel. Only ids in the data; the
 * name comes from the channel, which is the subject. The heir's id separates
 * one succession from the next in the NotificationSyncer.
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
