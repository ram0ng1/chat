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
 * Sending stickers, as a right of its own.
 *
 * Separate from `ramon-chat.use` because a sticker is not an ordinary message: it
 * is large, loud, and the thing a forum most often wants to keep out of a support
 * channel while leaving normal chat open to everyone.
 *
 * Seeded to MEMBER, matching the extension's other conveniences (uploads,
 * reactions). Moderators and administrators reach it through the groups they
 * already hold, so seeding them explicitly would only duplicate the badge in the
 * admin grid.
 */
return [
    // Flarum's migrator passes a schema Builder, never a ConnectionInterface;
    // typing it as the latter is a TypeError that aborts the whole extension's
    // migration run.
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $exists = $db->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.sendStickers')
            ->exists();

        if ($exists) {
            return;
        }

        $db->table('group_permission')->insert([
            ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.sendStickers'],
        ]);
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('permission', 'ramon-chat.sendStickers')
            ->delete();
    },
];
