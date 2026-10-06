<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Helper for the ownership-transfer suite, development forum only.
 *
 * A transfer's code goes out by email and only its hash is kept in the
 * database, on purpose: no HTTP route reveals it. So the suite can type a
 * code, this script boots the application and writes the hash of a known code
 * onto the channel's pending transfer. It is a test file, outside src/, that
 * the forum never serves.
 *
 *     php tests/E2E/lib/transfer-helper.php code <channelId> <code>
 *     php tests/E2E/lib/transfer-helper.php reset <userId> [<userId> ...]
 *
 * `reset` zeroes these users' hourly limit on started transfers, so running
 * the suite twice in the same hour does not hit it.
 */

use Flarum\Foundation\Site;
use Illuminate\Contracts\Cache\Repository;
use Ramon\Chat\ChannelTransfer;

$root = getenv('FLARUM_ROOT') ?: dirname(__DIR__, 5);

require $root.'/vendor/autoload.php';

$site = Site::fromPaths([
    'base'    => $root,
    'public'  => $root.'/public',
    'storage' => $root.'/storage',
]);

$app = $site->bootApp();

$command = $argv[1] ?? '';

if ($command === 'code') {
    $channelId = (int) ($argv[2] ?? 0);
    $code = (string) ($argv[3] ?? '');

    if ($channelId <= 0 || ! preg_match('/^\d{6}$/', $code)) {
        fwrite(STDERR, "usage: code <channelId> <6 digits>\n");
        exit(1);
    }

    $updated = ChannelTransfer::query()
        ->where('channel_id', $channelId)
        ->whereNull('confirmed_at')
        ->update(['code_hash' => password_hash($code, PASSWORD_DEFAULT), 'attempts' => 0]);

    echo 'code set on '.$updated.' pending transfer(s)'.PHP_EOL;
    exit($updated > 0 ? 0 : 1);
}

if ($command === 'reset') {
    $cache = $app->getContainer()->make(Repository::class);
    $window = intdiv(time(), 3600);

    foreach (array_slice($argv, 2) as $id) {
        $cache->forget('ramon-chat.throttle.transfer.'.(int) $id.'.'.$window);
    }

    echo 'reset '.count(array_slice($argv, 2)).' user(s)'.PHP_EOL;
    exit(0);
}

fwrite(STDERR, "usage: code <channelId> <code> | reset <userId>...\n");
exit(1);
