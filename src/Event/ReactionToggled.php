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

class ReactionToggled
{
    public function __construct(
        public Message $message,
        public User $actor,
        public string $emoji,
        /** True when the reaction was added, false when it was removed. */
        public bool $added
    ) {
    }
}
