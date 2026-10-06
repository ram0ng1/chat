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
 * Empurra um `ramonChat.pong` para o próprio canal privado do ator.
 *
 * Prova a ida e volta inteira (PHP → daemon → navegador) logo após a inscrição,
 * para o cliente desligar o polling sem esperar a primeira mensagem do chat.
 * Limitado a um por usuário a cada 3s; o excedente responde 204 sem empurrar.
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
