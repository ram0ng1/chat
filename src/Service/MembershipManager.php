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

/**
 * Creates, updates and retires channel memberships, keeping the channel's
 * denormalised user_count in step.
 */
class MembershipManager
{
    public function __construct(
        protected ConnectionInterface $db,
        protected OwnershipSuccession $succession
    ) {
    }

    /**
     * Idempotent: joining a channel you are already in re-activates a previous
     * departure rather than creating a duplicate row, which is what makes
     * "restarting a DM links you back to the earlier messages" work.
     */
    /**
     * @param  bool  $hidden  Join without appearing to anyone else — no entry in the
     *                        member list, no change to `user_count`, and no join
     *                        announcement. For moderators reading a channel without
     *                        their presence changing how people talk in it.
     *
     * Whether anything changed is on the returned row: `wasRecentlyCreated`, or
     * `wasChanged(['left_at', 'hidden'])`. A caller announcing the join reads that
     * instead of assuming, so repeating the request is not a way to repeat the line.
     */
    public function join(
        Channel $channel,
        User $user,
        ?int $notificationLevel = null,
        bool $hidden = false
    ): ChannelUser {
        return $this->db->transaction(function () use ($channel, $user, $notificationLevel, $hidden) {
            /** @var ChannelUser|null $membership */
            $membership = ChannelUser::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $user->id)
                ->first();

            $isNew = $membership === null;
            $wasGone = $membership !== null && $membership->left_at !== null;
            $wasHidden = $membership !== null && (bool) $membership->hidden;

            if ($isNew) {
                $membership = new ChannelUser();
                $membership->channel_id = $channel->id;
                $membership->user_id = $user->id;
                $membership->notification_level = $notificationLevel ?? ChannelUser::LEVEL_MENTIONS;
                $membership->joined_at = Carbon::now();
            }

            $membership->following = true;
            $membership->left_at = null;
            $membership->hidden = $hidden;

            if ($notificationLevel !== null) {
                $membership->notification_level = $notificationLevel;
            }

            // A returning member should not face a badge for everything they
            // missed while away — start them level with the channel.
            if ($wasGone) {
                $membership->markReadUpTo($channel->last_message_id);
            }

            $membership->save();

            // `user_count` is what everyone else sees, so it tracks visible
            // membership only. Each branch below is a transition *of visibility*,
            // not of membership: someone can go from hidden to visible without
            // having joined or left in the meantime.
            $wasVisible = ! $isNew && ! $wasGone && ! $wasHidden;
            $isVisible = ! $hidden;

            if (! $wasVisible && $isVisible) {
                $channel->increment('user_count');
            } elseif ($wasVisible && ! $isVisible) {
                if ($channel->user_count > 0) {
                    $channel->decrement('user_count');
                }
            }

            // The instance memoises its lookups, and the endpoint that called this
            // serialises the same instance straight afterwards — a stale miss here
            // is a member told they are not one.
            $channel->forgetMembership($user);

            return $membership;
        });
    }

    /**
     * Leaves without destroying the membership row, so read state and history
     * survive a rejoin.
     *
     * An owner leaving hands the channel to its oldest moderator in the same
     * transaction (OwnershipSuccession); the announcement goes out after the
     * commit, before the caller announces the departure itself.
     */
    public function leave(Channel $channel, User $user, ?User $actor = null): ?ChannelUser
    {
        $settled = null;

        $membership = $this->db->transaction(function () use ($channel, $user, &$settled) {
            /** @var ChannelUser|null $membership */
            $membership = ChannelUser::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $user->id)
                ->whereNull('left_at')
                ->first();

            if ($membership === null) {
                return null;
            }

            $wasHidden = (bool) $membership->hidden;

            $membership->following = false;
            $membership->left_at = Carbon::now();
            $membership->unread_count = 0;
            $membership->unread_mentions_count = 0;
            $membership->save();

            // A hidden member was never counted, so leaving must not decrement —
            // otherwise a lurker's departure silently undercounts the channel.
            if (! $wasHidden && $channel->user_count > 0) {
                $channel->decrement('user_count');
            }

            $settled = $this->succession->settle($channel, $user);

            $channel->forgetMembership($user);

            return $membership;
        });

        $this->succession->announce($settled, $actor);

        return $membership;
    }

    public function updatePreferences(
        Channel $channel,
        User $user,
        ?int $notificationLevel = null,
        ?bool $muted = null
    ): ?ChannelUser {
        /** @var ChannelUser|null $membership */
        $membership = ChannelUser::query()
            ->where('channel_id', $channel->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        // Only on a live membership. Setting a preference used to imply joining,
        // and that made this a side door past the join policy: a closed channel,
        // or a private one seen through an invitation, could be entered by
        // choosing how loudly it should notify.
        if ($membership === null) {
            return null;
        }

        if ($notificationLevel !== null && in_array($notificationLevel, ChannelUser::levels(), true)) {
            $membership->notification_level = $notificationLevel;
        }

        if ($muted !== null) {
            $membership->muted = $muted;

            // Muting clears outstanding badge pressure so the sidebar settles
            // immediately rather than on the next read.
            if ($muted) {
                $membership->unread_count = 0;
                $membership->unread_mentions_count = 0;
            }
        }

        $membership->save();

        // This row was loaded by its own query, so the instance may be holding a
        // different object for the same membership — drop it rather than let the
        // next read answer with the pre-change preferences.
        $channel->forgetMembership($user);

        return $membership;
    }

    /**
     * Adds users to a channel in bulk. Used by @mention invitations and by the
     * auto-join setting.
     *
     * @param  int[]  $userIds
     * @return int Number of memberships actually created or reactivated.
     */
    public function addMany(Channel $channel, array $userIds): int
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if ($userIds === []) {
            return 0;
        }

        $added = 0;

        // Chunked so an auto-join across a large user base does not build one
        // enormous transaction.
        foreach (array_chunk($userIds, 500) as $chunk) {
            $users = User::query()->whereIn('id', $chunk)->get();

            foreach ($users as $user) {
                $existing = ChannelUser::query()
                    ->where('channel_id', $channel->id)
                    ->where('user_id', $user->id)
                    ->whereNull('left_at')
                    ->exists();

                if ($existing) {
                    continue;
                }

                $this->join($channel, $user);
                $added++;
            }
        }

        return $added;
    }
}
