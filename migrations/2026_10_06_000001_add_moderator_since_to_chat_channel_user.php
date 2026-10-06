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
 * Quando o membro virou moderador do canal.
 *
 * Decide quem herda o canal quando o dono sai: o moderador mais antigo no
 * papel (Service\OwnershipSuccession). Papéis dados antes desta coluna ficam
 * nulos e caem para a data de entrada no canal.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_channel_user', 'moderator_since')) {
            return;
        }

        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->dateTime('moderator_since')->nullable()->after('is_moderator');
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_channel_user', 'moderator_since')) {
            return;
        }

        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->dropColumn('moderator_since');
        });
    },
];
