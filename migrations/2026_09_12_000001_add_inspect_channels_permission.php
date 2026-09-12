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
 * Inspecionar um canal sem ninguém perceber, como direito próprio.
 *
 * A entrada oculta (sem lugar na lista de membros, sem anúncio de chegada nem
 * de saída, sem notificação para ninguém) vinha junto com `ramon-chat.moderate`.
 * Isso amarrava duas perguntas: quem age sobre as mensagens dos outros, e quem
 * pode observar uma sala em silêncio. Um fórum pode querer a segunda para um
 * grupo de administração ou de auditoria sem entregar a primeira, e vice-versa.
 *
 * Semeada para cada grupo que já tem `moderate`, para que nenhum moderador
 * perca no upgrade uma capacidade que já usava. Administradores têm toda
 * permissão e não precisam de linha.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $groupIds = $db->table('group_permission')
            ->where('permission', 'ramon-chat.moderate')
            ->where('group_id', '!=', Group::ADMINISTRATOR_ID)
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        foreach ($groupIds as $groupId) {
            $seeded = $db->table('group_permission')
                ->where('group_id', $groupId)
                ->where('permission', 'ramon-chat.inspectChannels')
                ->exists();

            if ($seeded) {
                continue;
            }

            if ($db->table('groups')->where('id', $groupId)->doesntExist()) {
                continue;
            }

            $db->table('group_permission')->insert([
                ['group_id' => $groupId, 'permission' => 'ramon-chat.inspectChannels'],
            ]);
        }
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('permission', 'ramon-chat.inspectChannels')
            ->delete();
    },
];
