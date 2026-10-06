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

/**
 * A channel's ranks changed: one was created, edited, deleted or reordered,
 * or a member gained or lost ranks.
 */
class ChannelRanksChanged
{
    public function __construct(
        public Channel $channel,
        public ?User $actor = null
    ) {
    }
}
