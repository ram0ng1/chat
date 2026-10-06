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
 * Who creates and manages channels becomes a switch
 * (`ramon-chat.channel_ownership`) plus three permissions in the "Member
 * channels" section: create channels, manage own channels and edit own channels.
 *
 * Two things happen here, in this order:
 *
 *  1. A forum that already granted `createChannel` to some group other than
 *     administrator is put in "members" mode. The switch defaults to
 *     "administrators", and applying it blindly would revoke, on upgrade, a right
 *     the admin granted on purpose.
 *  2. The three permissions are seeded to the Member group. Inert in
 *     "administrators" mode (the GlobalPolicy denies creation to non-admins, and
 *     ChannelOwnership only reads the other two in "members" mode), so flipping
 *     the switch is all the admin needs to do for members to start creating and
 *     looking after their own channels.
 *
 * The seed only runs when the group exists and the row does not yet: a forum may
 * have deleted the group, and `group_permission.group_id` is an FK to `groups`, so
 * inserting blindly aborts the whole extension's activation with
 * SQLSTATE[23000].
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $settingExists = $db->table('settings')
            ->where('key', 'ramon-chat.channel_ownership')
            ->exists();

        $alreadyDelegated = $db->table('group_permission')
            ->where('permission', 'ramon-chat.createChannel')
            ->where('group_id', '!=', Group::ADMINISTRATOR_ID)
            ->exists();

        if (! $settingExists && $alreadyDelegated) {
            $db->table('settings')->insert([
                'key'   => 'ramon-chat.channel_ownership',
                'value' => 'members',
            ]);
        }

        if ($db->table('groups')->where('id', Group::MEMBER_ID)->doesntExist()) {
            return;
        }

        $permissions = [
            'ramon-chat.createChannel',
            'ramon-chat.manageOwnChannels',
            'ramon-chat.editOwnChannels',
        ];

        foreach ($permissions as $permission) {
            $seeded = $db->table('group_permission')
                ->where('group_id', Group::MEMBER_ID)
                ->where('permission', $permission)
                ->exists();

            if ($seeded) {
                continue;
            }

            $db->table('group_permission')->insert([
                ['group_id' => Group::MEMBER_ID, 'permission' => $permission],
            ]);
        }
    },

    'down' => function (Builder $schema) {
        $db = $schema->getConnection();

        $db->table('settings')
            ->where('key', 'ramon-chat.channel_ownership')
            ->delete();

        $db->table('group_permission')
            ->whereIn('permission', ['ramon-chat.manageOwnChannels', 'ramon-chat.editOwnChannels'])
            ->delete();

        $db->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.createChannel')
            ->delete();
    },
];
