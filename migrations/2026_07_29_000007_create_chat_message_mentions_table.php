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
        $schema->create('chat_message_mentions', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('message_id');

            // user | group | here | all
            $table->string('type', 20);

            // Populated for type=user and type=group respectively. Both null for
            // the channel-wide @here / @all forms.
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('group_id')->nullable();

            $table->dateTime('created_at')->nullable();

            $table->index(['message_id', 'type']);
            $table->index('user_id');

            $table->foreign('message_id')->references('id')->on('chat_messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_message_mentions');
    },
];
