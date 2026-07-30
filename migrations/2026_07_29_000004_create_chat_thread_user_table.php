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
        $schema->create('chat_thread_user', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('thread_id');
            $table->unsignedInteger('user_id');

            // Same scale as chat_channel_user.notification_level. Threads default
            // to "always" for participants — replying to a thread opts you in.
            $table->unsignedTinyInteger('notification_level')->default(2);

            $table->unsignedInteger('last_read_message_id')->nullable();
            $table->unsignedInteger('unread_count')->default(0);

            $table->timestamps();

            $table->unique(['thread_id', 'user_id']);
            $table->index(['user_id', 'unread_count']);

            $table->foreign('thread_id')->references('id')->on('chat_threads')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_thread_user');
    },
];
