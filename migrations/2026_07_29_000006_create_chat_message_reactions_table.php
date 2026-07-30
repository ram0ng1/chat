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
        $schema->create('chat_message_reactions', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('message_id');
            $table->unsignedInteger('user_id');

            // Emoji shortcode without colons, e.g. "heart", "+1".
            $table->string('emoji', 60);

            $table->dateTime('created_at')->nullable();

            $table->unique(['message_id', 'user_id', 'emoji']);
            $table->index('message_id');

            $table->foreign('message_id')->references('id')->on('chat_messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_message_reactions');
    },
];
