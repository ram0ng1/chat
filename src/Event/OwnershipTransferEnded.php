<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Event;

use Flarum\User\User;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelTransfer;

/**
 * A transfer ended without changing the owner: declined by the recipient,
 * cancelled, or replaced by a new one. The row is already deleted; `transfer`
 * is the in-memory copy, for whoever needs to know who the parties were.
 */
class OwnershipTransferEnded
{
    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public const REPLACED = 'replaced';

    public function __construct(
        public Channel $channel,
        public ChannelTransfer $transfer,
        public string $reason,
        public ?User $actor = null
    ) {
    }
}
