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
 * Inspecting a channel unnoticed, as a right of its own.
 *
 * Hidden entry (no place in the member list, no join or leave announcement, no
 * notification to anyone) used to come bundled with `ramon-chat.moderate`.
 * That tied together two questions: who acts on other people's messages, and
 * who may watch a room in silence. A forum may want the second for an admin or
 * audit group without granting the first, and vice versa.
 *
 * Seeded for every group that already has `moderate`, so no moderator loses a
 * capability they already used on upgrade. Administrators hold every
 * permission and need no row.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $groupIds = $db->table('group_permission')
            ->where('permission', 'ramon-chat.moderate')
            ->where('group_id', '!=', Group::ADMINISTRATOR_ID)
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        foreach ($groupIds as $groupId) {
            $seeded = $db->table('group_permission')
                ->where('group_id', $groupId)
                ->where('permission', 'ramon-chat.inspectChannels')
                ->exists();

            if ($seeded) {
                continue;
            }

            if ($db->table('groups')->where('id', $groupId)->doesntExist()) {
                continue;
            }

            $db->table('group_permission')->insert([
                ['group_id' => $groupId, 'permission' => 'ramon-chat.inspectChannels'],
            ]);
        }
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('permission', 'ramon-chat.inspectChannels')
            ->delete();
    },
];
