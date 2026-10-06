<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Event;

use Flarum\User\User;

/**
 * The moderation queue moved: a report was filed, or one or more were closed.
 *
 * Carries no flag: what each moderator counts depends on which messages they
 * can see, so whoever needs the number asks for their own.
 */
class FlagsChanged
{
    public function __construct(
        public ?User $actor = null
    ) {
    }
}
