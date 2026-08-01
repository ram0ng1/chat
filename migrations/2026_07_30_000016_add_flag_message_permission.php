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
 * Reporting a message, as a right of its own.
 *
 * Seeded to MEMBER: reporting is what an ordinary member does when they see
 * something wrong, and a moderation queue nobody may file into is furniture. It is
 * still revocable, because the queue is only useful while the reports in it are,
 * and a community with a persistent bad-faith reporter needs a way to stop them
 * without silencing them everywhere.
 *
 * *Reading* the queue is `ramon-chat.moderate`, which already exists and already
 * means "may act on other people's messages here".
 *
 * O seed só ocorre quando o grupo existe: um fórum pode tê-lo apagado, e
 * `group_permission.group_id` é FK para `groups` — inserir às cegas aborta a
 * ativação inteira da extensão com SQLSTATE[23000]. Mesmo guard que
 * `Migration::addPermissions` aplica.
 */
return [
    // Flarum's migrator passes a schema Builder, never a ConnectionInterface;
    // typing it as the latter is a TypeError that aborts the whole extension's
    // migration run.
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $exists = $db->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.flagMessage')
            ->exists();

        if ($exists) {
            return;
        }

        if ($db->table('groups')->where('id', Group::MEMBER_ID)->doesntExist()) {
            return;
        }

        $db->table('group_permission')->insert([
            ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.flagMessage'],
        ]);
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('permission', 'ramon-chat.flagMessage')
            ->delete();
    },
];
