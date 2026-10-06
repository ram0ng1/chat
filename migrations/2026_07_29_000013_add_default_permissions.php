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
 * Chat defaults: taking part is a member thing, moderating is not.
 *
 * Joining the chat, opening a direct conversation, attaching a file and reacting
 * are the ordinary use of the feature: a chat no member can open is not a chat,
 * it is a staff room. Only the mention that notifies the whole channel and
 * moderation itself stay with MODERATOR.
 *
 * Every registered user implicitly joins the Member group
 * (`User::getPermissions()`), so granting to MEMBER also covers moderators.
 * Administrators get no row: `User::hasPermission()` already returns `true` for
 * them, and seeding them duplicates the badge in the grid (see migration
 * `2026_07_30_000010_drop_redundant_admin_permissions`).
 *
 * `Migration::addPermissions` skips groups that do not exist, so a forum that
 * deleted Moderator does not break on activation.
 */
return Migration::addPermissions([
    'ramon-chat.use'                 => Group::MEMBER_ID,
    'ramon-chat.startDirect'         => Group::MEMBER_ID,
    'ramon-chat.upload'              => Group::MEMBER_ID,
    'ramon-chat.react'               => Group::MEMBER_ID,
    'ramon-chat.mentionChannelWide'  => Group::MODERATOR_ID,
    'ramon-chat.moderate'            => Group::MODERATOR_ID,
]);
