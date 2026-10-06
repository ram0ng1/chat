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
use Illuminate\Contracts\Events\Dispatcher as Events;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\ChannelOwnershipTransferred;

/**
 * Who gets the channel when the owner leaves.
 *
 * The longest-serving moderator in the role (`moderator_since`; for roles
 * predating that column, the channel join date, and finally the membership
 * id) who can actually own channels. With none, the channel is left without an
 * owner: chat moderators and administrators stay in control, and an
 * administrator can hand it to someone through the transfer. The channel never
 * goes to an arbitrary member who was given no trust at all.
 *
 * Only in "members" mode. In "administrators" mode owning grants nothing and
 * the moderator roles are off, so whoever created the channel stays recorded
 * as its creator.
 *
 * `settle()` writes inside the caller's transaction (the channel leave), and
 * `announce()` fires the events after the commit.
 */
class OwnershipSuccession
{
    public function __construct(
        protected ChannelOwnership $ownership,
        protected OwnershipTransfers $transfers,
        protected Events $events
    ) {
    }

    /**
     * Passes the channel on if the one leaving is the owner. Null when they were not.
     *
     * @return array{channel: Channel, previous: User, heir: User|null}|null
     */
    public function settle(Channel $channel, User $leaving): ?array
    {
        if (! $channel->isCategory() || ! $this->ownership->membersOwnChannels()) {
            return null;
        }

        /** @var Channel|null $fresh */
        $fresh = Channel::query()->whereKey($channel->id)->lockForUpdate()->first();

        if ($fresh === null || $fresh->creator_id === null || (int) $fresh->creator_id !== (int) $leaving->id) {
            return null;
        }

        $heir = $this->heir($channel, $leaving);

        $fresh->creator_id = $heir?->id;
        $fresh->save();

        if ($heir !== null) {
            ChannelUser::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $heir->id)
                ->update(['is_moderator' => false, 'moderator_since' => null]);
        }

        $channel->creator_id = $fresh->creator_id;
        $channel->syncOriginalAttribute('creator_id');
        $channel->unsetRelation('creator');
        $channel->forgetMembership();

        return ['channel' => $channel, 'previous' => $leaving, 'heir' => $heir];
    }

    /**
     * What comes after the commit: the pending transfer loses its meaning, and
     * the room learns of the new owner as in an accepted transfer.
     *
     * @param  array{channel: Channel, previous: User, heir: User|null}|null  $settled
     */
    public function announce(?array $settled, ?User $actor = null): void
    {
        if ($settled === null) {
            return;
        }

        $this->transfers->cancelFor($settled['channel'], null, $actor);

        if ($settled['heir'] !== null) {
            $this->events->dispatch(new ChannelOwnershipTransferred(
                $settled['channel'],
                $settled['heir'],
                $settled['previous'],
                null,
                $actor,
                inherited: true
            ));
        }
    }

    /**
     * Both steps in a transaction of their own, for whoever does not leave
     * through the endpoint: a deleted or anonymized account.
     */
    public function handOver(Channel $channel, User $leaving, ?User $actor = null): void
    {
        $settled = $channel->getConnection()->transaction(fn () => $this->settle($channel, $leaving));

        $this->announce($settled, $actor);
    }

    /**
     * All channels the account owns, for deletion and anonymization.
     */
    public function handOverAll(User $leaving): void
    {
        Channel::query()
            ->where('creator_id', $leaving->id)
            ->where('type', Channel::TYPE_CATEGORY)
            ->get()
            ->each(fn (Channel $channel) => $this->handOver($channel, $leaving));
    }

    protected function heir(Channel $channel, User $leaving): ?User
    {
        $candidates = ChannelUser::query()
            ->with('user')
            ->where('channel_id', $channel->id)
            ->where('user_id', '!=', $leaving->id)
            ->whereNull('left_at')
            ->where('hidden', false)
            ->where('is_moderator', true)
            ->orderByRaw('CASE WHEN moderator_since IS NULL THEN 1 ELSE 0 END')
            ->orderBy('moderator_since')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $membership) {
            /** @var ChannelUser $membership */
            $user = $membership->user;

            if ($user !== null && $this->mayOwn($user)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * The same yardstick as the transfer: without `manageOwnChannels` owning
     * would be inert, and the heir would lose the moderator role for nothing.
     */
    protected function mayOwn(User $user): bool
    {
        $suspendedUntil = $user->getAttribute('suspended_until');

        if ($suspendedUntil !== null && Carbon::parse($suspendedUntil)->isFuture()) {
            return false;
        }

        return $user->hasPermission('ramon-chat.manageOwnChannels');
    }
}
