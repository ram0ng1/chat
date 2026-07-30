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
        $schema->create('chat_uploads', function (Blueprint $table) {
            $table->increments('id');

            // Null until the composer submits — uploads are created first and
            // attached on send, so a cancelled composer leaves orphans for the
            // retention command to sweep.
            $table->unsignedInteger('message_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();

            $table->string('path');
            $table->string('file_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedInteger('size')->default(0);

            // Populated for images so the client can reserve layout space and
            // avoid reflow as attachments load.
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->timestamps();

            $table->index('message_id');
            $table->index(['user_id', 'message_id']);

            $table->foreign('message_id')->references('id')->on('chat_messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_uploads');
    },
];
