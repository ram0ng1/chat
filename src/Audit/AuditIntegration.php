<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Audit;

use Flarum\Audit\AuditLogger;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Ramon\Chat\Event\ChannelStatusChanged;
use Ramon\Chat\Event\ChannelWasArchived;
use Ramon\Chat\Event\ChannelWasCreated;
use Ramon\Chat\Event\ChannelWasDeleted;
use Ramon\Chat\Event\MessagePinToggled;
use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Event\MessageWasMoved;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UserLeftChannel;

/**
 * Audit-log integration, wired through `Flarum\Audit\Extend\Audit::using()` behind
 * a Conditional — the class references flarum/audit's AuditLogger, so it must not
 * be loaded on a forum without it.
 *
 * ## What is logged, and what is not
 *
 * Only acts of moderation and configuration: creating, closing, archiving or
 * deleting a channel; removing or pinning someone else's message; moving messages
 * between channels; and membership changes, because a hidden join in particular is
 * precisely the sort of thing an audit trail exists to record.
 *
 * Ordinary conversation is *not* logged. A chat produces thousands of messages a
 * day, and copying each one into the audit log would drown the record it is meant
 * to be while duplicating data that already lives in `chat_messages` — and doing so
 * for private channels would quietly build a second, permanent copy of private
 * conversations in a table with different retention rules.
 */
class AuditIntegration
{
    /**
     * Declared so flarum/audit can list and filter them in its admin UI.
     *
     * @var string[]
     */
    public static array $actions = [
        'chat.channel_created',
        'chat.channel_status_changed',
        'chat.channel_archived',
        'chat.channel_deleted',
        'chat.message_deleted',
        'chat.message_pinned',
        'chat.message_unpinned',
        'chat.messages_moved',
        'chat.user_joined',
        'chat.user_joined_hidden',
        'chat.user_left',
    ];

    public function __invoke(Container $container): void
    {
        $events = $container->make(Dispatcher::class);

        $events->listen(ChannelWasCreated::class, [$this, 'channelCreated']);
        $events->listen(ChannelStatusChanged::class, [$this, 'channelStatusChanged']);
        $events->listen(ChannelWasArchived::class, [$this, 'channelArchived']);
        $events->listen(ChannelWasDeleted::class, [$this, 'channelDeleted']);
        $events->listen(MessageWasDeleted::class, [$this, 'messageDeleted']);
        $events->listen(MessagePinToggled::class, [$this, 'messagePinToggled']);
        $events->listen(MessageWasMoved::class, [$this, 'messagesMoved']);
        $events->listen(UserJoinedChannel::class, [$this, 'userJoined']);
        $events->listen(UserLeftChannel::class, [$this, 'userLeft']);
    }

    public function channelCreated(ChannelWasCreated $event): void
    {
        $this->log($event->actor, 'chat.channel_created', [
            'channel_id'   => $event->channel->id,
            'channel_name' => $event->channel->name,
            'is_private'   => (bool) $event->channel->is_private,
        ]);
    }

    public function channelStatusChanged(ChannelStatusChanged $event): void
    {
        $this->log($event->actor, 'chat.channel_status_changed', [
            'channel_id' => $event->channel->id,
            'from'       => $event->previousStatus,
            'to'         => $event->channel->status,
        ]);
    }

    public function channelArchived(ChannelWasArchived $event): void
    {
        $this->log($event->actor, 'chat.channel_archived', [
            'channel_id'    => $event->channel->id,
            'discussion_id' => $event->discussion->id,
        ]);
    }

    public function channelDeleted(ChannelWasDeleted $event): void
    {
        $this->log($event->actor, 'chat.channel_deleted', [
            'channel_id'   => $event->channel->id,
            'channel_name' => $event->channel->name,
        ]);
    }

    public function messageDeleted(MessageWasDeleted $event): void
    {
        // Only when someone removed *another* person's message. Deleting your own
        // is not moderation, and logging it would bury the entries that are.
        if ($event->actor === null || $event->actor->id === $event->message->user_id) {
            return;
        }

        $this->log($event->actor, 'chat.message_deleted', [
            'message_id' => $event->message->id,
            'channel_id' => $event->message->channel_id,
            'author_id'  => $event->message->user_id,
        ]);
    }

    public function messagePinToggled(MessagePinToggled $event): void
    {
        $this->log(
            $event->actor,
            $event->message->isPinned() ? 'chat.message_pinned' : 'chat.message_unpinned',
            [
                'message_id' => $event->message->id,
                'channel_id' => $event->message->channel_id,
            ]
        );
    }

    public function messagesMoved(MessageWasMoved $event): void
    {
        $this->log($event->actor, 'chat.messages_moved', [
            'message_id' => $event->message->id,
            'from'       => $event->from->id,
            'to'         => $event->to->id,
        ]);
    }

    public function userJoined(UserJoinedChannel $event): void
    {
        // A hidden join gets its own action: it is the one membership change nobody
        // in the channel can see, so the audit log is the only place it is visible
        // at all.
        $this->log($event->actor, $event->hidden ? 'chat.user_joined_hidden' : 'chat.user_joined', [
            'channel_id' => $event->channel->id,
            'user_id'    => $event->user->id,
        ]);
    }

    public function userLeft(UserLeftChannel $event): void
    {
        $this->log($event->actor, 'chat.user_left', [
            'channel_id' => $event->channel->id,
            'user_id'    => $event->user->id,
        ]);
    }

    /**
     * Some of these fire from queued jobs and console commands, where the
     * request-scoped actor is not set — the same reason flarum/gdpr's own
     * integration assigns it explicitly before every call.
     */
    protected function log(?object $actor, string $action, array $meta): void
    {
        AuditLogger::$actor = $actor;

        AuditLogger::log($action, $meta);
    }
}
