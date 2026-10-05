<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Apaga, como o endpoint de exclusão faria, canais que a API não deixa a suíte
 * apagar: uma conversa direta só é visível aos participantes, e só um
 * administrador pode excluí-la, então nenhum token sozinho alcança uma conversa
 * entre dois usuários de teste. Só para o fórum de desenvolvimento.
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
