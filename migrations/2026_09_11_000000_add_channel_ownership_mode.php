<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Group\Group;
use Illuminate\Database\Schema\Builder;

/**
 * Quem cria e administra os canais vira um interruptor
 * (`ramon-chat.channel_ownership`) mais três permissões da seção "Canais dos
 * membros": criar canais, gerenciar os próprios canais e editar os próprios
 * canais.
 *
 * Duas coisas acontecem aqui, nesta ordem:
 *
 *  1. Um fórum que já concedia `createChannel` a algum grupo além do
 *     administrador é posto no modo "membros". O padrão do interruptor é
 *     "administradores", e aplicá-lo cegamente revogaria, no upgrade, um direito
 *     que o admin concedeu de propósito.
 *  2. As três permissões são semeadas ao grupo Membro. Inertes no modo
 *     "administradores" — a GlobalPolicy nega a criação a quem não é admin, e
 *     ChannelOwnership só lê as outras duas no modo "membros" — de modo que
 *     virar o interruptor é tudo que o admin precisa fazer para os membros
 *     passarem a criar e cuidar dos próprios canais.
 *
 * O seed só ocorre quando o grupo existe e a linha ainda não: um fórum pode ter
 * apagado o grupo, e `group_permission.group_id` é FK para `groups` — inserir
 * às cegas aborta a ativação inteira da extensão com SQLSTATE[23000].
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $settingExists = $db->table('settings')
            ->where('key', 'ramon-chat.channel_ownership')
            ->exists();

        $alreadyDelegated = $db->table('group_permission')
            ->where('permission', 'ramon-chat.createChannel')
            ->where('group_id', '!=', Group::ADMINISTRATOR_ID)
            ->exists();

        if (! $settingExists && $alreadyDelegated) {
            $db->table('settings')->insert([
                'key'   => 'ramon-chat.channel_ownership',
                'value' => 'members',
            ]);
        }

        if ($db->table('groups')->where('id', Group::MEMBER_ID)->doesntExist()) {
            return;
        }

        $permissions = [
            'ramon-chat.createChannel',
            'ramon-chat.manageOwnChannels',
            'ramon-chat.editOwnChannels',
        ];

        foreach ($permissions as $permission) {
            $seeded = $db->table('group_permission')
                ->where('group_id', Group::MEMBER_ID)
                ->where('permission', $permission)
                ->exists();

            if ($seeded) {
                continue;
            }

            $db->table('group_permission')->insert([
                ['group_id' => Group::MEMBER_ID, 'permission' => $permission],
            ]);
        }
    },

    'down' => function (Builder $schema) {
        $db = $schema->getConnection();

        $db->table('settings')
            ->where('key', 'ramon-chat.channel_ownership')
            ->delete();

        $db->table('group_permission')
            ->whereIn('permission', ['ramon-chat.manageOwnChannels', 'ramon-chat.editOwnChannels'])
            ->delete();

        $db->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.createChannel')
            ->delete();
    },
];
