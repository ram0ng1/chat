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
        $schema->create('chat_webhooks', function (Blueprint $table) {
            $table->increments('id');

            $table->string('name', 100);
            $table->text('description')->nullable();

            // Display identity for posted messages — a webhook does not need a
            // real user account.
            $table->string('username', 100)->nullable();
            $table->string('emoji', 60)->nullable();

            $table->unsignedInteger('channel_id');

            // Secret path segment used to authenticate deliveries.
            $table->string('key', 64);

            $table->unsignedInteger('creator_id')->nullable();
            $table->boolean('active')->default(true);

            $table->unsignedInteger('deliveries_count')->default(0);
            $table->dateTime('last_delivered_at')->nullable();

            $table->timestamps();

            $table->unique('key');
            $table->index('channel_id');

            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
            $table->foreign('creator_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_webhooks');
    },
];
