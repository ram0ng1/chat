<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Generates the tokens the E2E harness uses against the local forum.
 *
 * Boots the forum's Flarum application (FLARUM_ROOT, or three levels above
 * this file), creates the test users if they don't exist yet and mints a
 * developer token for each, plus a "remember" token for the user the browser
 * tests will log in as by cookie. Writes everything to
 * tests/E2E/.tokens.json, which is in .gitignore.
 *
 *     php tests/E2E/mint-tokens.php
 *
 * Development forum only. The users created are normal accounts (Member
 * group) named chat_e2e_a, chat_e2e_b and chat_e2e_c; the administrator is
 * user 1.
 *
 * The permissions suite needs three more levels, created the same idempotent
 * way: chat_e2e_mod (Moderator group), chat_e2e_susp (suspended for a year,
 * demoted to guest by flarum/suspend) and chat_e2e_unconf (unconfirmed email,
 * which the core also reduces to guest). They come out under the keys `mod`,
 * `susp` and `unconf`.
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

$extra = [
    'mod'    => ['username' => 'chat_e2e_mod', 'activate' => true],
    'susp'   => ['username' => 'chat_e2e_susp', 'activate' => true],
    'unconf' => ['username' => 'chat_e2e_unconf', 'activate' => false],
];

foreach ($extra as $key => $spec) {
    $user = User::query()->where('username', $spec['username'])->first();

    if ($user === null) {
        $user = new User();
        $user->username = $spec['username'];
        $user->email = $spec['username'].'@e2e.local';
        $user->password = 'chat-e2e-password';
        $user->joined_at = \Carbon\Carbon::now();

        if ($spec['activate']) {
            $user->activate();
        }

        $user->save();
    }

    if ($key === 'mod') {
        $user->groups()->syncWithoutDetaching([\Flarum\Group\Group::MODERATOR_ID]);
    }

    if ($key === 'susp') {
        $user->setAttribute('suspended_until', \Carbon\Carbon::now()->addYear());
        $user->save();
    }

    if ($key === 'unconf' && $user->is_email_confirmed) {
        $user->is_email_confirmed = false;
        $user->save();
    }

    $users[$key] = $user;
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
