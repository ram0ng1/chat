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
 * Skipping slow mode, as a right of its own.
 *
 * Slow mode shipped exempting anyone with `ramon-chat.moderate`, which conflated
 * two questions: who may act on other people's messages, and who the channel's
 * pace applies to. A forum may well want a bot account, a support agent or an
 * announcements author to answer without waiting while holding no moderation
 * power at all — and, going the other way, may want its moderators to observe the
 * same rhythm as everyone else in a channel.
 *
 * Seeded to MODERATOR so nothing changes for the forums already running slow
 * mode; administrators hold every permission and are covered without a row.
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
            ->where('group_id', Group::MODERATOR_ID)
            ->where('permission', 'ramon-chat.bypassSlowMode')
            ->exists();

        if ($exists) {
            return;
        }

        if ($db->table('groups')->where('id', Group::MODERATOR_ID)->doesntExist()) {
            return;
        }

        $db->table('group_permission')->insert([
            ['group_id' => Group::MODERATOR_ID, 'permission' => 'ramon-chat.bypassSlowMode'],
        ]);
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('permission', 'ramon-chat.bypassSlowMode')
            ->delete();
    },
];
