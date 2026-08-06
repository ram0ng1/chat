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
 * Backs the stream itself — the most-run query in the extension.
 *
 * A channel opens with `where channel_id = ? order by id desc limit 50`, and
 * pages backwards with the same shape. Every existing index on the table leads
 * with `created_at`, `number` or `updated_at`, so MySQL could narrow to the
 * channel but then had to sort the whole matching set to find the newest fifty:
 * `Using where; Using filesort`. That is free on a channel with a few hundred
 * messages and a full sort of half a million on one that has been busy for a
 * year.
 *
 * With `(channel_id, id)` the plan becomes a backward index scan that stops
 * after fifty rows — measured on this install, the same query goes from
 * `Using filesort` to `Backward index scan`. The optimiser will keep preferring
 * the older index while a channel is small, which is correct: the cost of this
 * one is that it exists, and it starts paying the moment sorting is no longer
 * free.
 *
 * `(thread_id, id)` is the same query for the thread panel.
 *
 * The raw closure form rather than a Migration helper: the helpers cover columns
 * and tables, not indexes. Each index is probed by its column list rather than by
 * a generated name, so the guard does not depend on Laravel's naming scheme
 * staying what it is today.
 *
 * On a forum whose `chat_messages` is already large, this is DDL on a table the
 * chat reads constantly — run it in a quiet window, as with any index build.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('chat_messages')) {
            return;
        }

        $missing = array_filter(
            [['channel_id', 'id'], ['thread_id', 'id']],
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
            [['channel_id', 'id'], ['thread_id', 'id']],
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
