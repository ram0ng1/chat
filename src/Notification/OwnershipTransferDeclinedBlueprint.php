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
use Ramon\Chat\ChannelTransfer;

/**
 * "X recusou receber #canal." Vai para quem iniciou a transferência.
 */
class OwnershipTransferDeclinedBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(
        public Channel $channel,
        public ChannelTransfer $transfer,
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

    public function getData(): array
    {
        return [
            'channelId'  => (int) $this->channel->id,
            'transferId' => (int) $this->transfer->id,
        ];
    }

    public static function getType(): string
    {
        return 'chatOwnershipTransferDeclined';
    }

    public static function getSubjectModel(): string
    {
        return Channel::class;
    }
}
