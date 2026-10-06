<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Realtime\ChatBroadcaster;

/**
 * Pushes a `ramonChat.pong` to the actor's own private channel.
 *
 * Proves the whole round trip (PHP → daemon → browser) right after
 * subscribing, so the client can turn polling off without waiting for the
 * first chat message. Limited to one per user every 3s; the excess answers 204
 * without pushing.
 */
class RealtimePingController implements RequestHandlerInterface
{
    public const THROTTLE_SECONDS = 3;

    public function __construct(
        protected ChatBroadcaster $broadcaster,
        protected Cache $cache
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('useChat');

        $userId = (int) $actor->id;

        if ($this->cache->add('ramon-chat.ping.'.$userId, 1, self::THROTTLE_SECONDS)) {
            $this->broadcaster->toUser($userId, 'ramonChat.pong', ['at' => time()]);
        }

        return new EmptyResponse(204);
    }
}
