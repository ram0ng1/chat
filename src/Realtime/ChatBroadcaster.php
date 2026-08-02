<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
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
 * ## Why nothing here talks to the daemon, and why it still runs now
 *
 * The HTTP call to the websocket daemon, and the queries that resolve who should
 * receive the event, both live in SendChatEventJob. But the job is *run*, not
 * enqueued, unless the forum asks otherwise.
 *
 * flarum/realtime enqueues its own pushes, and chat followed that for one
 * release. It is the wrong trade here. Flarum's database queue driver schedules
 * its worker `everyMinute()` with `--stop-when-empty`
 * (Flarum\Queue\QueueServiceProvider::registerSchedule), so on the queue path a
 * chat message can wait a full minute for delivery. A minute is nothing for the
 * "someone replied to your discussion" push that shape was designed for, and it
 * is not a chat.
 *
 * So the default is immediate, and `ramon-chat.queue_realtime` moves it back
 * onto the queue for the forums that need it: one whose web process cannot reach
 * the daemon, or one large enough that the fan-out belongs off the request. That
 * setting is only sane alongside a continuously running worker.
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
        protected Bus $bus,
        protected Queue $queue,
        protected SettingsRepositoryInterface $settings,
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
     * Running the job must never be able to fail the action that caused the
     * event: the message is already committed, and a client that misses the push
     * reconciles through the API. A daemon that is down would otherwise turn
     * every send into a 500.
     *
     * Nothing happens at all without a Pusher binding — PresenceBroadcaster is
     * wired unconditionally, so on a forum with no realtime this is what keeps
     * every keystroke from running a job that would resolve to a no-op.
     */
    protected function dispatch(SendChatEventJob $job): void
    {
        if (! class_exists(Pusher::class) || ! $this->container->bound(Pusher::class)) {
            return;
        }

        try {
            if ($this->settings->get('ramon-chat.queue_realtime')) {
                $this->queue->push($job);

                return;
            }

            // `dispatchNow`, not `dispatchSync`. They read alike and are not: on a
            // ShouldQueue job `dispatchSync` re-dispatches to the queue named
            // 'sync', and Flarum's QueueFactory::connection() ignores the name it
            // is given and hands back the one configured connection. On a forum
            // using the database driver that quietly puts the job back on the
            // queue the setting above exists to avoid. `dispatchNow` runs it in
            // this process, full stop.
            $this->bus->dispatchNow($job);
        } catch (\Throwable $e) {
            $this->log->warning('[ramon/chat] realtime dispatch failed: '.$e->getMessage());
        }
    }
}
