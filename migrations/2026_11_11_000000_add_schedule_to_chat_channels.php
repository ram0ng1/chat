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
 * Horário de funcionamento do canal, em JSON (ver Schedule\OpeningHours). Null
 * é sempre aberto.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_channels', 'schedule')) {
            return;
        }

        $schema->table('chat_channels', function (Blueprint $table) {
            $table->text('schedule')->nullable();
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_channels', 'schedule')) {
            return;
        }

        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('schedule');
        });
    },
];
