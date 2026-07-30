<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('chat_drafts', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('user_id');
            $table->unsignedInteger('channel_id');

            // A channel and each of its threads hold independent drafts, so the
            // uniqueness key includes thread_id.
            $table->unsignedInteger('thread_id')->nullable();

            $table->mediumText('content')->nullable();

            $table->timestamps();

            // MySQL treats NULLs as distinct in unique indexes, so channel-level
            // drafts (thread_id IS NULL) are de-duplicated in the repository
            // rather than by this constraint.
            $table->unique(['user_id', 'channel_id', 'thread_id'], 'chat_drafts_scope_unique');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on('chat_threads')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_drafts');
    },
];
