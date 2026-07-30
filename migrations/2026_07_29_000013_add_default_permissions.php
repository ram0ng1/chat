<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Discourse ships chat restricted to staff, and admins widen it via
// "chat allowed groups". We mirror that default: moderators and admins get the
// full surface, members get nothing until an admin opts them in.
return Migration::addPermissions([
    'ramon-chat.use'                 => Group::MODERATOR_ID,
    'ramon-chat.startDirect'         => Group::MODERATOR_ID,
    'ramon-chat.upload'              => Group::MODERATOR_ID,
    'ramon-chat.react'               => Group::MODERATOR_ID,
    'ramon-chat.mentionChannelWide'  => Group::MODERATOR_ID,
    'ramon-chat.moderate'            => Group::MODERATOR_ID,
]);
