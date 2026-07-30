<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Group\Group;
use Illuminate\Database\Schema\Builder;

/**
 * Reading the chat, as a right separate from taking part in it.
 *
 * `ramon-chat.use` has always meant "participate": it gates the chat as a whole
 * and everything inside it. Making a public channel readable to logged-out
 * visitors needed a narrower right, because granting `use` to guests would also
 * be granting them every ability that defers to it.
 *
 * ## Why this grants nothing to guests
 *
 * Per CLAUDE.md §4, GUEST is group 2, and a permission granted to that group is
 * granted to the entire internet — search engines, scrapers, anyone. Whether the
 * chat should be public is a decision about a particular forum, not a default an
 * extension gets to make on the admin's behalf. So this seeds MEMBER only, and
 * an admin who wants public reading ticks the Guest column themselves.
 */
return [
    // Flarum's migrator passes a schema Builder, never a ConnectionInterface. Typing
    // the parameter as the latter is a TypeError that aborts the *whole extension's*
    // migration run — every later migration silently stops too, and the console
    // reports only "Migrating extension: ramon-chat" before exiting 255. The
    // connection is reached through the builder, which is what the other
    // permission migrations here do.
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        // Scoped to the row this actually seeds. Checking for *any* grant of the
        // permission meant a single unrelated one — an admin who had already
        // ticked Guest, say — suppressed the MEMBER seed entirely, and the
        // migration recorded itself as done. Which is exactly what happened here.
        $exists = $db->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.view')
            ->exists();

        if ($exists) {
            return;
        }

        // MEMBER only. Moderators and administrators inherit through the groups
        // they already hold, and seeding them explicitly is what produced the
        // duplicated badges in the admin permission grid before.
        $db->table('group_permission')->insert([
            ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.view'],
        ]);
    },

    'down' => function (Builder $schema) {
        $db = $schema->getConnection();

        // Only what `up` created. Dropping every grant of the permission would take
        // an admin's own Guest grant with it, which this migration never made.
        $db->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.view')
            ->delete();
    },
];
