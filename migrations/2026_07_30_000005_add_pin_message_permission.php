<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Flarum\Group\Group;

/**
 * Pinning a message is its own right, granted to administrators.
 *
 * Kept out of `moderate` deliberately: moderation is about removing and moving
 * other people's messages, whereas a pin is editorial — it decides what everyone
 * in a channel sees first. A forum may well want those in different hands.
 */
return Migration::addPermissions([
    'ramon-chat.pinMessage' => Group::ADMINISTRATOR_ID,
]);
