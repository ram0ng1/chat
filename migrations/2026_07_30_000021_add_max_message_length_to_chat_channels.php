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
 * Per-channel cap on how long a message may be.
 *
 * Nullable, and null means "whatever the forum is set to" — not "unlimited".
 * The forum-wide `ramon-chat.max_message_length` stays the default for every
 * channel that has no opinion, so raising it once still raises it everywhere,
 * and a channel only stops tracking it when someone deliberately gives that
 * channel a number of its own.
 *
 * Separate from slow mode even though both are per-channel throttles: slow mode
 * limits how often a person may speak, this limits how much they may say at
 * once. A support room wants long messages and slow turns; an announcements
 * room wants the opposite.
 */
return [
    // Flarum's migrator passes a schema Builder, never a ConnectionInterface;
    // typing it as the latter is a TypeError that aborts the whole extension's
    // migration run.
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_channels', 'max_message_length')) {
            return;
        }

        $schema->table('chat_channels', function (Blueprint $table) {
            $table->unsignedInteger('max_message_length')->nullable();
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_channels', 'max_message_length')) {
            return;
        }

        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('max_message_length');
        });
    },
];
