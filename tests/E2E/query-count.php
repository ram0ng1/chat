<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Conta as queries SQL de cada requisição quente do chat, como um membro.
 *
 * Sobe o fórum no próprio processo, despacha cada requisição pelo handler HTTP
 * do Flarum e agrupa o SQL repetido — o mesmo texto com bindings diferentes é a
 * assinatura de um N+1. Lê os tokens de tests/E2E/.tokens.json.
 *
 *     php tests/E2E/query-count.php <channelId> [verbose]
 */

use Flarum\Foundation\Site;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\QueryExecuted;
use Laminas\Diactoros\ServerRequestFactory;

$root = getenv('FLARUM_ROOT') ?: dirname(__DIR__, 4);

require $root.'/vendor/autoload.php';

$channelId = (int) ($argv[1] ?? 0);
$verbose = isset($argv[2]);
$tokens = json_decode((string) file_get_contents(__DIR__.'/.tokens.json'), true);
$member = $tokens['b'];

$site = Site::fromPaths([
    'base'    => $root,
    'public'  => $root.'/public',
    'storage' => $root.'/storage',
]);

$app = $site->bootApp();
$handler = $app->getRequestHandler();
$container = \Illuminate\Container\Container::getInstance();
$config = $container->make(\Flarum\Foundation\Config::class);
$base = rtrim((string) $config->url(), '/');

/** @var ConnectionInterface $db */
$db = $container->make(ConnectionInterface::class);
$log = [];
$db->listen(function (QueryExecuted $query) use (&$log) {
    $log[] = ['sql' => $query->sql, 'ms' => $query->time];
});

$cases = [
    'channel list'      => ['GET', '/api/chat-channels?filter[following]=true&sort=-lastMessageAt&page[limit]=50', null],
    'channel show'      => ['GET', "/api/chat-channels/$channelId", null],
    'newest 50'         => ['GET', "/api/chat-messages?filter[channel]=$channelId&sort=-id&page[limit]=50", null],
    'poll (empty)'      => ['GET', "/api/chat-messages?filter[channel]=$channelId&filter[greaterThan]=999999999&sort=id&page[limit]=50", null],
    'send'              => ['POST', '/api/chat-messages', ['data' => ['type' => 'chat-messages', 'attributes' => ['content' => 'qc '.uniqid(), 'channelId' => $channelId]]]],
    'read marker'       => ['POST', "/api/chat-channels/$channelId/read", null],
    'forum /api'        => ['GET', '/api', null],
];

foreach ($cases as $name => [$method, $path, $body]) {
    $log = [];
    $uri = $base.$path;
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

    $request = ServerRequestFactory::fromGlobals(
        ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'HTTPS' => 'on', 'HTTP_HOST' => parse_url($base, PHP_URL_HOST)],
        $query
    )
        ->withUri(new \Laminas\Diactoros\Uri($uri))
        ->withMethod($method)
        ->withHeader('Authorization', 'Token '.$member['token'])
        ->withHeader('Accept', 'application/vnd.api+json');

    if ($body !== null) {
        $request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/vnd.api+json');
    }

    $start = microtime(true);
    $response = $handler->handle($request);
    if ($response->getStatusCode() >= 400) { echo "   body: ".mb_substr((string) $response->getBody(), 0, 300)."
"; }
    $elapsed = (microtime(true) - $start) * 1000;

    $groups = [];
    foreach ($log as $entry) {
        $groups[$entry['sql']] = ($groups[$entry['sql']] ?? 0) + 1;
    }
    arsort($groups);

    $dbMs = array_sum(array_column($log, 'ms'));
    printf("%-14s HTTP %d  %3d queries  %5.1f ms db  %6.1f ms total\n", $name, $response->getStatusCode(), count($log), $dbMs, $elapsed);

    foreach ($groups as $sql => $count) {
        if ($count > 1 || $verbose) {
            printf("      x%-3d %s\n", $count, mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 220));
        }
    }
}
