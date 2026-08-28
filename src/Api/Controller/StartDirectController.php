<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Api\Client as ApiClient;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\ChannelWasCreated;
use Ramon\Chat\Service\MembershipManager;
use Throwable;

/**
 * Finds or creates a direct channel for a set of participants.
 *
 * Find-or-create rather than always-create is what makes Discourse's documented
 * behaviour work: "if you accidentally leave a direct message, starting a new
 * chat with the same person links you back to the previously sent messages."
 */
class StartDirectController implements RequestHandlerInterface
{
    protected const MAX_PARTICIPANTS = 20;

    public function __construct(
        protected ConnectionInterface $db,
        protected Events $events,
        protected Translator $translator,
        protected MembershipManager $memberships,
        protected ApiClient $api,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('startDirect');

        $body = $request->getParsedBody();
        $requested = (array) Arr::get($body, 'data.attributes.userIds', []);

        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $requested),
            fn (int $id) => $id > 0 && $id !== (int) $actor->id
        )));

        if ($userIds === []) {
            throw new ValidationException([
                'userIds' => $this->translator->trans('ramon-chat.api.direct_needs_participant'),
            ]);
        }

        if (count($userIds) + 1 > self::MAX_PARTICIPANTS) {
            throw new ValidationException([
                'userIds' => $this->translator->trans('ramon-chat.api.direct_too_many', [
                    'max' => self::MAX_PARTICIPANTS,
                ]),
            ]);
        }

        $users = User::query()->whereIn('id', $userIds)->get();

        if ($users->count() !== count($userIds)) {
            throw new ValidationException([
                'userIds' => $this->translator->trans('ramon-chat.api.direct_unknown_user'),
            ]);
        }

        // The full participant set, actor included, is the identity of the channel.
        $participantIds = $userIds;
        $participantIds[] = (int) $actor->id;
        sort($participantIds);

        $channel = $this->findExisting($participantIds);
        $created = false;

        if ($channel === null) {
            $channel = $this->db->transaction(function () use ($actor, $users, $participantIds) {
                $channel = Channel::build(Channel::TYPE_DIRECT, creator: $actor);
                $channel->save();

                $this->memberships->join($channel, $actor);

                foreach ($users as $user) {
                    $this->memberships->join($channel, $user);
                }

                $channel->refreshMetadata()->save();

                return $channel;
            });

            $created = true;

            $this->events->dispatch(new ChannelWasCreated($channel, $actor));
        } else {
            // Re-joining is idempotent and restores anyone who had left, which is
            // precisely the "links you back" behaviour.
            $this->memberships->join($channel, $actor);
        }

        return new JsonResponse(
            $this->serialize($request, $channel, $created),
            $created ? 201 : 200
        );
    }

    /**
     * The channel as ChannelResource would serve it, so the caller does not have
     * to ask for it again.
     *
     * This used to answer with a hand-rolled stub carrying nothing but the id,
     * which left the client with a channel it could not render and one more round
     * trip before it could: POST here, then GET /chat-channels/{id}, then the
     * first page of messages — three in series before "Send message" on a profile
     * showed anything. Serialising in-process removes the middle one, and it
     * arrives with the same fields, capability flags and `directParticipants` as
     * every other read of a channel, because it *is* that read.
     *
     * `meta.created` is how the client knows a conversation is new. A channel that
     * was just inserted has no messages and nothing pinned, so it can seed both as
     * loaded-and-empty and skip two more requests; a conversation that was found
     * rather than created has history to fetch.
     *
     * `meta.serialized` is false when the internal read failed. The channel exists
     * either way — it has already been committed — so reporting an error here
     * would be a lie about a write that succeeded. The stub is enough for the
     * client to fall back to fetching it itself.
     *
     * @return array<string, mixed>
     */
    protected function serialize(ServerRequestInterface $request, Channel $channel, bool $created): array
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
                $document['meta'] = ['created' => $created, 'serialized' => true];

                return $document;
            }
        } catch (Throwable $e) {
            $this->logger->warning('[ramon-chat] could not serialise a direct channel', [
                'channel' => (int) $channel->id,
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'data' => [
                'type' => 'chat-channels',
                'id'   => (string) $channel->id,
            ],
            'meta' => ['created' => $created, 'serialized' => false],
        ];
    }

    /**
     * A direct channel matches when its *active* participant set is exactly the
     * requested one — no more, no fewer.
     *
     * Leaving a direct channel is a clean break: the departed member keeps no claim
     * on it, and starting a fresh conversation opens a new channel rather than
     * resurrecting the old thread. Discourse reuses the old one instead, but that
     * makes leaving meaningless — you are dropped straight back into the history you
     * walked away from, and the other party cannot tell the difference.
     *
     * The consequence is deliberate: `left_at IS NULL` is part of the match, so a
     * channel anyone has left can never be reused.
     *
     * @param  int[]  $participantIds  Sorted.
     */
    protected function findExisting(array $participantIds): ?Channel
    {
        $count = count($participantIds);
        $prefix = $this->db->getTablePrefix();

        $candidateIds = ChannelUser::query()
            ->select('channel_id')
            ->whereIn('user_id', $participantIds)
            ->whereNull('left_at')
            ->groupBy('channel_id')
            // Every requested participant still present…
            ->havingRaw('COUNT(DISTINCT user_id) = ?', [$count])
            ->pluck('channel_id');

        if ($candidateIds->isEmpty()) {
            return null;
        }

        return Channel::query()
            ->whereIn('id', $candidateIds)
            ->where('type', Channel::TYPE_DIRECT)
            ->whereNull('deleted_at')
            // …and nobody else still present. Counting only active rows means a
            // channel someone walked out of is not a candidate, and a superset
            // that happens to contain everyone requested is excluded too.
            ->whereRaw(
                '(SELECT COUNT(*) FROM '.$prefix.'chat_channel_user cu '.
                'WHERE cu.channel_id = '.$prefix.'chat_channels.id AND cu.left_at IS NULL) = ?',
                [$count]
            )
            ->first();
    }
}
