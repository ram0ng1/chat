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

/**
 * A message was pinned or unpinned.
 *
 * Its own event rather than reusing MessageWasEdited: the content did not change,
 * so nothing that reacts to an edit — revision history, mention re-parsing —
 * should run. Both directions share one event; `$message->isPinned()` says which.
 */
class MessagePinToggled
{
    public function __construct(
        public Message $message,
        public ?User $actor = null
    ) {
    }
}
