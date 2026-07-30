<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;
use Ramon\Chat\Message;

/**
 * "There is a new message in a channel you watch."
 *
 * Alert-only by design. This fires for members at notification level "always",
 * so in a busy channel it can fire per message — routing it to email would be a
 * mail-bomb. Only ChatMentionBlueprint is mailable.
 */
class ChatMessageBlueprint implements BlueprintInterface
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
        return 'chatMessage';
    }

    public static function getSubjectModel(): string
    {
        return Message::class;
    }
}
