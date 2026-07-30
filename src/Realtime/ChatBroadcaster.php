<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime;

use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;

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
 * ## Who receives a message
 *
 * The audience is the channel's active members — *not* intersected with who is
 * currently connected. Pusher's channel-list API is eventually consistent, so
 * using it to pick recipients drops messages at random; triggering on an
 * unsubscribed channel is free by comparison. See recipients().
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
        $pusher = $this->pusher();

        if ($pusher === null) {
            return;
        }

        $recipients = $this->recipients($pusher, $channel, $exceptUserId);

        if ($recipients->isEmpty()) {
            return;
        }

        // Pusher caps a multi-channel trigger at 100 channels per call.
        foreach ($recipients->chunk(100) as $chunk) {
            $channels = $chunk->map(fn (int $id) => 'private-user='.$id)->values()->all();

            try {
                $pusher->trigger($channels, $event, $payload);
            } catch (\Throwable $e) {
                // A websocket delivery failure must never surface as a failed
                // send: the message is already committed, and the client will
                // reconcile on its next fetch.
                $this->log->warning('[ramon/chat] realtime trigger failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Sends an event to one user's private channel.
     *
     * @param  array<string, mixed>  $payload
     */
    public function toUser(int $userId, string $event, array $payload): void
    {
        $pusher = $this->pusher();

        if ($pusher === null) {
            return;
        }

        try {
            $pusher->trigger('private-user='.$userId, $event, $payload);
        } catch (\Throwable $e) {
            $this->log->warning('[ramon/chat] realtime trigger failed: '.$e->getMessage());
        }
    }

    /**
     * @return Collection<int, int> Eligible user ids.
     */
    protected function recipients(Pusher $pusher, Channel $channel, ?int $exceptUserId): Collection
    {
        // Deliberately NOT filtered by who is currently connected.
        //
        // Pusher's channel-list API is eventually consistent: a client that just
        // subscribed may not appear yet, and one that just dropped may still be
        // listed. Intersecting the audience with it therefore loses messages at
        // random — which is exactly the "realtime works most of the time" symptom.
        //
        // Triggering on a channel nobody is subscribed to is free: the daemon
        // discards it. Paying for that is strictly better than a race, and it also
        // removes an HTTP round-trip to the daemon per broadcast.
        $memberIds = ChannelUser::query()
            ->where('channel_id', $channel->id)
            ->whereNull('left_at')
            ->when($exceptUserId !== null, fn ($q) => $q->where('user_id', '!=', $exceptUserId))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);

        if ($memberIds->isEmpty()) {
            return collect();
        }

        // For a direct channel, membership *is* the visibility rule — the scope
        // resolves through the same `chat_channel_user` rows we just read. Nothing
        // further to check, and no per-user query.
        if ($channel->isDirect() || $channel->tag_id === null) {
            return $memberIds->values();
        }

        // A tag-bound channel inherits the tag's `viewForum`, and a membership row
        // outlives a permission change — so someone who joined before the category
        // was restricted must not keep receiving pushes. This costs one query per
        // member, which is why it is confined to the case that actually needs it.
        $users = User::query()->whereIn('id', $memberIds->all())->get();

        return $users
            ->filter(fn (User $user) => Channel::whereVisibleTo($user)->whereKey($channel->id)->exists())
            ->map(fn (User $user) => (int) $user->id)
            ->values();
    }


    /**
     * Null when flarum/realtime is not installed or not booted, which is what
     * makes every caller safe to invoke unconditionally.
     */
    protected function pusher(): ?Pusher
    {
        if (! class_exists(Pusher::class) || ! $this->container->bound(Pusher::class)) {
            return null;
        }

        try {
            return $this->container->make(Pusher::class);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
