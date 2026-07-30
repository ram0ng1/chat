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
 * Lurking: a membership nobody else can see.
 *
 * A moderator sometimes needs to read a channel — a private one especially —
 * without their arrival changing how people talk in it. An ordinary join cannot do
 * that: it announces itself in a direct channel, adds a row to the member list and
 * moves the member count.
 *
 * A hidden membership is a real membership for everything that concerns the
 * moderator (visibility, unread tracking, the sidebar), and invisible for
 * everything that concerns everyone else (the participants list, `user_count`, the
 * join and leave announcements). It is deliberately a property of the membership
 * rather than of the user, so a moderator can lurk in one channel and take part
 * openly in another.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->boolean('hidden')->default(false)->after('following');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->dropColumn('hidden');
        });
    },
];
