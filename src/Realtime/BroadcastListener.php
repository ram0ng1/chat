<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime;

use Carbon\Carbon;
use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\ChannelModeratorChanged;
use Ramon\Chat\Event\ChannelOwnershipTransferred;
use Ramon\Chat\Event\ChannelRanksChanged;
use Ramon\Chat\Event\ChannelStatusChanged;
use Ramon\Chat\Event\ChannelWasArchived;
use Ramon\Chat\Event\ChannelWasCreated;
use Ramon\Chat\Event\ChannelWasDeleted;
use Ramon\Chat\Event\ChannelWasEdited;
use Ramon\Chat\Event\FlagsChanged;
use Ramon\Chat\Event\InviteWasCancelled;
use Ramon\Chat\Event\InviteWasDeclined;
use Ramon\Chat\Event\MessagePinToggled;
use Ramon\Chat\Event\MessagesWereMoved;
use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Event\MessageWasEdited;
use Ramon\Chat\Event\MessageWasPurged;
use Ramon\Chat\Event\MessageWasRestored;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Event\OwnershipTransferEnded;
use Ramon\Chat\Event\OwnershipTransferRequested;
use Ramon\Chat\Event\ReactionToggled;
use Ramon\Chat\Event\ThreadWasCreated;
use Ramon\Chat\Event\ThreadWasEdited;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UserLeftChannel;
use Ramon\Chat\Event\UserWasInvited;
use Ramon\Chat\ChannelRankUser;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Message;
use Ramon\Chat\Service\ChannelRanks;
use Ramon\Chat\Thread;
use Ramon\Chat\Upload;

/**
 * Translates domain events into websocket payloads.
 *
 * Registered only when flarum/realtime is enabled (see the Conditional in
 * extend.php), but every path is still null-safe so a half-configured realtime
 * install degrades to polling rather than erroring.
 */
class BroadcastListener
{
    /**
     * Event names the client binds to. Prefixed so they cannot collide with
     * core's or another extension's events on the same private channel.
     */
    public const EVENT_MESSAGE = 'ramonChat.message';
    public const EVENT_MESSAGE_CHANGED = 'ramonChat.messageChanged';

    /**
     * Distinct from `messageChanged`, which carries a row that still exists. A
     * purge leaves nothing to redraw — the client removes the row instead.
     */
    public const EVENT_MESSAGE_PURGED = 'ramonChat.messagePurged';

    public const EVENT_REACTION = 'ramonChat.reaction';
    public const EVENT_THREAD = 'ramonChat.thread';
    public const EVENT_CHANNEL = 'ramonChat.channel';

    /**
     * Who is in, invited to, or out of a channel. Delivered to the person the
     * change is about, so their other tabs follow, and to the channel's members
     * when the change is one the room can see.
     */
    public const EVENT_MEMBERSHIP = 'ramonChat.membership';

    /**
     * Messages that left one channel for another. Ids only: the side that
     * loses them drops the rows, the side that gains them reads them through
     * the API, which is what decides whether each reader may have them.
     */
    public const EVENT_MESSAGES_MOVED = 'ramonChat.messagesMoved';

    /**
     * The moderation queue moved. Carries nothing; see FlagsChanged.
     */
    public const EVENT_FLAGS = 'ramonChat.flags';

    /**
     * A channel's rank book changed. Carries the whole book: it is channel
     * configuration every member may read, small, and the same for all of them,
     * so each author line and an open members tab redraw without a fetch.
     */
    public const EVENT_RANKS = 'ramonChat.ranks';

    /**
     * Members at which a channel-wide push goes to the queue whatever the
     * forum's setting: an auto-join channel can hold every account, and
     * resolving that audience is not work for the request that created it.
     */
    public const LARGE_AUDIENCE = 500;

    public function __construct(
        protected ChatBroadcaster $broadcaster,
        protected LoggerInterface $log,
        protected ChannelRanks $ranks
    ) {
    }

    public function whenMessageSent(MessageWasSent $event): void
    {
        $channel = $event->message->channel;

        if ($channel === null) {
            return;
        }

        $this->broadcaster->toChannelMembers(
            $channel,
            self::EVENT_MESSAGE,
            $this->messagePayload($event->message),
            $event->message->user_id
        );
    }

