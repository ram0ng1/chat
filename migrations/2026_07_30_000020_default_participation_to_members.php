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
 * Leva o padrão de participação para MEMBRO nas instalações já existentes.
 *
 * `2026_07_29_000013_add_default_permissions` passou a semear entrar no chat,
 * abrir DM, anexar e reagir para MEMBER, mas ela já consta como executada em
 * quem instalou antes — e num fórum sem o grupo Moderador ela não semeou nada,
 * deixando o chat sem nenhum grupo não-administrador capaz de abri-lo.
 *
 * Esta migração aplica o mesmo padrão de novo. `Migration::addPermissions` pula
 * a linha que já existe e pula o grupo que não existe, então rodar em cima de
 * uma instalação que já está correta não faz nada.
 *
 * Não remove os grants antigos de MODERADOR: onde eles existem, foram herdados
 * de um padrão anterior e podem já ter virado configuração deliberada do admin —
 * e são inofensivos, porque todo moderador também é membro.
 */
return Migration::addPermissions([
    'ramon-chat.use'         => Group::MEMBER_ID,
    'ramon-chat.startDirect' => Group::MEMBER_ID,
    'ramon-chat.upload'      => Group::MEMBER_ID,
    'ramon-chat.react'       => Group::MEMBER_ID,
]);
