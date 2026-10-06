<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Deletes, as the delete endpoint would, channels the API won't let the suite
 * delete: a direct conversation is only visible to its participants, and only
 * an administrator can delete it, so no single token reaches a conversation
 * between two test users. Development forum only.
 *
 *     php tests/E2E/lib/purge-channels.php 12 13
 */

use Carbon\Carbon;
use Flarum\Foundation\Site;
use Ramon\Chat\Channel;

$root = getenv('FLARUM_ROOT') ?: dirname(__DIR__, 5);

require $root.'/vendor/autoload.php';

$site = Site::fromPaths([
    'base'    => $root,
    'public'  => $root.'/public',
    'storage' => $root.'/storage',
]);

$site->bootApp();

$ids = array_values(array_filter(array_map('intval', array_slice($argv, 1))));

$done = 0;

foreach (Channel::query()->whereIn('id', $ids)->whereNull('deleted_at')->get() as $channel) {
    $channel->deleted_at = Carbon::now();
    $channel->deleted_by_id = 1;
    $channel->save();
    $done++;
}

echo 'purged '.$done.' of '.count($ids).' channel(s)'.PHP_EOL;
