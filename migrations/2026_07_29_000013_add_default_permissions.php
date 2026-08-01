<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Flarum\Group\Group;

/**
 * Padrões do chat: participar é coisa de membro, moderar não.
 *
 * Entrar no chat, abrir uma conversa direta, anexar um arquivo e reagir são o
 * uso ordinário da funcionalidade — um chat que nenhum membro pode abrir não é
 * um chat, é uma sala de equipe. Ficam em MODERADOR apenas a menção que notifica
 * o canal inteiro e a moderação em si.
 *
 * Todo usuário registrado entra implicitamente no grupo Membro
 * (`User::getPermissions()`), então conceder a MEMBER também cobre moderadores.
 * Administradores não recebem linha: `User::hasPermission()` já devolve `true`
 * para eles, e semeá-los duplica o badge no grid — ver a migração
 * `2026_07_30_000010_drop_redundant_admin_permissions`.
 *
 * `Migration::addPermissions` pula grupos que não existem, então um fórum que
 * apagou Moderador não quebra na ativação.
 */
return Migration::addPermissions([
    'ramon-chat.use'                 => Group::MEMBER_ID,
    'ramon-chat.startDirect'         => Group::MEMBER_ID,
    'ramon-chat.upload'              => Group::MEMBER_ID,
    'ramon-chat.react'               => Group::MEMBER_ID,
    'ramon-chat.mentionChannelWide'  => Group::MODERATOR_ID,
    'ramon-chat.moderate'            => Group::MODERATOR_ID,
]);
