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
 * The account a webhook posts as. Null means the chat's bot. Deleting the
 * account drops the webhook back to the bot instead of breaking it.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_webhooks', 'user_id')) {
            return;
        }

        $schema->table('chat_webhooks', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->nullable()->after('channel_id');

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_webhooks', 'user_id')) {
            return;
        }

        $schema->table('chat_webhooks', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    },
];
