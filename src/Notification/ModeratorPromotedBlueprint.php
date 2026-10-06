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
 * "X made you a moderator of #channel."
 *
 * Only ids in the data: the recipient is a channel member, so the name comes
 * from the channel itself, which the notification list loads as the subject
 * and filters by visibility. The promoted user's id goes in the data so one
 * person's notification never matches another's in the NotificationSyncer.
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
