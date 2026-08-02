<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Neutralizada. Era uma migração de re-semeadura, e re-semear é justamente o que
 * uma extensão não pode fazer.
 *
 * A versão anterior reaplicava `ramon-chat.use`, `startDirect`, `upload` e
 * `react` ao grupo Membro para cobrir instalações antigas que nunca receberam o
 * padrão de `2026_07_29_000013`. O efeito colateral era que qualquer fórum que
 * tivesse revogado essas permissões de propósito as recebia de volta na
 * atualização seguinte — a extensão sobrescrevendo, calada, a decisão do admin.
 *
 * Semear o padrão na primeira instalação é legítimo e continua em
 * `2026_07_29_000013`. Reaplicá-lo depois não é: dali em diante a configuração de
 * permissões pertence ao fórum, e a extensão só lê.
 *
 * O arquivo fica no lugar em vez de ser apagado: o nome dele já está gravado na
 * tabela `migrations` de quem atualizou, e removê-lo não desfaz nada — apenas
 * apagaria o registro de por que ele existiu.
 */
return [
    'up'   => fn () => null,
    'down' => fn () => null,
];
