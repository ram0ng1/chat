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
use Ramon\Chat\Channel;

/**
 * "You were added to a channel."
 *
 * The only way into a private channel is for someone with `manageMembers` to put
 * you there, and that happens entirely on their screen — without this the channel
 * simply appears in your sidebar one day with no explanation of who added you or
 * why it exists.
 *
 * Not mailable. Being added to a chat room is not urgent enough to interrupt
 * someone's inbox, and the alert is seen the next time they look at the forum.
 */
/*
 * AlertableInterface is a marker with no methods, and it is easy to leave off —
 * but Flarum's AlertNotificationDriver checks for it before queueing anything, so
 * without it the notification is dropped in silence: no row, no error. That is why
 * the bell never showed chat activity.
 */
class ChannelInviteBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(
        public Channel $channel,
        public ?User $inviter = null
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->channel;
    }

    public function getFromUser(): ?User
    {
        return $this->inviter;
    }

    /**
     * The name is carried rather than looked up when the list renders: a private
     * channel is only visible to members, and the notification list is rendered
     * with a serialiser that would rightly refuse to load it for anyone who has
     * since left.
     */
    public function getData(): array
    {
        return [
            'channelId'   => $this->channel->id,
            'channelName' => $this->channel->name,
            'isPrivate'   => (bool) $this->channel->is_private,
        ];
    }

    public static function getType(): string
    {
        return 'chatChannelInvite';
    }

    public static function getSubjectModel(): string
    {
        return Channel::class;
    }
}
