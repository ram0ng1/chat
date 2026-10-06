<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Mail\Job\SendInformationalEmailJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Queue\Queue;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelTransfer;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\ChannelOwnershipTransferred;
use Ramon\Chat\Event\OwnershipTransferEnded;
use Ramon\Chat\Event\OwnershipTransferRequested;

/**
 * Passes a channel from one owner to another, in three steps.
 *
 * 1. Whoever can transfer picks a member; a six-digit code goes to the
 *    initiator's own email, and only its hash is stored.
 * 2. The initiator types the code; the transfer becomes a request to the
 *    chosen member.
 * 3. The member accepts (becomes owner, and the previous owner becomes a
 *    moderator of the channel) or declines.
 *
 * The code confirms the request came from someone with access to the
 * account's mailbox, and not just an open session. Acceptance guarantees
 * nobody receives a channel unwillingly. The code is never logged nor
 * returned.
 */
class OwnershipTransfers
{
    public const CODE_TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    /**
     * How many transfers an account can start per hour: each one is an email,
     * and nobody's inbox should become a target for repetition.
     */
    public const STARTS_PER_HOUR = 3;

    /**
     * How long a confirmed request waits for the recipient's answer.
     */
    public const ANSWER_TTL_DAYS = 7;

    public function __construct(
        protected ChannelOwnership $ownership,
        protected ActionThrottle $throttle,
        protected Events $events,
        protected Queue $queue,
        protected TranslatorInterface $translator,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * The channel's still-live transfer, or null. An expired one counts as
     * none; whoever finds it later is the one who deletes it.
     */
    public function pending(Channel $channel): ?ChannelTransfer
    {
        /** @var ChannelTransfer|null $transfer */
        $transfer = ChannelTransfer::query()->where('channel_id', $channel->id)->first();

        return $transfer !== null && ! $transfer->isExpired() ? $transfer : null;
    }

    /**
     * Opens the transfer and sends the code. A previous transfer of the same
     * channel is replaced.
     */
    public function start(Channel $channel, User $actor, User $target): ChannelTransfer
    {
        $this->assertTransferable($channel);
        $this->assertEligible($channel, $target, 'userId');

        if (! $actor->isAdmin() && ! $this->throttle->attempt('transfer.'.$actor->id, self::STARTS_PER_HOUR, 3600)) {
            throw new ValidationException([
                'userId' => $this->translator->trans('ramon-chat.api.transfer_rate_limited'),
            ]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        [$transfer, $replaced] = $channel->getConnection()->transaction(function () use ($channel, $actor, $target, $code) {
            /** @var ChannelTransfer|null $previous */
            $previous = ChannelTransfer::query()
                ->where('channel_id', $channel->id)
                ->lockForUpdate()
                ->first();

            $previous?->delete();

            $transfer = new ChannelTransfer();
            $transfer->channel_id = $channel->id;
            $transfer->from_user_id = $actor->id;
            $transfer->to_user_id = $target->id;
            $transfer->code_hash = password_hash($code, PASSWORD_DEFAULT);
            $transfer->attempts = 0;
            $transfer->expires_at = Carbon::now()->addMinutes(self::CODE_TTL_MINUTES);
            $transfer->save();

            return [$transfer, $previous];
        });

        if ($replaced !== null) {
            $this->events->dispatch(new OwnershipTransferEnded($channel, $replaced, OwnershipTransferEnded::REPLACED, $actor));
        }

        $this->mailCode($channel, $actor, $target, $code);

        return $transfer;
    }

    /**
     * Checks the initiator's code. Each mistake counts; on the fifth the
     * transfer is discarded. The counts are written before the error is
     * thrown, so the rejection does not undo them along with the transaction.
     */
    public function confirm(Channel $channel, User $actor, string $code): ChannelTransfer
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';

        [$outcome, $transfer] = $channel->getConnection()->transaction(function () use ($channel, $actor, $code) {
            /** @var ChannelTransfer|null $transfer */
            $transfer = ChannelTransfer::query()
                ->where('channel_id', $channel->id)
                ->lockForUpdate()
                ->first();

            if ($transfer === null
                || $transfer->isConfirmed()
                || (int) $transfer->from_user_id !== (int) $actor->id
                || $transfer->code_hash === null) {
                return ['missing', null];
            }

            if ($transfer->isExpired() || $transfer->attempts >= self::MAX_ATTEMPTS) {
                $transfer->delete();

                return [$transfer->isExpired() ? 'expired' : 'locked', $transfer];
            }

            if (strlen($code) !== 6 || ! password_verify($code, $transfer->code_hash)) {
                $transfer->attempts = $transfer->attempts + 1;

                if ($transfer->attempts >= self::MAX_ATTEMPTS) {
                    $transfer->delete();

                    return ['locked', $transfer];
                }

                $transfer->save();

                return ['wrong', $transfer];
            }

            $transfer->code_hash = null;
            $transfer->attempts = 0;
            $transfer->confirmed_at = Carbon::now();
            $transfer->expires_at = Carbon::now()->addDays(self::ANSWER_TTL_DAYS);
            $transfer->save();

            return ['confirmed', $transfer];
        });

        if ($outcome === 'missing') {
            throw new ValidationException(['code' => $this->translator->trans('ramon-chat.api.transfer_not_found')]);
        }

        if ($outcome === 'expired') {
            throw new ValidationException(['code' => $this->translator->trans('ramon-chat.api.transfer_expired')]);
        }

        if ($outcome === 'locked') {
            throw new ValidationException(['code' => $this->translator->trans('ramon-chat.api.transfer_code_locked')]);
        }

        if ($outcome === 'wrong') {
            throw new ValidationException(['code' => $this->translator->trans('ramon-chat.api.transfer_code_invalid', [
                'remaining' => self::MAX_ATTEMPTS - $transfer->attempts,
            ])]);
        }

        /** @var User|null $target */
        $target = User::query()->find($transfer->to_user_id);

        if ($target !== null) {
            $this->events->dispatch(new OwnershipTransferRequested($channel, $transfer, $actor, $target));
        }

        return $transfer;
    }

    /**
     * The recipient accepts: becomes owner, the previous owner becomes a
     * moderator of the channel if still a member, and the transfer is ended,
     * all in one transaction.
     */
    public function accept(Channel $channel, User $actor): ChannelTransfer
    {
        $transfer = $this->incomingFor($channel, $actor);

        $this->assertTransferable($channel);
        $this->assertEligible($channel, $actor, 'channel');

        $previousOwnerId = $channel->getConnection()->transaction(function () use ($channel, $actor, $transfer) {
            /** @var ChannelTransfer|null $locked */
            $locked = ChannelTransfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            /** @var Channel $fresh */
            $fresh = Channel::query()->whereKey($channel->id)->lockForUpdate()->firstOrFail();

            $previousOwnerId = $fresh->creator_id;

            $fresh->creator_id = $actor->id;
            $fresh->save();

            ChannelUser::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $actor->id)
                ->update(['is_moderator' => false, 'moderator_since' => null]);

            if ($previousOwnerId !== null && (int) $previousOwnerId !== (int) $actor->id) {
                ChannelUser::query()
                    ->where('channel_id', $channel->id)
                    ->where('user_id', $previousOwnerId)
                    ->whereNull('left_at')
                    ->where('hidden', false)
                    ->update(['is_moderator' => true, 'moderator_since' => Carbon::now()]);
            }

            $locked->delete();

            return $previousOwnerId;
        });

        if ($previousOwnerId === false) {
            throw new ValidationException(['channel' => $this->translator->trans('ramon-chat.api.transfer_not_found')]);
        }

        $channel->creator_id = $actor->id;
        $channel->syncOriginalAttribute('creator_id');
        $channel->unsetRelation('creator');
        $channel->forgetMembership();

        $previousOwner = $previousOwnerId !== null ? User::query()->find($previousOwnerId) : null;
        $initiator = User::query()->find($transfer->from_user_id);

        $this->events->dispatch(new ChannelOwnershipTransferred($channel, $actor, $previousOwner, $transfer, $initiator));

        return $transfer;
    }

    /**
     * The recipient declines. The initiator is notified (see NotifyOwnershipTransfers).
     */
    public function decline(Channel $channel, User $actor): ChannelTransfer
    {
        $transfer = $this->incomingFor($channel, $actor);

        return $this->end($channel, $transfer, OwnershipTransferEnded::DECLINED, $actor);
    }

    /**
     * The initiator, or whoever can transfer the channel, gives up.
     */
    public function cancel(Channel $channel, User $actor): ChannelTransfer
    {
        /** @var ChannelTransfer|null $transfer */
        $transfer = ChannelTransfer::query()->where('channel_id', $channel->id)->first();

        if ($transfer === null
            || ((int) $transfer->from_user_id !== (int) $actor->id && ! $actor->can('transferOwnership', $channel))) {
            throw new ValidationException(['channel' => $this->translator->trans('ramon-chat.api.transfer_not_found')]);
        }

        return $this->end($channel, $transfer, OwnershipTransferEnded::CANCELLED, $actor);
    }

    /**
     * Ends whatever is pending on the channel, when something removes the
     * transfer's meaning: the channel was deleted or archived, or one of the
     * parties left. With `$user`, only the transfer in which that person is a
     * party.
     */
    public function cancelFor(Channel $channel, ?User $user = null, ?User $actor = null): void
    {
        /** @var ChannelTransfer|null $transfer */
        $transfer = ChannelTransfer::query()->where('channel_id', $channel->id)->first();

        if ($transfer === null) {
            return;
        }

        if ($user !== null
            && (int) $transfer->from_user_id !== (int) $user->id
            && (int) $transfer->to_user_id !== (int) $user->id) {
            return;
        }

        $this->end($channel, $transfer, OwnershipTransferEnded::CANCELLED, $actor);
    }

    /**
     * Removes the row and notifies. Deleted outside a transaction: it is a
     * single write, and the event only goes out after it.
     */
    protected function end(Channel $channel, ChannelTransfer $transfer, string $reason, ?User $actor): ChannelTransfer
    {
        $deleted = ChannelTransfer::query()->whereKey($transfer->id)->delete();

        if ($deleted > 0) {
            $this->events->dispatch(new OwnershipTransferEnded($channel, $transfer, $reason, $actor));
        }

        return $transfer;
    }

    /**
     * The confirmed request addressed to the actor, or a translated 422.
     */
    protected function incomingFor(Channel $channel, User $actor): ChannelTransfer
    {
        /** @var ChannelTransfer|null $transfer */
        $transfer = ChannelTransfer::query()->where('channel_id', $channel->id)->first();

        if ($transfer === null
            || ! $transfer->isConfirmed()
            || (int) $transfer->to_user_id !== (int) $actor->id) {
            throw new ValidationException(['channel' => $this->translator->trans('ramon-chat.api.transfer_not_found')]);
        }

        if ($transfer->isExpired()) {
            $this->end($channel, $transfer, OwnershipTransferEnded::CANCELLED, null);

            throw new ValidationException(['channel' => $this->translator->trans('ramon-chat.api.transfer_expired')]);
        }

        return $transfer;
    }

    /**
     * Only a category channel, active, in a forum where members own channels.
     * In "administrators" mode owning grants nothing.
     */
    protected function assertTransferable(Channel $channel): void
    {
        if (! $channel->isCategory()
            || $channel->isArchived()
            || $channel->isDeleted()
            || ! $this->ownership->membersOwnChannels()) {
            throw new ValidationException(['channel' => $this->translator->trans('ramon-chat.api.transfer_unavailable')]);
        }
    }

    /**
     * The recipient must be a visible member of the channel, not the current
     * owner, and able to actually own channels: without `manageOwnChannels`
     * ownership would be inert. A suspended account loses the permission
     * through flarum/suspend, and the suspension date is checked here too.
     */
    protected function assertEligible(Channel $channel, User $target, string $field): void
    {
        if ($channel->isOwnedBy($target)) {
            throw new ValidationException([$field => $this->translator->trans('ramon-chat.api.transfer_target_is_owner')]);
        }

        /** @var ChannelUser|null $membership */
        $membership = ChannelUser::query()
            ->where('channel_id', $channel->id)
            ->where('user_id', $target->id)
            ->whereNull('left_at')
            ->first();

        if ($membership === null || $membership->isHidden()) {
            throw new ValidationException([$field => $this->translator->trans('ramon-chat.api.not_a_member')]);
        }

        $suspendedUntil = $target->getAttribute('suspended_until');

        if (! $target->exists
            || ($suspendedUntil !== null && Carbon::parse($suspendedUntil)->isFuture())
            || ! $target->can('useChat')
            || ! $target->hasPermission('ramon-chat.manageOwnChannels')) {
            throw new ValidationException([$field => $this->translator->trans('ramon-chat.api.transfer_target_not_allowed')]);
        }
    }

    /**
     * The code goes to the initiator's email, in that person's language,
     * through Flarum's mail queue. The core's informational layout escapes the
     * body.
     */
    protected function mailCode(Channel $channel, User $actor, User $target, string $code): void
    {
        $locale = $actor->getPreference('locale') ?? $this->settings->get('default_locale');
        $previous = $this->translator->getLocale();

        if (is_string($locale) && $locale !== '') {
            $this->translator->setLocale($locale);
        }

        try {
            $subject = $this->translator->trans('ramon-chat.email.transfer_code.subject', [
                'channel' => (string) $channel->name,
            ]);
            $body = $this->translator->trans('ramon-chat.email.transfer_code.body', [
                'channel'   => (string) $channel->name,
                'recipient' => (string) $target->display_name,
                'code'      => $code,
                'minutes'   => self::CODE_TTL_MINUTES,
            ]);
        } finally {
            $this->translator->setLocale($previous);
        }

        $this->queue->push(new SendInformationalEmailJob(
            email: (string) $actor->email,
            displayName: (string) $actor->display_name,
            subject: $subject,
            body: $body,
            forumTitle: (string) $this->settings->get('forum_title'),
            bodyTitle: $subject,
            locale: is_string($locale) && $locale !== '' ? $locale : null,
        ));
    }
}
