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
 * Pinned messages.
 *
 * Columns on the message rather than a pivot table: a message is pinned or not,
 * once, for the whole channel — there is no per-user pin. That makes it an
 * attribute of the message, and keeps the pinned list a plain indexed query
 * instead of a join.
 *
 * `pinned_by_id` is kept so the act is attributable; it is nulled on unpin along
 * with the timestamp, so `pinned_at IS NOT NULL` is the single source of truth.
 * ON DELETE SET NULL, because deleting the moderator's account must not delete
 * everyone's pinned messages.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_messages', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('edited_by_id');
            $table->integer('pinned_by_id')->unsigned()->nullable()->after('pinned_at');

            $table->foreign('pinned_by_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            // The pinned list is always scoped to one channel.
            $table->index(['channel_id', 'pinned_at']);
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['pinned_by_id']);
            $table->dropIndex(['channel_id', 'pinned_at']);
            $table->dropColumn(['pinned_at', 'pinned_by_id']);
        });
    },
];
