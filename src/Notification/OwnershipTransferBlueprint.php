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
 * "X wants to hand #channel over to you", with accept and decline on the row
 * itself.
 *
 * Only ids in the data. The transfer id separates one request from the next,
 * so a new request shows up as new, and so a closed request leaves the bell
 * without taking another with it (see NotifyOwnershipTransfers).
 */
class OwnershipTransferBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(
        public Channel $channel,
        public ChannelTransfer $transfer,
        public ?User $from = null
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->channel;
    }

    public function getFromUser(): ?User
    {
        return $this->from;
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
        return 'chatOwnershipTransfer';
    }

    public static function getSubjectModel(): string
    {
        return Channel::class;
    }
}
