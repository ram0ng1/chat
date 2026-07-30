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
        $schema->create('chat_channel_user', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('channel_id');
            $table->unsignedInteger('user_id');

            // Membership: following = appears in the user's sidebar.
            $table->boolean('following')->default(true);

            // Per-channel notification level, mirroring Discourse:
            //   2 = always      → notify on every message
            //   1 = mentions    → notify only on @mention (default)
            //   0 = never/muted → no notifications at all
            $table->unsignedTinyInteger('notification_level')->default(1);

            // Suppresses unread badges as well as notifications.
            $table->boolean('muted')->default(false);

            // Read state. unread_count is denormalised so the sidebar can render
            // badges without counting rows per channel on every draw.
            $table->unsignedInteger('last_read_message_id')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->unsignedInteger('unread_mentions_count')->default(0);
            $table->dateTime('last_viewed_at')->nullable();

            // Direct-channel participants can leave without destroying history.
            $table->dateTime('joined_at')->nullable();
            $table->dateTime('left_at')->nullable();

            $table->timestamps();

            $table->unique(['channel_id', 'user_id']);
            $table->index(['user_id', 'following']);
            $table->index(['user_id', 'unread_count']);

            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_channel_user');
    },
];
