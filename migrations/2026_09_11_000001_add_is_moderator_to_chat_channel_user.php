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
 * Moderador de canal: um papel dado pelo dono a um membro daquele canal.
 *
 * Propriedade da associação, não do usuário, porque a confiança é local — quem
 * modera uma sala não modera as outras. Só é lido no modo "membros"
 * (Service\ChannelOwnership), então um fórum que volta ao modo
 * "administradores" desliga esses papéis sem apagá-los.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_channel_user', 'is_moderator')) {
            return;
        }

        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->boolean('is_moderator')->default(false)->after('hidden');
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_channel_user', 'is_moderator')) {
            return;
        }

        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->dropColumn('is_moderator');
        });
    },
];
