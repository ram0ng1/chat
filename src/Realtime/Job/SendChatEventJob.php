<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime\Job;

use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;

/**
 * Delivers one chat event to the websocket daemon.
 *
 * A job because flarum/realtime models its own pushes as jobs — see
 * Flarum\Realtime\Push\EventSubscriber — and because it keeps the fan-out and
 * the audience query in one testable place rather than in the listener. But
 * whether it is *queued* or run immediately is ChatBroadcaster's call, and it
 * runs immediately by default: read the note there before changing it.
 *
 * Resolving the audience lives here rather than at the call site so that the
 * queued path, when a forum chooses it, moves the per-member visibility query
 * for tag-bound channels off the request along with the HTTP calls.
 */
class SendChatEventJob extends AbstractJob
{
    /**
     * One attempt. A websocket push is only worth delivering while it is still
     * current: by the time a retry ran, every client will have reconciled the
     * message through the API anyway, and a duplicate push is noise.
     */
    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload
     * @param  int|null  $channelId     Fan out to this channel's members.
     * @param  int|null  $userId        Or deliver to exactly this one user.
     * @param  int|null  $exceptUserId  The actor, who already knows what happened.
     */
    public function __construct(
        protected string $event,
        protected array $payload,
        protected ?int $channelId = null,
        protected ?int $userId = null,
        protected ?int $exceptUserId = null
    ) {
        parent::__construct();
    }

    public function __invoke(Container $container, LoggerInterface $log): void
    {
        $pusher = $this->pusher($container);

        if ($pusher === null) {
            return;
        }

        $recipients = $this->userId !== null
            ? collect([$this->userId])
            : $this->channelMembers();

        if ($recipients->isEmpty()) {
            return;
        }

        // Pusher caps a multi-channel trigger at 100 channels per call.
        foreach ($recipients->chunk(100) as $chunk) {
            $channels = $chunk->map(fn (int $id) => 'private-user='.$id)->values()->all();

            try {
                $pusher->trigger($channels, $this->event, $this->payload);
            } catch (\Throwable $e) {
                // Logged rather than rethrown: the message is already committed,
                // and every client reconciles through the API regardless. Failing
                // the job would only fill the failed-jobs table with events that
                // are stale by the time anyone reads it.
                $log->warning('[ramon/chat] realtime trigger failed: '.$e->getMessage());
            }
        }
    }

    /**
     * @return Collection<int, int> Eligible user ids.
     */
    protected function channelMembers(): Collection
    {
        $channel = Channel::query()->find($this->channelId);

        if ($channel === null) {
            return collect();
        }

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
            ->when($this->exceptUserId !== null, fn ($q) => $q->where('user_id', '!=', $this->exceptUserId))
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
        // `whereKey` rather than `whereIn('id', ...)`: the two do the same thing,
        // but `whereIn` is reached through Eloquent's mixin onto the query builder
        // and comes back typed as that builder, which loses the model type and
        // turns the collection below into one of anonymous rows.
        $users = User::query()->whereKey($memberIds->all())->get();

        return $users
            ->filter(fn (User $user) => Channel::whereVisibleTo($user)->whereKey($channel->id)->exists())
            ->map(fn (User $user) => (int) $user->id)
            ->values();
    }

    /**
     * Null when flarum/realtime is not installed or not booted, which is what
     * makes the job safe to run in an install that has since disabled it.
     */
    protected function pusher(Container $container): ?Pusher
    {
        if (! class_exists(Pusher::class) || ! $container->bound(Pusher::class)) {
            return null;
        }

        try {
            return $container->make(Pusher::class);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
