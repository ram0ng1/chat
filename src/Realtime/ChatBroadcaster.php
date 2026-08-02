<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;
use Ramon\Chat\Channel;
use Ramon\Chat\Realtime\Job\SendChatEventJob;

/**
 * Delivers chat events over flarum/realtime's websocket.
 *
 * ## Why this never uses the `public` channel
 *
 * flarum/realtime exposes exactly two channel shapes: `public`, which every
 * connected client (including guests) is subscribed to, and `private-user=<id>`,
 * one per authenticated user. There is no per-chat-channel Pusher channel.
 *
 * Chat channels are permission-scoped — a category channel inherits its tag's
 * `viewForum`, direct channels are participants-only, and the whole feature sits
 * behind `ramon-chat.use`. Broadcasting a chat message on `public` would
 * therefore hand every message in every restricted channel to every connected
 * browser, regardless of permissions. So every chat payload is addressed to
 * specific users' private channels instead.
 *
 * ## Why nothing here talks to the daemon
 *
 * This class only enqueues. The HTTP call to the websocket daemon, and the
 * queries that resolve who should receive the event, both happen inside
 * SendChatEventJob — the same shape flarum/realtime uses for its own pushes,
 * where every listener ends in `queue()->push(new SendTriggerJob(...))`.
 *
 * Triggering inline made the send request wait on the daemon and made a daemon
 * the web process cannot reach look like a chat that silently stops updating.
 * Matching core's shape puts chat on the same path as the post broadcasts that
 * already work on any install where realtime works at all.
 *
 * ## Who receives a message
 *
 * The audience is the channel's active members — *not* intersected with who is
 * currently connected. Pusher's channel-list API is eventually consistent, so
 * using it to pick recipients drops messages at random; triggering on an
 * unsubscribed channel is free by comparison. See
 * SendChatEventJob::channelMembers().
 *
 * Restricting to members is deliberate: a non-member reading a public channel
 * picks up new messages on their next fetch rather than by push, which is the
 * correct trade — they have not opted into the channel.
 *
 * For tag-bound category channels the visibility scope is re-checked on top of
 * membership, because a membership row outlives a permission change: someone who
 * joined before the category was restricted still holds a row they can no longer
 * read through.
 */
class ChatBroadcaster
{
    public function __construct(
        protected Container $container,
        protected Queue $queue,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Sends an event to every eligible member of a channel.
     *
     * @param  array<string, mixed>  $payload
     * @param  int|null  $exceptUserId  The actor, who already knows what happened.
     */
    public function toChannelMembers(
        Channel $channel,
        string $event,
        array $payload,
        ?int $exceptUserId = null
    ): void {
        $this->dispatch(new SendChatEventJob(
            event: $event,
            payload: $payload,
            channelId: (int) $channel->id,
            exceptUserId: $exceptUserId
        ));
    }

    /**
     * Sends an event to one user's private channel.
     *
     * @param  array<string, mixed>  $payload
     */
    public function toUser(int $userId, string $event, array $payload): void
    {
        $this->dispatch(new SendChatEventJob(
            event: $event,
            payload: $payload,
            userId: $userId
        ));
    }

    /**
     * Enqueuing must never be able to fail the action that caused the event: the
     * message is already committed, and a client that misses the push reconciles
     * through the API. A queue backend that is down would otherwise turn every
     * send into a 500.
     *
     * Nothing is queued at all without a Pusher binding — PresenceBroadcaster is
     * wired unconditionally, so on a forum with no realtime this is what keeps
     * every keystroke from enqueuing a job that would resolve to a no-op.
     */
    protected function dispatch(SendChatEventJob $job): void
    {
        if (! class_exists(Pusher::class) || ! $this->container->bound(Pusher::class)) {
            return;
        }

        try {
            $this->queue->push($job);
        } catch (\Throwable $e) {
            $this->log->warning('[ramon/chat] realtime dispatch failed: '.$e->getMessage());
        }
    }
}
