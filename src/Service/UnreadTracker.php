<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Message;
use Ramon\Chat\MessageMention;
use Ramon\Chat\Thread;
use Ramon\Chat\ThreadUser;

/**
 * Maintains the denormalised unread counters on memberships.
 *
 * These are counters rather than "count rows newer than last_read_message_id"
 * because the sidebar renders every followed channel on every draw; an
 * aggregate per channel per draw does not scale past a handful of channels.
 */
class UnreadTracker
{
    public function __construct(
        protected ConnectionInterface $db
    ) {
    }

    /**
     * Increments unread counters for every member except the sender, and bumps
     * the mention counter for members this message actually mentions.
     */
    public function recordNewMessage(Message $message): void
    {
        $mentionedUserIds = $this->mentionedUserIds($message);

        $base = ChannelUser::query()
            ->where('channel_id', $message->channel_id)
            ->whereNull('left_at')
            ->when(
                $message->user_id !== null,
                fn ($q) => $q->where('user_id', '!=', $message->user_id)
            );

        // Muted memberships are excluded from badge pressure entirely — that is
        // the difference between muting a channel and setting level 0.
        (clone $base)
            ->where('muted', false)
            ->increment('unread_count');

        if ($mentionedUserIds !== []) {
            (clone $base)
                ->whereIn('user_id', $mentionedUserIds)
                ->increment('unread_mentions_count');
        }

        if ($message->thread_id !== null) {
            ThreadUser::query()
                ->where('thread_id', $message->thread_id)
                ->when(
                    $message->user_id !== null,
                    fn ($q) => $q->where('user_id', '!=', $message->user_id)
                )
                ->increment('unread_count');
        }
    }

    /**
     * Recomputes a membership's counters from source. Used when a message is
     * deleted or moved, where decrementing blindly could drift below zero.
     */
    public function recalculate(ChannelUser $membership): ChannelUser
    {
        $lastRead = $membership->last_read_message_id ?? 0;

        // `user_id != x` would silently drop system messages, whose user_id is
        // NULL, and undercount against what recordNewMessage() incremented.
        $unreadQuery = Message::query()
            ->where('channel_id', $membership->channel_id)
            ->where('id', '>', $lastRead)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($membership) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', '!=', $membership->user_id);
            });

        $membership->unread_count = (clone $unreadQuery)->count();

        $membership->unread_mentions_count = (clone $unreadQuery)
            ->whereExists(function ($sub) use ($membership) {
                $sub->selectRaw(1)
                    ->from('chat_message_mentions')
                    ->whereColumn('chat_message_mentions.message_id', 'chat_messages.id')
                    ->where(function ($q) use ($membership) {
                        $q->where(function ($q) use ($membership) {
                            $q->where('chat_message_mentions.type', MessageMention::TYPE_USER)
                                ->where('chat_message_mentions.user_id', $membership->user_id);
                        })->orWhereIn('chat_message_mentions.type', [
                            MessageMention::TYPE_HERE,
                            MessageMention::TYPE_ALL,
                        ]);
                    });
            })
            ->count();

        return $membership;
    }

    /**
     * Marks a channel read up to a message and returns the updated membership.
     * Passing null marks it read up to the channel's latest message.
     */
    public function markChannelRead(Channel $channel, User $actor, ?int $upToMessageId = null): ?ChannelUser
    {
        $membership = $channel->membershipFor($actor);

        if ($membership === null) {
            return null;
        }

        $target = $upToMessageId ?? $channel->last_message_id;

        // Never move the marker backwards — a stale client shipping an old id
        // must not resurrect already-read messages.
        if ($target !== null && ($membership->last_read_message_id ?? 0) > $target) {
            return $membership;
        }

        $membership->markReadUpTo($target);
        $membership->save();

        return $membership;
    }

    public function markThreadRead(Thread $thread, User $actor, ?int $upToMessageId = null): ?ThreadUser
    {
        $membership = $thread->membershipFor($actor);

        if ($membership === null) {
            return null;
        }

        $target = $upToMessageId ?? $thread->last_message_id;

        if ($target !== null && ($membership->last_read_message_id ?? 0) > $target) {
            return $membership;
        }

        $membership->markReadUpTo($target);
        $membership->save();

        return $membership;
    }

    /**
     * The set of users this message notifies by name, expanding group mentions
     * and channel-wide mentions to the channel's membership.
     *
     * @return int[]
     */
    public function mentionedUserIds(Message $message): array
    {
        $mentions = $message->mentions;

        if ($mentions->isEmpty()) {
            return [];
        }

        $userIds = $mentions
            ->where('type', MessageMention::TYPE_USER)
            ->pluck('user_id')
            ->filter()
            ->all();

        $groupIds = $mentions
            ->where('type', MessageMention::TYPE_GROUP)
            ->pluck('group_id')
            ->filter()
            ->all();

        if ($groupIds !== []) {
            $groupMemberIds = $this->db->table('group_user')
                ->whereIn('group_id', $groupIds)
                ->pluck('user_id')
                ->all();

            $userIds = array_merge($userIds, $groupMemberIds);
        }

        $channelWide = $mentions->first(fn (MessageMention $m) => $m->isChannelWide()) !== null;

        if ($channelWide) {
            $memberIds = ChannelUser::query()
                ->where('channel_id', $message->channel_id)
                ->whereNull('left_at')
                ->pluck('user_id')
                ->all();

            $userIds = array_merge($userIds, $memberIds);
        }

        // The author never notifies themselves.
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        return array_values(array_filter(
            $userIds,
            fn (int $id) => $id !== (int) $message->user_id
        ));
    }

    /**
     * Total unread channels for a user, used for the header badge.
     */
    public function totalUnreadFor(User $user): int
    {
        if (! $user->exists) {
            return 0;
        }

        return (int) ChannelUser::query()
            ->where('user_id', $user->id)
            ->where('following', true)
            ->where('muted', false)
            ->whereNull('left_at')
            ->where('unread_count', '>', 0)
            ->count();
    }

    public function totalUnreadMentionsFor(User $user): int
    {
        if (! $user->exists) {
            return 0;
        }

        return (int) ChannelUser::query()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->sum('unread_mentions_count');
    }
}
