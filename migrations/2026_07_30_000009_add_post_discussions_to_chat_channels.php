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
 * Announce new discussions from the bound category in the channel.
 *
 * Turns a category channel into the live-chat counterpart of its category: someone
 * starts a discussion, and the room it belongs to hears about it. Only meaningful
 * for a tag-bound channel — there is no category to watch otherwise — which is why
 * it sits beside `auto_join_on_reply` rather than being a global setting.
 *
 * Off by default. An announcement per new discussion is welcome in a quiet
 * category and unbearable in a busy one, so it is opted into per channel.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->boolean('post_discussions')->default(false)->after('auto_join_on_reply');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('post_discussions');
        });
    },
];
