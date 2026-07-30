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
        $schema->create('chat_message_revisions', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('message_id');

            // The content as it stood *before* the edit that created this row,
            // so replaying revisions oldest-first reconstructs the history.
            $table->mediumText('content')->nullable();

            $table->unsignedInteger('edited_by_id')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['message_id', 'created_at']);

            $table->foreign('message_id')->references('id')->on('chat_messages')->cascadeOnDelete();
            $table->foreign('edited_by_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_message_revisions');
    },
];