    public function whenMessageChanged(MessageWasEdited|MessageWasDeleted|MessageWasRestored|MessagePinToggled $event): void
    {
        $channel = $event->message->channel;

        if ($channel === null) {
            return;
        }

        $this->broadcaster->toChannelMembers(
            $channel,
            self::EVENT_MESSAGE_CHANGED,
            $this->messagePayload($event->message),
            $event->actor?->id
        );
    }

    /**
     * A message removed outright.
     *
     * Not excluded from the actor's own client, unlike the sends: the moderator
     * has already dropped the row from their stream, and a second removal is a
     * no-op — whereas excluding them leaves a moderator's other tab still
     * showing a message that no longer exists anywhere.
     */
    public function whenMessagePurged(MessageWasPurged $event): void
    {
        $this->broadcaster->toChannelMembers(
            $event->channel,
            self::EVENT_MESSAGE_PURGED,
            [
                'id'        => $event->messageId,
                'channelId' => (int) $event->channel->id,

                // So a thread panel open on this message can drop it too, and
                // the indicator under the root can be recounted.
                'threadId'  => $event->threadId,
            ],
            null
        );
    }

    public function whenReactionToggled(ReactionToggled $event): void
    {
        $channel = $event->message->channel;

        if ($channel === null) {
            return;
        }

        $this->broadcaster->toChannelMembers(
            $channel,
            self::EVENT_REACTION,
            [
                'messageId' => (int) $event->message->id,
                'channelId' => (int) $event->message->channel_id,
                'emoji'     => $event->emoji,
                'userId'    => (int) $event->actor->id,
                'added'     => $event->added,
            ],
            (int) $event->actor->id
        );
    }

    public function whenThreadChanged(ThreadWasCreated|ThreadWasEdited $event): void
    {
        $channel = $event->thread->channel;

        if ($channel === null) {
            return;
        }

        $this->broadcaster->toChannelMembers(
            $channel,
            self::EVENT_THREAD,
            [
                'threadId'          => (int) $event->thread->id,
                'channelId'         => (int) $event->thread->channel_id,
                'originalMessageId' => $event->thread->original_message_id,
                'title'             => $event->thread->title,
                'repliesCount'      => (int) $event->thread->replies_count,

                // How far the count above already reaches. Creating a thread
                // broadcasts twice — this event and the message that opened it —
                // and the recipient has no other way to tell that the two
                // describe the same reply. Without it the client counted the
                // message once from here and once again on arrival, and every
                // new thread announced two replies to everyone but its author.
                'lastMessageId'     => $event->thread->last_message_id,
            ],
            $event->actor?->id
        );
    }

    public function whenChannelChanged(ChannelStatusChanged|ChannelWasEdited $event): void
    {
        $this->broadcaster->toChannelMembers(
            $event->channel,
            self::EVENT_CHANNEL,
            [
                'channelId' => (int) $event->channel->id,
                'status'    => $event->channel->status,

                // Settings that change what the client may draw. `postPermission`
                // in particular decides whether the composer appears at all, and
                // an admin flipping it should take effect without every member
                // reloading the page.
                //
                // What is deliberately *not* here is `canPostMessage`: that answer
                // differs per user — a moderator may post in a channel a member may
                // not — and one broadcast payload cannot carry a different value
                // for each recipient. The client refetches its own record instead,
                // so the server stays the only thing deciding who may post.
                'postPermission' => $event->channel->post_permission,
                'isPrivate'      => (bool) $event->channel->is_private,
                'threadingEnabled' => (bool) $event->channel->threading_enabled,

                // Same reasoning as `postPermission`: turning slow mode on
                // changes whether the composer accepts the next message, and a
                // rule everyone is now bound by should not wait for each of them
                // to reload. Its per-user half — `slowModeRemaining`, which
                // holders of `bypassSlowMode` read as zero — is refetched by the
                // client for the same reason `canPostMessage` is.
                'slowModeSeconds' => (int) $event->channel->slow_mode_seconds,
                'name'           => $event->channel->name,
                'emoji'          => $event->channel->emoji,
                'description'    => $event->channel->description,
                'imageUrl'       => $event->channel->imageUrl(),

                // Always sent, so leaving the archive clears them on every
                // screen the way archiving set them.
                'archivedAt'           => $event->channel->archived_at?->toIso8601String(),
                'archivedDiscussionId' => $event->channel->archived_discussion_id,
            ],
            // Not excluded: the actor's own client has already applied the change
            // optimistically, and pushing it again is harmless — whereas excluding
            // them would leave a moderator with two browser tabs out of step.
            null
        );
    }

