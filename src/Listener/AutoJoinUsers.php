<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\ChannelWasCreated;
use Ramon\Chat\Service\MembershipManager;

/**
 * Adds existing users to a channel created with `auto_join` set — the "default
 * channel" behaviour Discourse exposes when creating a channel.
 *
 * "Every user" is read as "every user who may use the chat and may see this
 * channel", not as every row in `users`. Joining someone without
 * `ramon-chat.use` writes them a membership they can never open: the channel
 * still feeds their header badge, because the badge sums `unread_count` over
 * memberships rather than re-deriving permission, and it still lists in the
 * sidebar, because loadChannels() filters on `following`. The result is a chat
 * announcing unread traffic to people who have no way to read it — which is
 * what a permission-restricted install reported.
 *
 * Only existing users are handled here. Future users are joined by
 * JoinAutoJoinChannels when their account is created, which has always applied
 * the visibility half of this check.
 */
class AutoJoinUsers
{
    public function __construct(
        protected MembershipManager $memberships
    ) {
    }

    public function handle(ChannelWasCreated $event): void
    {
        $channel = $event->channel;

        if (! $channel->auto_join || $channel->isDirect()) {
            return;
        }

        // Keyed by the actor's sorted group ids — see mayJoin().
        $verdicts = [];

        // Chunked through the query rather than pluck()->all(): a forum with
        // 100k users must not materialise every id to add them.
        $this->candidates()->chunk(500, function ($users) use ($channel, &$verdicts) {
            $eligible = $users
                ->filter(fn (User $user) => $this->mayJoin($channel, $user, $verdicts))
                ->pluck('id')
                ->all();

            $this->memberships->addMany($channel, $eligible);
        });

        $channel->refreshMetadata()->save();
    }

    /**
     * Users whose groups could hold `ramon-chat.use`, narrowed in SQL.
     *
     * Permissions in Flarum are entirely group-derived — see
     * `User::permissions()` — so the set of users who might pass is expressible
     * as a query. Narrowing here rather than per row is what keeps a restricted
     * forum from walking its whole user table to reject it.
     *
     * @return Builder<User>
     */
    protected function candidates(): Builder
    {
        $groupIds = Permission::query()
            ->where('permission', 'ramon-chat.use')
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        /** @var Builder<User> $query */
        $query = User::query()->with('groups')->orderBy('id');

        // Granted to guests, so every account carries it too: `permissions()`
        // puts GUEST_ID in the set unconditionally.
        if (in_array(Group::GUEST_ID, $groupIds, true)) {
            return $query;
        }

        // An unconfirmed account gets guest permissions only, whatever groups it
        // is listed in. Confirmation is what admits MEMBER and the user's own
        // groups, so this is exact rather than an optimisation.
        $query->where('is_email_confirmed', true);

        // Granted to members, which every confirmed account belongs to
        // implicitly — there is nothing left to narrow by.
        if (in_array(Group::MEMBER_ID, $groupIds, true)) {
            return $query;
        }

        // Administrators are never listed in `group_permission`;
        // `hasPermission()` short-circuits for them.
        $groupIds[] = Group::ADMINISTRATOR_ID;

        return $query->whereHas(
            'groups',
            fn (Builder $groups) => $groups->whereIn('groups.id', $groupIds)
        );
    }

    /**
     * Whether this user may be joined, memoised per set of groups.
     *
     * Both halves of the answer are group-derived: `ramon-chat.use` comes from
     * `group_permission`, and channel visibility from the bound tag's
     * `viewForum` plus `ramon-chat.moderate`. The one remaining input to
     * ScopeChannelVisibility is an existing membership, and the channel was
     * created moments ago — so two users in the same groups cannot disagree, and
     * the verdict is worth caching. Without it this is one visibility query per
     * user, which is the N+1 the chunking above exists to avoid.
     *
     * A private auto-join channel therefore admits moderators only, nobody else
     * being able to see it before they are in it. That is the answer
     * JoinAutoJoinChannels already gives on registration, and the two settings
     * are contradictory anyway: "add everyone" against "invitation only".
     *
     * Group processors — flarum/suspend demoting a suspended account to guest —
     * are not modelled, so a suspended user may be joined. They cannot reach the
     * forum to open it and the state is transient, which is not worth a query
     * per row to reflect.
     *
     * @param  array<string, bool>  $verdicts
     */
    protected function mayJoin(Channel $channel, User $user, array &$verdicts): bool
    {
        $signature = $user->groups->pluck('id')->sort()->implode(',');

        if (! isset($verdicts[$signature])) {
            $verdicts[$signature] = $user->can('useChat')
                && Channel::whereVisibleTo($user)->whereKey($channel->id)->exists();
        }

        return $verdicts[$signature];
    }
}
