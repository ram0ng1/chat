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
use Ramon\Chat\Message;

class MessageWasMoved
{
    public function __construct(
        public Message $message,
        public Channel $from,
        public Channel $to,
        public ?User $actor = null
    ) {
    }
}
