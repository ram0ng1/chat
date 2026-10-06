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
 * Quem tem cada cargo criado pelo dono. Um membro pode ter vários; o exibido
 * é o de maior prioridade. `channel_id` repetido aqui para que o livro de
 * cargos de um canal saia numa consulta só, sem passar pelos cargos.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('chat_channel_rank_user')) {
            return;
        }

        $schema->create('chat_channel_rank_user', function (Blueprint $table) {
            $table->unsignedInteger('rank_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('channel_id');
            $table->dateTime('created_at')->nullable();

            $table->unique(['channel_id', 'user_id', 'rank_id']);
            $table->index(['rank_id']);
            $table->index(['user_id']);

            $table->foreign('rank_id')->references('id')->on('chat_channel_ranks')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_channel_rank_user');
    },
];
