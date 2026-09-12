<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\ChannelInvite;
use Ramon\Chat\Event\InviteWasDeclined;
use Ramon\Chat\Service\InvitationManager;

/**
 * O convidado recusa. O convite some, quem convidou e o dono do canal são
 * avisados (Listener\NotifyInvitations), e nada mais muda: a pessoa continua
 * sem ver o canal, exatamente como antes do convite.
 */
class DeclineInviteController implements RequestHandlerInterface
{
    public function __construct(
        protected Events $events,
        protected InvitationManager $invitations
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $channelId = (int) Arr::get($request->getQueryParams(), 'id');

        $invite = ChannelInvite::query()
            ->where('channel_id', $channelId)
            ->where('user_id', $actor->id)
            ->with(['channel', 'inviter'])
            ->first();

        $channel = $invite?->channel;

        if ($invite === null || $channel === null) {
            throw new ModelNotFoundException();
        }

        $this->invitations->decline($channel, $actor);

        $this->events->dispatch(new InviteWasDeclined($channel, $actor, $invite->inviter));

        return new EmptyResponse(204);
    }
}
