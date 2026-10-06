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
 * When the member became a moderator of the channel.
 *
 * Decides who inherits the channel when the owner leaves: the longest-serving
 * moderator (Service\OwnershipSuccession). Roles granted before this column
 * existed stay null and fall back to the date the member joined the channel.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_channel_user', 'moderator_since')) {
            return;
        }

        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->dateTime('moderator_since')->nullable()->after('is_moderator');
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_channel_user', 'moderator_since')) {
            return;
        }

        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->dropColumn('moderator_since');
        });
    },
];
