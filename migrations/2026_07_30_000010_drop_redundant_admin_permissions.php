<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Flarum\Group\Group;
use Illuminate\Database\Schema\Builder;

/**
 * Removes permissions granted to the Administrator group.
 *
 * Three of this extension's migrations granted their permission to
 * `Group::ADMINISTRATOR_ID`, which is redundant — `User::hasPermission()`
 * short-circuits for administrators, so they already hold every permission — and
 * has a visible cost: core's PermissionDropdown builds its label as
 *
 *     [badgeForId(Group.ADMINISTRATOR_ID), groupIds.map(badgeForId)]
 *
 * The Administrator badge is always prepended, so a permission explicitly held by
 * group 1 renders that badge twice. That is the "duplicated permissions" in the
 * admin panel: not duplicate rows, but the same group listed by both halves of
 * that expression.
 *
 * No extension shipped with Flarum grants to the Administrator group; they grant
 * to members or moderators and let the admin bypass do the rest. The original
 * migrations have been corrected too, so a fresh install never creates these rows
 * and this migration finds nothing to remove.
 *
 * Nobody loses access: an administrator could already do all three things by
 * virtue of being an administrator.
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('group_id', Group::ADMINISTRATOR_ID)
            ->whereIn('permission', [
                'ramon-chat.createChannel',
                'ramon-chat.editChannel',
                'ramon-chat.pinMessage',
            ])
            ->delete();
    },

    'down' => function (Builder $schema) {
        // Deliberately not reinstated. Putting the rows back would restore the
        // duplicate badge without restoring any capability, and `down` should not
        // recreate a defect.
    },
];
