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
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Draft;
use Ramon\Chat\Thread;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * Stores (or clears) the actor's composer draft for a channel or thread scope.
 *
 * Drafts live server-side rather than in localStorage so they follow the user
 * across devices, which is what Discourse does.
 */
class DraftController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body = $request->getParsedBody();
        $attributes = (array) Arr::get($body, 'data.attributes', []);

        $channelId = (int) Arr::get($attributes, 'channelId');

        /** @var Channel|null $channel */
        $channel = Channel::query()->whereVisibleTo($actor)->find($channelId);

        if ($channel === null) {
            throw new ForbiddenException();
        }

        $threadId = Arr::get($attributes, 'threadId');
        $thread = null;

        if ($threadId !== null && $threadId !== '') {
            /** @var Thread|null $thread */
            $thread = Thread::query()
                ->whereVisibleTo($actor)
                ->where('channel_id', $channel->id)
                ->find((int) $threadId);

            if ($thread === null) {
                throw new ForbiddenException();
            }
        }

        $content = Arr::get($attributes, 'content');

        $draft = Draft::store($actor, $channel, $thread, $content === null ? null : (string) $content);

        // An empty draft is a deletion, not an empty record.
        if ($draft === null) {
            return new EmptyResponse(204);
        }

        return new JsonResponse([
            'data' => [
                'type'       => 'chat-drafts',
                'id'         => (string) $draft->id,
                'attributes' => [
                    'channelId' => $draft->channel_id,
                    'threadId'  => $draft->thread_id,
                    'content'   => $draft->content,
                ],
            ],
        ]);
    }
}
