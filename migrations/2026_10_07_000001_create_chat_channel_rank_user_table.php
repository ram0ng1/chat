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
 * Who holds each owner-created rank. A member can hold several; the one shown
 * is the highest priority. `channel_id` is repeated here so a channel's rank
 * book comes out in a single query, without going through the ranks.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('chat_channel_rank_user')) {
            return;
        }

        $schema->create('chat_channel_rank_user', function (Blueprint $table) {
            $table->unsignedInteger('rank_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('channel_id');
            $table->dateTime('created_at')->nullable();

            $table->unique(['channel_id', 'user_id', 'rank_id']);
            $table->index(['rank_id']);
            $table->index(['user_id']);

            $table->foreign('rank_id')->references('id')->on('chat_channel_ranks')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_channel_rank_user');
    },
];
