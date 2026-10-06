<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Channel moderator: a role the owner gives to a member of that channel.
 *
 * A property of the membership, not of the user, because trust is local: whoever
 * moderates one room does not moderate the others. Only read in "members" mode
 * (Service\ChannelOwnership), so a forum that returns to "administrators" mode
 * switches these roles off without deleting them.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_channel_user', 'is_moderator')) {
            return;
        }

        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->boolean('is_moderator')->default(false)->after('hidden');
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_channel_user', 'is_moderator')) {
            return;
        }

        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->dropColumn('is_moderator');
        });
    },
];
