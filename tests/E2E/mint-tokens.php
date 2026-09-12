<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Gera os tokens que o harness E2E usa contra o fórum local.
 *
 * Sobe a aplicação Flarum do fórum (FLARUM_ROOT, ou três níveis acima deste
 * arquivo), cria os usuários de teste se ainda não existem e cunha um token de
 * desenvolvedor para cada um, mais um token "remember" para o usuário que os
 * testes de navegador vão logar por cookie. Escreve tudo em
 * tests/E2E/.tokens.json, que está no .gitignore.
 *
 *     php tests/E2E/mint-tokens.php
 *
 * Só para um fórum de desenvolvimento. Os usuários criados são contas normais
 * (grupo Membro) chamadas chat_e2e_a, chat_e2e_b e chat_e2e_c; o administrador
 * é o usuário 1.
 */

use Flarum\Foundation\Site;
use Flarum\Http\DeveloperAccessToken;
use Flarum\Http\RememberAccessToken;
use Flarum\User\User;

$root = getenv('FLARUM_ROOT') ?: dirname(__DIR__, 4);

require $root.'/vendor/autoload.php';

$site = Site::fromPaths([
    'base'    => $root,
    'public'  => $root.'/public',
    'storage' => $root.'/storage',
]);

$site->bootApp();

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);
    exit(1);
});

$users = [];

foreach (['a', 'b', 'c'] as $letter) {
    $username = 'chat_e2e_'.$letter;

    $user = User::query()->where('username', $username)->first();

    if ($user === null) {
        $user = new User();
        $user->username = $username;
        $user->email = $username.'@e2e.local';
        $user->password = 'chat-e2e-password';
        $user->joined_at = \Carbon\Carbon::now();
        $user->activate();
        $user->save();
    }

    $users[$letter] = $user;
}

$admin = User::query()->findOrFail(1);

$out = [
    'admin' => ['id' => (int) $admin->id, 'username' => $admin->username, 'token' => DeveloperAccessToken::generate((int) $admin->id)->token],
];

foreach ($users as $letter => $user) {
    $out[$letter] = [
        'id'       => (int) $user->id,
        'username' => $user->username,
        'token'    => DeveloperAccessToken::generate((int) $user->id)->token,
        'remember' => RememberAccessToken::generate((int) $user->id)->token,
    ];
}

$out['admin']['remember'] = RememberAccessToken::generate((int) $admin->id)->token;

file_put_contents(__DIR__.'/.tokens.json', json_encode($out, JSON_PRETTY_PRINT).PHP_EOL);

echo 'tokens written to tests/E2E/.tokens.json for users: ',
    implode(', ', array_map(fn ($entry) => $entry['username'], $out)), PHP_EOL;
