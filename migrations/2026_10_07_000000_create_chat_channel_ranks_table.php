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
 * The ranks of each channel: the ones the owner creates and, in a row only
 * when customised, the two built-in ones (owner and moderator).
 *
 * A null `builtin` is a rank created by the owner. A null name and color on a
 * built-in mean the default: the translated name and the theme color.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('chat_channel_ranks')) {
            return;
        }

        $schema->create('chat_channel_ranks', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('channel_id');
            $table->string('builtin', 16)->nullable();
            $table->string('name', 32)->nullable();
            $table->string('color', 7)->nullable();
            $table->string('icon', 64)->nullable();
            $table->boolean('show_badge')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['channel_id', 'builtin']);
            $table->index(['channel_id', 'position']);

            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_channel_ranks');
    },
];
