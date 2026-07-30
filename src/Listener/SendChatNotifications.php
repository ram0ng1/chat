<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Message;
use Ramon\Chat\Notification\ChatMentionBlueprint;
use Ramon\Chat\Notification\ChatMessageBlueprint;
use Ramon\Chat\Service\UnreadTracker;
use Ramon\Chat\ThreadUser;

/**
 * Fans a new message out to notifications.
 *
 * Two audiences, and a user in both gets only the mention — the stronger signal
 * wins so nobody is notified twice for one message.
 */
class SendChatNotifications
{
    public function __construct(
        protected NotificationSyncer $notifications,
        protected UnreadTracker $unread,
        protected LoggerInterface $log
    ) {
    }

    public function handle(MessageWasSent $event): void
    {
        $message = $event->message;

        // System messages are stream furniture, not events worth notifying about.
        if ($message->isSystem()) {
            return;
        }

        $channel = $message->channel;

        if ($channel === null) {
            return;
        }

        $mentionedIds = $this->unread->mentionedUserIds($message);

        $mentioned = $this->recipientsFor($message, $mentionedIds, isMention: true);

        if ($mentioned !== []) {
            $this->dispatch(new ChatMentionBlueprint($message), $mentioned);
        }

        // Watchers, minus anyone already notified as a mention.
        $watcherIds = ChannelUser::query()
            ->where('channel_id', $channel->id)
            ->whereNull('left_at')
            ->where('muted', false)
            ->where('notification_level', ChannelUser::LEVEL_ALWAYS)
            ->when(
                $message->user_id !== null,
                fn ($q) => $q->where('user_id', '!=', $message->user_id)
            )
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Thread participants watching the thread are notified even when their
        // channel level is lower — opting into a thread is a stronger signal than
        // the channel default.
        if ($message->thread_id !== null) {
            $threadWatcherIds = ThreadUser::query()
                ->where('thread_id', $message->thread_id)
                ->where('notification_level', ThreadUser::LEVEL_ALWAYS)
                ->when(
                    $message->user_id !== null,
                    fn ($q) => $q->where('user_id', '!=', $message->user_id)
                )
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $watcherIds = array_merge($watcherIds, $threadWatcherIds);
        }

        $alreadyNotified = array_map(fn (User $u) => (int) $u->id, $mentioned);

        $watcherIds = array_values(array_diff(array_unique($watcherIds), $alreadyNotified));

        $watchers = $this->recipientsFor($message, $watcherIds, isMention: false);

        if ($watchers !== []) {
            $this->dispatch(new ChatMessageBlueprint($message), $watchers);
        }
    }

    /**
     * Sends a notification without letting a delivery failure take the message
     * with it.
     *
     * On the sync queue driver, NotificationSyncer mails inline — so an
     * unreachable mail server would otherwise propagate out of this listener,
     * abort the send, and make the channel unusable until mail was fixed. A
     * message that was accepted must stay accepted; the notification is the
     * expendable half.
     *
     * @param  User[]  $recipients
     */
    protected function dispatch(BlueprintInterface $blueprint, array $recipients): void
    {
        try {
            $this->notifications->sync($blueprint, $recipients);
        } catch (\Throwable $e) {
            $this->log->warning(
                '[ramon/chat] notification delivery failed for '.$blueprint::getType().': '.$e->getMessage()
            );
        }
    }

    /**
     * Resolves ids to users who may actually receive this notification.
     *
     * Three filters apply, and all three are necessary:
     *  - the user must still be able to see the message (permissions can change
     *    between send and fan-out, and a mention must never leak content);
     *  - the user must not have opted out of chat entirely;
     *  - for channel-wide mentions, the user must not have opted out of @here/@all.
     *
     * @param  int[]  $userIds
     * @return User[]
     */
    protected function recipientsFor(Message $message, array $userIds, bool $isMention): array
    {
        if ($userIds === []) {
            return [];
        }

        $isChannelWide = $isMention && $message->mentions->contains(fn ($m) => $m->isChannelWide());

        $recipients = [];

        // Chunked so a channel-wide mention in a large channel does not load every
        // member at once.
        foreach (array_chunk($userIds, 200) as $chunk) {
            $users = User::query()->whereIn('id', $chunk)->get();

            foreach ($users as $user) {
                if ($user->getPreference('ramon-chat.enabled') === false) {
                    continue;
                }

                if ($isChannelWide && $user->getPreference('ramon-chat.allowChannelWideMentions') === false) {
                    continue;
                }

                // The authoritative check. Cheaper filters above exist only to
                // avoid running this one more often than needed.
                if (! Message::whereVisibleTo($user)->whereKey($message->id)->exists()) {
                    continue;
                }

                $recipients[] = $user;
            }
        }

        return $recipients;
    }
}
