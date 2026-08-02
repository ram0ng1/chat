<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime;

use Flarum\User\User;
use Ramon\Chat\Event\ChannelStatusChanged;
use Ramon\Chat\Event\ChannelWasEdited;
use Ramon\Chat\Event\MessagePinToggled;
use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Event\MessageWasEdited;
use Ramon\Chat\Event\MessageWasPurged;
use Ramon\Chat\Event\MessageWasRestored;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Event\ReactionToggled;
use Ramon\Chat\Event\ThreadWasCreated;
use Ramon\Chat\Message;
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

    public function __construct(
        protected ChatBroadcaster $broadcaster
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

    public function whenThreadChanged(ThreadWasCreated $event): void
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
            ],
            // Not excluded: the actor's own client has already applied the change
            // optimistically, and pushing it again is harmless — whereas excluding
            // them would leave a moderator with two browser tabs out of step.
            null
        );
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
            'contentHtml' => $deleted || $message->isSystem() ? null : $message->formatContent(),
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

            // The id alone. The recipient names the moderator only if that user
            // is already in their store — which they usually are, having been
            // active in the channel — and falls back to the unnamed wording
            // otherwise. Pushing a whole user record for a tombstone is not
            // worth the payload on every edit and pin that shares this shape.
            'deletedById' => $message->deleted_by_id,

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
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function userPayload(Message $message): ?array
    {
        $user = $message->relationLoaded('user') ? $message->user : $message->user()->first();

        if ($user === null) {
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
