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
 * Who may post in a channel.
 *
 * `all` is the default and means "anyone who can be in the channel", which is what
 * every existing channel already was. `moderators` narrows posting to holders of
 * `ramon-chat.moderate` — administrators included, since they hold every permission
 * — turning the channel into an announcement board that members read but do not
 * write to.
 *
 * A string rather than a boolean: "restricted" would name the current pair of
 * states and then be wrong the moment a third is wanted, and the column would have
 * to be migrated to say what it now means.
 *
 * Independent of `is_private`, which decides who can *see* the channel. The two
 * compose: a private channel only moderators may post in is an announcement channel
 * for an invited audience.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->string('post_permission', 20)->default('all')->after('is_private');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('post_permission');
        });
    },
];
