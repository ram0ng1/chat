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
use Ramon\Chat\Service\ActionThrottle;
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
        protected PresenceBroadcaster $presence,
        protected ActionThrottle $throttle
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body = $request->getParsedBody();
        $channelId = (int) Arr::get($body, 'data.attributes.channelId');
        $typing = (bool) Arr::get($body, 'data.attributes.typing', true);

        // One signal per state per channel every two seconds, which is already
        // more often than the client sends one. Checked before the channel is
        // looked up, so a flood costs a cache hit rather than a visibility query
        // and a push to every member. Start and stop are counted apart, so the
        // stop that follows a start is never the one dropped. Answered as
        // success: the indicator is best-effort either way.
        if (! $this->throttle->attempt('typing.'.$actor->id.'.'.$channelId.'.'.(int) $typing, 1, 2)) {
            return new EmptyResponse(204);
        }

        /** @var Channel|null $channel */
        $channel = Channel::query()->whereVisibleTo($actor)->find($channelId);

        if ($channel === null) {
            throw new ForbiddenException();
        }

        // Only participants of a channel should be able to signal presence in it.
        if (! $actor->can('postMessage', $channel)) {
            throw new ForbiddenException();
        }

        // Someone inspecting the channel unnoticed must stay unnoticed: a
        // "X is typing" row would give away a presence the member list hides.
        // Answered as success so the client keeps nothing pending.
        if ($channel->membershipFor($actor)?->isHidden()) {
            return new EmptyResponse(204);
        }

        $this->presence->typing(
            $channel,
            $actor,
            $typing
        );

        return new EmptyResponse(204);
    }
}
