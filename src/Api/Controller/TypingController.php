<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Realtime\PresenceBroadcaster;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * Announces that the actor is typing in a channel.
 *
 * Deliberately fire-and-forget: it returns 204 and never fails the request if
 * broadcasting is unavailable. A typing indicator is the least important thing
 * on the page and must never be able to break sending a message.
 */
class TypingController implements RequestHandlerInterface
{
    public function __construct(
        protected PresenceBroadcaster $presence
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body = $request->getParsedBody();
        $channelId = (int) Arr::get($body, 'data.attributes.channelId');

        /** @var Channel|null $channel */
        $channel = Channel::query()->whereVisibleTo($actor)->find($channelId);

        if ($channel === null) {
            throw new ForbiddenException();
        }

        // Only participants of a channel should be able to signal presence in it.
        if (! $actor->can('postMessage', $channel)) {
            throw new ForbiddenException();
        }

        $this->presence->typing(
            $channel,
            $actor,
            (bool) Arr::get($body, 'data.attributes.typing', true)
        );

        return new EmptyResponse(204);
    }
}
