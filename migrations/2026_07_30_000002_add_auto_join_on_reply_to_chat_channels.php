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
 * Opt-in: subscribe a user to this channel the first time they reply to a
 * discussion in the category it is bound to.
 *
 * Distinct from `auto_join`, which adds *everyone* on the forum up front. This one
 * grows the channel from demonstrated interest — someone who participates in the
 * category gets the matching chat room — which is far less intrusive on a large
 * forum and only meaningful for a tag-bound channel.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->boolean('auto_join_on_reply')->default(false)->after('auto_join');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('auto_join_on_reply');
        });
    },
];
