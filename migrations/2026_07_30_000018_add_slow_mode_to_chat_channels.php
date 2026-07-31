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
 * Slow mode: the minimum gap between one person's messages in a channel.
 *
 * Per channel rather than forum-wide, which is the whole point — a support room
 * and a chatter room want different rhythms, and the existing
 * `max_messages_per_second` is a flood guard for the whole install, not a
 * moderation tool a channel owner can reach for.
 *
 * Seconds, with 0 meaning off. Discord stores it the same way, and seconds are
 * what the interface offers: 5s, 30s, 1m, and so on.
 */
return [
    // Flarum's migrator passes a schema Builder, never a ConnectionInterface;
    // typing it as the latter is a TypeError that aborts the whole extension's
    // migration run.
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_channels', 'slow_mode_seconds')) {
            return;
        }

        $schema->table('chat_channels', function (Blueprint $table) {
            $table->unsignedInteger('slow_mode_seconds')->default(0);
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_channels', 'slow_mode_seconds')) {
            return;
        }

        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('slow_mode_seconds');
        });
    },
];
