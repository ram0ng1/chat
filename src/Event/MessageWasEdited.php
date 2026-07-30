<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Event;

use Flarum\User\User;
use Ramon\Chat\Message;

class MessageWasEdited
{
    public function __construct(
        public Message $message,
        public ?User $actor = null
    ) {
    }
}