    /**
     * A channel that has just come into existence, for whoever it already
     * holds besides its creator: the other side of a direct conversation, or
     * everyone an auto-join channel took in. Without it the channel appeared
     * only on their next fetch of the list.
     *
     * Ids only. Each recipient reads the channel through the API, which decides
     * what of it they may see; the audience itself already went through the
     * visibility scope in SendChatEventJob.
     */
    public function whenChannelCreated(ChannelWasCreated $event): void
    {
        $this->broadcaster->toChannelMembers(
            $event->channel,
            self::EVENT_CHANNEL,
            [
                'channelId' => (int) $event->channel->id,
                'status'    => $event->channel->status,
                'created'   => true,
            ],
            $event->actor?->id,
            queue: (int) $event->channel->user_count >= self::LARGE_AUDIENCE
        );
    }

    /**
     * A move, told to both rooms it touched.
     *
     * The rooms the messages left hear which rows to drop, and the new counts
     * of the threads they left. The room they went to hears which rows to read.
     * Neither side hears the other's id: the destination may be a channel the
     * source's members cannot see, and the reverse.
     */
    public function whenMessagesMoved(MessagesWereMoved $event): void
    {
        $threads = [];

        foreach (Thread::query()->whereKey(array_values(array_unique($event->threadIds)))->get() as $thread) {
            $threads[(int) $thread->channel_id][] = [
                'threadId'          => (int) $thread->id,
                'repliesCount'      => (int) $thread->replies_count,
                'originalMessageId' => $thread->original_message_id,
                'lastMessageId'     => $thread->last_message_id,
            ];
        }

        $movedIn = [];

        foreach ($event->messageIdsBySource as $sourceId => $messageIds) {
            $messageIds = array_map('intval', $messageIds);
            $movedIn = array_merge($movedIn, $messageIds);

            $source = $event->sources[$sourceId] ?? null;

            if ($source === null) {
                continue;
            }

            $this->broadcaster->toChannelMembers(
                $source,
                self::EVENT_MESSAGES_MOVED,
                [
                    'channelId'     => (int) $sourceId,
                    'movedOut'      => $messageIds,

                    // So a thread panel open on one of these can drop it too.
                    'threadIds'     => array_intersect_key($event->threadIds, array_flip($messageIds)),
                    'threads'       => $threads[(int) $sourceId] ?? [],
                    'messagesCount' => (int) $source->messages_count,
                ],
                null
            );
        }

        $this->broadcaster->toChannelMembers(
            $event->target,
            self::EVENT_MESSAGES_MOVED,
            [
                'channelId'     => (int) $event->target->id,
                'movedIn'       => $movedIn,

                // The move rewrote each row, so its `updated_at` is at least
                // this. Narrowing the read to it keeps rows that were already
                // in the destination, inside the same id range, out of it.
                'movedAt'       => Carbon::now()->subSecond()->getTimestamp(),
                'messagesCount' => (int) $event->target->messages_count,
            ],
            null
        );
    }

    /**
     * Someone made or unmade a moderator of a channel. The room hears it, as
     * the role shows in the member list anyone in the room can open, and the
     * person it is about refetches what they may now do there.
     */
    public function whenModeratorChanged(ChannelModeratorChanged $event): void
    {
        $this->membership(
            $event->channel,
            $event->user,
            $event->isModerator ? 'promoted' : 'demoted',
            $event->actor,
            tellMembers: true
        );

        $this->pushRanks($event->channel);
    }

    /**
     * The channel changed hands. The whole room hears it, because the owner
     * badge moves in every open members tab, and both people involved re-read
     * what they may now do there. `previousOwnerId` is what tells the old
     * owner the push concerns them too.
     */
    public function whenOwnershipTransferred(ChannelOwnershipTransferred $event): void
    {
        $this->membership(
            $event->channel,
            $event->newOwner,
            'owner_changed',
            $event->newOwner,
            tellMembers: true,
            extra: ['previousOwnerId' => $event->previousOwner?->id !== null ? (int) $event->previousOwner->id : null]
        );

        $this->pushRanks($event->channel);
    }

    /**
     * A handover offered, after the code: the member it is offered to, and
     * whoever started it, so an open members tab on either side re-reads it.
     */
    public function whenTransferRequested(OwnershipTransferRequested $event): void
    {
        $this->membership($event->channel, $event->to, 'transfer_requested', $event->from, tellMembers: false);
    }

