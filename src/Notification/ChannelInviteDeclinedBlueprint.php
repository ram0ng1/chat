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
 * "X declined your invite to #channel."
 *
 * Goes to the channel owner and to whoever invited. Without it the declined
 * invite simply vanished from the pending list, and the inviter kept waiting
 * for an answer that had already been given.
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
     * Only ids and the name: the name is carried because a private channel is
     * not readable through the notification list of someone who already left
     * it.
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
