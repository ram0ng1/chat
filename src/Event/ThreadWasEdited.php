<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Event;

use Flarum\User\User;
use Ramon\Chat\Thread;

class ThreadWasEdited
{
    public function __construct(
        public Thread $thread,
        public ?User $actor = null
    ) {
    }
}