    /**
     * A handover withdrawn, declined or replaced. Both parties hear it; the
     * room never knew it was happening.
     */
    public function whenTransferEnded(OwnershipTransferEnded $event): void
    {
        $to = User::query()->find($event->transfer->to_user_id);
        $from = User::query()->find($event->transfer->from_user_id);

        if ($to === null) {
            return;
        }

        $this->membership(
            $event->channel,
            $to,
            'transfer_ended',
            $from,
            tellMembers: false,
            extra: ['reason' => $event->reason]
        );
    }

    /**
     * The queue moved; every moderator recounts their own badge.
     */
    public function whenFlagsChanged(FlagsChanged $event): void
    {
        $this->broadcaster->toModerators(self::EVENT_FLAGS, []);
    }

    /**
     * A channel removed. Ids and scalars only: the row is gone from every
     * member's sidebar, so there is nothing left to describe — without the push
     * the stale row stayed clickable and answered with a 404.
     *
     * The audience is still resolvable: deletion stamps `deleted_at` and leaves
     * the memberships in place, and SendChatEventJob skips the visibility scope
     * (which now excludes the channel) for a deleted channel, checking only that
     * each member may still use the chat at all.
     */
    public function whenChannelDeleted(ChannelWasDeleted $event): void
    {
        $this->broadcaster->toChannelMembers(
            $event->channel,
            self::EVENT_CHANNEL,
            [
                'channelId' => (int) $event->channel->id,
                'status'    => $event->channel->status,
                'deleted'   => true,
            ],
            null
        );
    }

    /**
     * Archiving freezes the channel for good, so members' composers have to go
     * the moment it happens, the same way closing does. Carries where the
     * transcript went, which is what the frozen notice links to.
     */
    public function whenChannelArchived(ChannelWasArchived $event): void
    {
        $this->broadcaster->toChannelMembers(
            $event->channel,
            self::EVENT_CHANNEL,
            [
                'channelId'            => (int) $event->channel->id,
                'status'               => $event->channel->status,
                'archivedAt'           => $event->channel->archived_at?->toIso8601String(),
                'archivedDiscussionId' => (int) $event->discussion->id,
            ],
            null
        );
    }

    public function whenInvited(UserWasInvited $event): void
    {
        $this->membership($event->channel, $event->user, 'invited', $event->inviter, tellMembers: false);
    }

    public function whenJoined(UserJoinedChannel $event): void
    {
        // A hidden arrival is not the room's to know; the member's own tabs are.
        $this->membership($event->channel, $event->user, 'joined', $event->actor, tellMembers: ! $event->hidden);

        if (! $event->hidden && $this->holdsRank($event->channel, $event->user)) {
            $this->pushRanks($event->channel);
        }
    }

    public function whenLeft(UserLeftChannel $event): void
    {
        $this->membership($event->channel, $event->user, 'left', $event->actor, tellMembers: ! $event->hidden);

        if (! $event->hidden && $this->holdsRank($event->channel, $event->user)) {
            $this->pushRanks($event->channel);
        }
    }

    /**
     * A rank created, edited, deleted or reordered, or a member's ranks set.
     * Not excluded from the actor: their editor already redrew from the
     * response, but their other tabs have not.
     */
    public function whenRanksChanged(ChannelRanksChanged $event): void
    {
        $this->pushRanks($event->channel);
    }

    protected function pushRanks(Channel $channel): void
    {
        $book = $this->ranks->book($channel);

        if ($book === null) {
            return;
        }

        $this->broadcaster->toChannelMembers($channel, self::EVENT_RANKS, [
            'channelId' => (int) $channel->id,
            'rankBook'  => $book,
        ], null);
    }

    /**
     * Whether a member coming or going changes the rank book: only someone
     * shown with a rank does. The owner shows one whether present or not.
     */
    protected function holdsRank(Channel $channel, User $user): bool
    {
        if (! $channel->isCategory()) {
            return false;
        }

        return ChannelRankUser::query()->where('channel_id', $channel->id)->where('user_id', $user->id)->exists()
            || ChannelUser::query()->where('channel_id', $channel->id)->where('user_id', $user->id)->where('is_moderator', true)->exists();
    }

    public function whenInviteDeclined(InviteWasDeclined $event): void
    {
        // The inviter is the actor here so their members tab hears the answer;
        // the refusal itself reaches them as a notification.
        $this->membership($event->channel, $event->user, 'invite_declined', $event->inviter, tellMembers: false);
    }

