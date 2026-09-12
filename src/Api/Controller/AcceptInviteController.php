<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Api\Client as ApiClient;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelInvite;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Service\InvitationManager;
use Ramon\Chat\Service\MembershipManager;
use Throwable;

/**
 * O convidado aceita e entra no canal.
 *
 * Uma rota própria, e não o endpoint `join` do resource, porque um canal
 * privado não é visível para quem ainda não é membro: o endpoint nunca
 * encontraria o modelo. Aqui a autorização é o próprio convite, mais a
 * checagem de que o canal seria acessível ao convidado se não fosse privado
 * (InvitationManager::mayEnter), para que um convite não contorne a permissão
 * da categoria.
 *
 * Responde com o canal já serializado pelo ChannelResource, agora que a
 * associação existe e ele é visível: o cliente o coloca na barra lateral e
 * desenha a caixa de mensagem sem uma segunda requisição.
 */
class AcceptInviteController implements RequestHandlerInterface
{
    public function __construct(
        protected Events $events,
        protected MembershipManager $memberships,
        protected InvitationManager $invitations,
        protected ApiClient $api,
        protected LoggerInterface $logger
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

        if ($invite === null || $channel === null || $channel->isDeleted()) {
            throw new ModelNotFoundException();
        }

        if (! $this->invitations->mayEnter($actor, $channel)) {
            throw new PermissionDeniedException();
        }

        $this->invitations->accept($channel, $actor);
        $this->memberships->join($channel, $actor);

        $this->events->dispatch(new UserJoinedChannel(
            $channel,
            $actor,
            $actor,
            acceptedInvite: true,
            invitedBy: $invite->inviter
        ));

        return new JsonResponse($this->serialize($request, $channel));
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(ServerRequestInterface $request, Channel $channel): array
    {
        try {
            $document = json_decode(
                (string) $this->api
                    ->withoutErrorHandling()
                    ->withParentRequest($request)
                    ->get('/chat-channels/'.$channel->id)
                    ->getBody(),
                true
            );

            if (is_array($document) && isset($document['data']['id'])) {
                return $document;
            }
        } catch (Throwable $e) {
            $this->logger->warning('[ramon-chat] could not serialise an accepted channel', [
                'channel' => (int) $channel->id,
                'class'   => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'data' => [
                'type' => 'chat-channels',
                'id'   => (string) $channel->id,
            ],
        ];
    }
}
