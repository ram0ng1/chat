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
 * Os cargos de cada canal: os que o dono cria e, numa linha só quando
 * personalizados, os dois embutidos (dono e moderador).
 *
 * `builtin` nulo é um cargo criado pelo dono. Nome e cor nulos num embutido
 * significam o padrão: o nome traduzido e a cor do tema.
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
