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
        $schema->create('chat_messages', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('channel_id');
            $table->unsignedInteger('user_id')->nullable();

            // Set when the message belongs to a thread. The thread's root message
            // also carries its own thread_id, so a thread reads as one query.
            $table->unsignedInteger('thread_id')->nullable();

            // Per-channel monotonic sequence. Gives stable ordering and cursor
            // pagination that survives edits and soft deletes, which raw ids
            // cannot do once messages move between channels.
            $table->unsignedInteger('number')->nullable();

            // Inline reply pointer (distinct from threading).
            $table->unsignedInteger('reply_to_id')->nullable();

            $table->mediumText('content')->nullable();

            // text | system. System messages carry a key + JSON data instead of
            // user prose, e.g. "user joined", "channel archived".
            $table->string('type', 20)->default('text');
            $table->string('system_key', 60)->nullable();
            $table->text('system_data')->nullable();

            // Set once the message has been used as a webhook delivery target.
            $table->unsignedInteger('webhook_id')->nullable();

            $table->dateTime('edited_at')->nullable();
            $table->unsignedInteger('edited_by_id')->nullable();

            $table->dateTime('deleted_at')->nullable();
            $table->unsignedInteger('deleted_by_id')->nullable();

            $table->timestamps();

            $table->unique(['channel_id', 'number']);
            $table->index(['channel_id', 'created_at']);
            $table->index(['thread_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('reply_to_id');

            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('thread_id')->references('id')->on('chat_threads')->nullOnDelete();
            $table->foreign('reply_to_id')->references('id')->on('chat_messages')->nullOnDelete();
            $table->foreign('edited_by_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_messages');
    },
];
