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
 * Deliberately implements no delivery channel.
 *
 * Chat activity belongs in the chat, not in Flarum's bell. The bell is for things
 * that happened elsewhere on the forum and that you would otherwise miss; a new
 * message in a channel you are reading is already surfaced by the chat itself —
 * the unread bubble on the header button, the per-channel badge in the sidebar,
 * the count on the collapsed drawer. Putting it in the bell as well meant a busy
 * channel drowned every other notification you had.
 *
 * Email was never an option here either: this fires for members at notification
 * level "always", so in a busy channel it fires per message.
 *
 * The blueprint is kept rather than deleted because rows of this type already
 * exist in installs that ran the earlier version, and the type has to stay
 * resolvable for those to render.
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
