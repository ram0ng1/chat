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
 * Transferências de propriedade de canal em andamento.
 *
 * No máximo uma por canal. Guarda só o hash do código enviado por e-mail a
 * quem iniciou, nunca o código. A linha some quando a transferência é aceita,
 * recusada, cancelada ou substituída.
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
