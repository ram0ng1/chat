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
 * Channel ownership transfers in progress.
 *
 * At most one per channel. Stores only the hash of the code emailed to whoever
 * started it, never the code. The row goes away when the transfer is accepted,
 * declined, cancelled or replaced.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('chat_channel_transfers')) {
            return;
        }

        $schema->create('chat_channel_transfers', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('channel_id');
            $table->unsignedInteger('from_user_id');
            $table->unsignedInteger('to_user_id');

            $table->string('code_hash', 255)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('confirmed_at')->nullable();

            $table->timestamps();

            $table->unique(['channel_id']);
            $table->index(['to_user_id']);
            $table->index(['from_user_id']);

            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
            $table->foreign('from_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('to_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_channel_transfers');
    },
];