    public function whenInviteCancelled(InviteWasCancelled $event): void
    {
        $this->membership($event->channel, $event->user, 'invite_cancelled', $event->actor, tellMembers: false);
    }

    /**
     * One membership change, addressed to everyone it concerns.
     *
     * The person it is about always hears it: that is what keeps a second tab,
     * or the drawer on another page, in step with the sidebar that acted. The
     * actor hears it too when they are somebody else, so a members tab that
     * just sent an invite sees it answered. The room as a whole hears only the
     * changes it can see anyway (arrivals and departures), and never the
     * invitations, which are between the managers and the person asked.
     *
     * The payload carries ids and display names only; the client refetches the
     * channel through the API when it needs the row, and the visibility scope
     * decides there whether it may have it.
     *
     * @param  array<string, mixed>  $extra  Scalars a particular action adds.
     */
    protected function membership(
        Channel $channel,
        User $user,
        string $action,
        ?User $actor,
        bool $tellMembers,
        array $extra = []
    ): void {
        $payload = [
            'channelId'   => (int) $channel->id,
            'channelName' => $channel->name,
            'isPrivate'   => (bool) $channel->is_private,
            'userId'      => (int) $user->id,
            'username'    => $user->display_name,
            'action'      => $action,
            'actorId'     => $actor?->id !== null ? (int) $actor->id : null,
            'actorName'   => $actor?->display_name,
            'userCount'   => (int) $channel->user_count,
        ] + $extra;

        $this->broadcaster->toUser((int) $user->id, self::EVENT_MEMBERSHIP, $payload);

        if ($tellMembers) {
            $this->broadcaster->toChannelMembers($channel, self::EVENT_MEMBERSHIP, $payload, (int) $user->id);

            return;
        }

        if ($actor !== null && (int) $actor->id !== (int) $user->id) {
            $this->broadcaster->toUser((int) $actor->id, self::EVENT_MEMBERSHIP, $payload);
        }
    }

