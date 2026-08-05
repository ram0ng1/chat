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
 * Backs the polling fallback's second cursor.
 *
 * `filter[updatedSince]` asks "what changed in this channel — or this thread —
 * since T", and the table's existing indexes are both on `created_at`, so that
 * question was a scan of every message in the channel on every poll. Two
 * compound indexes turn it into a range read, which matters because the query
 * runs for every open client on a forum with no websocket.
 *
 * The raw closure form rather than a Migration helper: the helpers cover columns
 * and tables, not indexes. Each index is probed by its column list rather than
 * by a generated name, so the guard does not depend on Laravel's naming scheme
 * staying what it is today.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('chat_messages')) {
            return;
        }

        $missing = array_filter(
            [['channel_id', 'updated_at'], ['thread_id', 'updated_at']],
            fn (array $columns) => ! $schema->hasIndex('chat_messages', $columns)
        );

        if ($missing === []) {
            return;
        }

        $schema->table('chat_messages', function (Blueprint $table) use ($missing) {
            foreach ($missing as $columns) {
                $table->index($columns);
            }
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasTable('chat_messages')) {
            return;
        }

        $present = array_filter(
            [['channel_id', 'updated_at'], ['thread_id', 'updated_at']],
            fn (array $columns) => $schema->hasIndex('chat_messages', $columns)
        );

        if ($present === []) {
            return;
        }

        $schema->table('chat_messages', function (Blueprint $table) use ($present) {
            foreach ($present as $columns) {
                $table->dropIndex($columns);
            }
        });
    },
];
