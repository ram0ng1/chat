<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelInvite;

/**
 * Creates and ends invites to a channel.
 *
 * Being added to a channel became a question: whoever manages it invites, and
 * the invitee joins or declines. The membership is only born on acceptance,
 * and MembershipManager is what creates it; here only the invite itself
 * exists.
 */
class InvitationManager
{
    /**
     * How long a decline lasts: within this interval the same channel does not
     * invite the same person again, and a "no" does not turn into a queue of
     * notifications. Administrators are exempt, as from every chat limit.
     */
    public const DECLINE_COOLDOWN_SECONDS = 86400;

    public function __construct(
        protected ExtensionManager $extensions,
        protected ChannelOwnership $ownership,
        protected Cache $cache
    ) {
    }

    /**
     * Whether the invitee could join the channel, setting aside the fact that
     * it is private. The invite answers for privacy; the rest still applies:
     * chat must be enabled for the account, a closed channel takes nobody, and
     * a channel tied to a restricted category still requires the category's
     * permission. An invite cannot become a side door around a forum
     * permission.
     */
    public function mayEnter(User $actor, Channel $channel): bool
    {
        if (! $actor->exists || ! $actor->can('useChat')) {
            return false;
        }

        if (! $channel->isOpen()) {
            return false;
        }

        if ($channel->isDirect() || $channel->tag_id === null) {
            return true;
        }

        if (! $this->extensions->isEnabled('flarum-tags')) {
            return false;
        }

        return \Flarum\Tags\Tag::query()
            // @phpstan-ignore method.notFound (flarum/tags model scope)
            ->whereHasPermission($actor, 'viewForum')
            ->whereKey($channel->tag_id)
            ->exists();
    }

    /**
     * Invites whoever is not yet a member, not already invited, and has not
     * declined recently.
     *
     * A hidden member counts as a member only for whoever may know they exist
     * (ChannelOwnership::seesHiddenMembers). For everyone else they are invited
     * like anyone else: skipping the person would reveal their presence through
     * the difference in the response, and they can simply decline the invite.
     *
     * @param  iterable<User>  $users
     * @return ChannelInvite[] The invites created, in the order received.
     */
    public function invite(Channel $channel, iterable $users, User $inviter): array
    {
        $created = [];

        foreach ($users as $user) {
            $membership = $channel->membershipFor($user);

            if ($membership !== null
                && (! $membership->isHidden() || $this->ownership->seesHiddenMembers($inviter))) {
                continue;
            }

            if ($channel->pendingInviteFor($user) !== null) {
                continue;
            }

            if (! $inviter->isAdmin() && $this->cache->has($this->declineKey($channel, $user))) {
                continue;
            }

            $invite = new ChannelInvite();
            $invite->channel_id = $channel->id;
            $invite->user_id = $user->id;
            $invite->inviter_id = $inviter->id;
            $invite->save();

            $invite->setRelation('channel', $channel);
            $invite->setRelation('user', $user);
            $invite->setRelation('inviter', $inviter);

            $channel->forgetInvite($user);

            $created[] = $invite;
        }

        return $created;
    }

    /**
     * Consumes the user's invite, handing it back to whoever will create the
     * membership. Null when there was no invite.
     */
    public function accept(Channel $channel, User $user): ?ChannelInvite
    {
        return $this->remove($channel, $user);
    }

    public function decline(Channel $channel, User $user): ?ChannelInvite
    {
        $invite = $this->remove($channel, $user);

        if ($invite !== null) {
            $this->cache->put($this->declineKey($channel, $user), true, self::DECLINE_COOLDOWN_SECONDS);
        }

        return $invite;
    }

    protected function declineKey(Channel $channel, User $user): string
    {
        return 'ramon-chat.invite-declined.'.$channel->id.'.'.$user->id;
    }

    public function cancel(Channel $channel, User $user): ?ChannelInvite
    {
        return $this->remove($channel, $user);
    }

    /**
     * All of the channel's pending invites made by the same inviter.
     *
     * It is the set the notification sync needs: the NotificationSyncer
     * matches notifications by type, subject and sender, and receives the
     * full list of who should still have theirs.
     *
     * @return User[]
     */
    public function pendingUsersInvitedBy(Channel $channel, ?User $inviter): array
    {
        return ChannelInvite::query()
            ->where('channel_id', $channel->id)
            ->when(
                $inviter !== null,
                fn ($query) => $query->where('inviter_id', $inviter->id),
                fn ($query) => $query->whereNull('inviter_id')
            )
            ->with('user')
            ->get()
            ->map(fn (ChannelInvite $invite) => $invite->user)
            ->filter()
            ->values()
            ->all();
    }

    protected function remove(Channel $channel, User $user): ?ChannelInvite
    {
        return ChannelInvite::query()->getConnection()->transaction(function () use ($channel, $user) {
            /** @var ChannelInvite|null $invite */
            $invite = ChannelInvite::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $user->id)
                ->with('inviter')
                ->first();

            if ($invite === null) {
                return null;
            }

            $invite->delete();

            $invite->setRelation('channel', $channel);
            $invite->setRelation('user', $user);

            $channel->forgetInvite($user);

            return $invite;
        });
    }
}
