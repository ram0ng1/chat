<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Draft;

/**
 * All of the actor's drafts, so the client can restore composer state in one
 * request when the chat opens instead of one request per channel.
 */
class ListDraftsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        // Scope to channels still visible: a draft in a channel the actor lost
        // access to must not be handed back.
        $visibleChannelIds = Channel::query()
            ->whereVisibleTo($actor)
            ->pluck('id');

        $drafts = Draft::query()
            ->where('user_id', $actor->id)
            ->whereIn('channel_id', $visibleChannelIds)
            ->get();

        return new JsonResponse([
            'data' => $drafts->map(fn (Draft $draft) => [
                'type'       => 'chat-drafts',
                'id'         => (string) $draft->id,
                'attributes' => [
                    'channelId' => $draft->channel_id,
                    'threadId'  => $draft->thread_id,
                    'content'   => $draft->content,
                ],
            ])->values()->all(),
        ]);
    }
}
