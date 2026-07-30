<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime;

use Ramon\Chat\Event\ChannelStatusChanged;
use Ramon\Chat\Event\MessagePinToggled;
use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Event\MessageWasEdited;
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
            ],
            $event->actor?->id
        );
    }

    public function whenChannelChanged(ChannelStatusChanged $event): void
    {
        $this->broadcaster->toChannelMembers(
            $event->channel,
            self::EVENT_CHANNEL,
            [
                'channelId' => (int) $event->channel->id,
                'status'    => $event->channel->status,
            ],
            $event->actor?->id
        );
    }

    /**
     * The wire form of a message.
     *
     * Content is included rather than sent as a bare id: chat is high-volume and
     * a fetch per message would multiply request count by the message rate. It is
     * safe to inline because ChatBroadcaster has already filtered recipients
     * through the same visibility scope the API would apply.
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
            'contentHtml' => $deleted || $message->isSystem() ? null : $message->formatContent(),
            'createdAt'   => $message->created_at?->toIso8601String(),
            'editedAt'    => $message->edited_at?->toIso8601String(),
            'isDeleted'   => $deleted,
            'isPinned'    => $message->isPinned(),
            'pinnedAt'    => $message->pinned_at?->toIso8601String(),

            // Attachments have to travel with the payload. The recipient builds the
            // message from this alone — nothing re-fetches it — so a message whose
            // uploads were omitted rendered as an empty row on every screen but the
            // sender's, where the API response had supplied them.
            'uploads'     => $deleted ? [] : $this->uploadsPayload($message),
        ];
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
