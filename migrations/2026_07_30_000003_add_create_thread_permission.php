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
 * Starting a thread off a reply is its own right.
 *
 * Until now anyone who could post in a channel with threading enabled could also
 * branch it. Branching is structural, though — a thread changes how the channel
 * reads for everyone — so who may do it should be separable from who may talk.
 *
 * Granted to members, which is exactly who could already do it (`postMessage`
 * requires membership and `ramon-chat.use` defaults to members). The migration
 * therefore takes nothing away from anyone; it only makes the capability
 * revocable. Administrators pass through User::hasPermission() regardless.
 */
return Migration::addPermissions([
    'ramon-chat.createThread' => Group::MEMBER_ID,
]);
