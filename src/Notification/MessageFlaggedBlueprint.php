<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;
use Ramon\Chat\Message;
use Ramon\Chat\MessageFlag;

/**
 * "A chat message was reported."
 *
 * Sent to the moderators, so a report reaches them the way a flag on a post does
 * rather than waiting to be discovered the next time someone opens the queue.
 *
 * AlertableInterface is a marker with no methods and easy to leave off — Flarum's
 * AlertNotificationDriver checks for it before queueing anything, so without it
 * the notification is dropped in silence: no row, no error.
 *
 * Not mailable. A busy channel can produce a run of reports, and a mailbox is the
 * wrong place for a queue; the alert is seen the next time they look at the forum.
 */
class MessageFlaggedBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(
        public MessageFlag $flag,
        public Message $message,
        public ?User $reporter = null
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->message;
    }

    public function getFromUser(): ?User
    {
        return $this->reporter;
    }

    /**
     * Ids and the reason key only.
     *
     * The `data` column is returned verbatim to every recipient with no policy
     * re-check, so the reported text must never live here: a message hidden or
     * deleted after the report would still be readable from the notification.
     * The channel name is the one exception, and it is safe because the recipients
     * are moderators who could open the channel anyway.
     */
    public function getData(): array
    {
        return [
            'flagId'      => (int) $this->flag->id,
            'messageId'   => (int) $this->message->id,
            'channelId'   => (int) $this->message->channel_id,
            'channelName' => (string) ($this->message->channel->name ?? ''),
            'reason'      => (string) $this->flag->reason,
        ];
    }

    public static function getType(): string
    {
        return 'chatMessageFlagged';
    }

    public static function getSubjectModel(): string
    {
        return Message::class;
    }
}