    /**
     * The wire form of a message.
     *
     * Content is included rather than sent as a bare id: chat is high-volume and
     * a fetch per message would multiply request count by the message rate. It is
     * safe to inline because SendChatEventJob filters recipients through the same
     * visibility scope the API would apply before it triggers anything.
     *
     * `contentHtml` is deliberately omitted for deleted messages so a tombstone
     * cannot be reconstructed from a push payload.
     *
     * @return array<string, mixed>
     */
    /**
     * Rendered content, or escaped plain text when a formatter callback threw.
     *
     * The broadcast runs inside the send request, after the row is stored, so
     * an exception here reached the sender as a 500 for a message that had in
     * fact been saved, and nobody else received it live at all.
     */
    protected function contentHtml(Message $message): string
    {
        try {
            return $message->formatContent();
        } catch (\Throwable $e) {
            $this->log->error('[ramon/chat] formatContent failed while broadcasting message '.$message->id.': '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $message->fallbackContentHtml();
        }
    }

    protected function messagePayload(Message $message): array
    {
        $deleted = $message->isDeleted();

        return [
            'id'          => (int) $message->id,
            'channelId'   => (int) $message->channel_id,
            'threadId'    => $message->thread_id,
            'replyToId'   => $message->reply_to_id,
            'number'      => $message->number,
            'userId'      => $message->user_id,
            'type'        => $message->type,
            'systemKey'   => $message->system_key,

            // The placeholders the system string is built from. Omitting these was
            // not a partial payload but a broken one: a system message renders by
            // interpolating this data into a translation, so the pushed copy showed
            // "{undefined} started a discussion: {undefined}" while the same row
            // fetched from the API read correctly. Anyone in the channel at the
            // moment it was posted saw the broken form.
            'systemData'  => $message->system_data,
            'contentHtml' => $deleted || $message->isSystem() ? null : $this->contentHtml($message),
            'createdAt'   => $message->created_at?->toIso8601String(),
            'editedAt'    => $message->edited_at?->toIso8601String(),
            'isDeleted'   => $deleted,

            // Who removed it. Without these the author of a message a moderator
            // deleted was told only that it "was deleted" — the same wording
            // their own deletion produces — and learned it had been moderated
            // only by reloading, where the API supplies the field.
            //
            // Derived exactly as MessageResource does, from the column rather
            // than from the relation: a deletion by the author is not a
            // moderation, whoever is looking.
            'isModeratorDeleted' => $deleted
                && $message->deleted_by_id !== null
                && (int) $message->deleted_by_id !== (int) $message->user_id,

            // Never sent. The tombstone and who made it are for the author and
            // the moderators, the only ones the API shows a deleted message to,
            // but this one payload goes to every member — so naming the moderator
            // here told the whole channel who removed whose message. Live, the
            // tombstone reads "removed by a moderator"; the named form arrives
            // with the next fetch, where MessageResource decides who may see it.
            // The key stays so the client's payload shape does not change.
            'deletedById' => null,

            'isPinned'    => $message->isPinned(),
            'pinnedAt'    => $message->pinned_at?->toIso8601String(),

            // Attachments have to travel with the payload. The recipient builds the
            // message from this alone — nothing re-fetches it — so a message whose
            // uploads were omitted rendered as an empty row on every screen but the
            // sender's, where the API response had supplied them.
            'uploads'     => $deleted ? [] : $this->uploadsPayload($message),

            // Who the message is addressed to. Carried for the same reason the
            // uploads are: the recipient builds the row from this payload alone.
            //
            // Without it a message arriving live could not be recognised as a
            // mention — the highlight never appeared, and the notification sound
            // had no way to honour a channel set to "mentions only", so it chimed
            // on every message in every channel the user belonged to.
            'mentionedUsers'      => $deleted ? [] : $message->mentions
                ->where('type', 'user')
                ->pluck('user_id')
                ->filter()
                ->values()
                ->all(),

            'mentionsChannelWide' => ! $deleted
                && $message->mentions->contains(fn ($mention) => $mention->isChannelWide()),

            // The author, inlined for the same reason the uploads are.
            //
            // `userId` alone is only a *reference*: the client resolves it against
            // whatever it already has in its local store, and a recipient who has
            // never seen that person — a fresh page, a first message from someone
            // new — resolves it to nothing and draws the row as "[deleted]".
            // Sending the few fields the row actually uses costs a fraction of the
            // message body and removes the failure entirely.
            'user'        => $this->userPayload($message),

            // The author's rank here, for the same reason: the row is drawn from
            // this payload, and a recipient who has not loaded the channel's rank
            // book has nothing else to read it from.
            'authorRank'  => $message->user_id !== null && $message->channel !== null
                ? $this->ranks->forUser($message->channel, (int) $message->user_id)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function userPayload(Message $message): ?array
    {
        $user = $message->relationLoaded('user') ? $message->user : $message->user()->first();

        // `instanceof` rather than a null check: the relation is typed as a bare
        // Model on the way out, and a bot message has no author at all, so this
        // is both the null guard and the narrowing the rest of the method needs.
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id'          => (int) $user->id,
            'username'    => $user->username,
            'displayName' => $user->display_name,
            'avatarUrl'   => $user->avatar_url,
            'slug'        => (string) ($user->slug ?? $user->id),
            'groups'      => $this->groupsPayload($user),
        ];
    }

    /**
     * The author's groups, for the badges drawn on their avatar.
     *
     * Hidden groups are dropped rather than filtered per recipient: one payload
     * goes to every member of the channel, so anything in it is readable by all of
     * them, and core only shows a hidden group to an actor holding
     * `viewHiddenGroups`. A moderator who does hold it therefore sees the hidden
     * badge appear on the next load rather than the instant the message lands —
     * the alternative is broadcasting group membership the recipient may not see.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function groupsPayload(User $user): array
    {
        $groups = $user->relationLoaded('groups') ? $user->groups : $user->groups()->get();

        return $groups
            ->filter(fn ($group) => ! $group->is_hidden)
            ->map(fn ($group) => [
                'id'           => (int) $group->id,
                'nameSingular' => $group->name_singular,
                'namePlural'   => $group->name_plural,
                'color'        => $group->color,
                'icon'         => $group->icon,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function uploadsPayload(Message $message): array
    {
        // `uploads` may not be loaded — the send path sets other relations
        // explicitly but not this one.
        $uploads = $message->relationLoaded('uploads')
            ? $message->uploads
            : $message->uploads()->get();

        return $uploads
            ->map(fn (Upload $upload) => [
                'id'        => (int) $upload->id,
                'fileName'  => $upload->file_name,
                'mimeType'  => $upload->mime_type,
                'size'      => (int) $upload->size,
                // Carried so the client can reserve layout space and avoid the row
                // reflowing as the image loads.
                'width'     => $upload->width,
                'height'    => $upload->height,
                'url'       => $upload->url(),
                'isImage'   => $upload->isImage(),
                'createdAt' => $upload->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
