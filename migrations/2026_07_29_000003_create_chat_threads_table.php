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
        $schema->create('chat_threads', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('channel_id');

            // The channel message the thread hangs off. Nullable only during the
            // brief window where the thread row is written before its root
            // message gets its thread_id back-filled.
            $table->unsignedInteger('original_message_id')->nullable();

            // Author-editable, and what "My Threads" lists.
            $table->string('title', 200)->nullable();

            $table->unsignedInteger('creator_id')->nullable();

            // open | closed | archived
            $table->string('status', 20)->default('open');

            $table->unsignedInteger('replies_count')->default(0);
            $table->unsignedInteger('last_message_id')->nullable();
            $table->dateTime('last_message_at')->nullable();

            $table->timestamps();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['channel_id', 'last_message_at']);
            $table->index('original_message_id');

            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
            $table->foreign('creator_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_threads');
    },
];
