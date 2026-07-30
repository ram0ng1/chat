<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;
use Ramon\Chat\Message;

/**
 * "You were mentioned in a chat message."
 *
 * Mentions are the one chat event that earns an email, because they are directed
 * at a specific person rather than being ambient channel traffic.
 */
/*
 * AlertableInterface is a marker with no methods, and it is easy to leave off —
 * but Flarum's AlertNotificationDriver checks for it before queueing anything, so
 * without it the notification is dropped in silence: no row, no error. That is why
 * the bell never showed chat activity.
 */
class ChatMentionBlueprint implements AlertableInterface, BlueprintInterface, MailableInterface
{
    public function __construct(
        public Message $message
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->message;
    }

    public function getFromUser(): ?User
    {
        return $this->message->user;
    }

    /**
     * Carries enough to render the notification list entry without loading the
     * channel — the list shows many notifications at once.
     */
    public function getData(): array
    {
        return [
            'channelId'   => $this->message->channel_id,
            'channelName' => $this->message->channel?->name,
            'threadId'    => $this->message->thread_id,
        ];
    }

    public static function getType(): string
    {
        return 'chatMention';
    }

    public static function getSubjectModel(): string
    {
        return Message::class;
    }

    /**
     * Flarum expects both views; NotificationMailer renders the pair.
     */
    public function getEmailViews(): array
    {
        return [
            'text' => 'ramon-chat::emails.plain.mentioned',
            'html' => 'ramon-chat::emails.html.mentioned',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ramon-chat.email.mentioned.subject', [
            'channel' => $this->message->channel?->name
                ?? $translator->trans('ramon-chat.email.mentioned.direct_channel'),
        ]);
    }
}
