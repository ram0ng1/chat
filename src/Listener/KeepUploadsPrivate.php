<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Ramon\Chat\Event\ChannelWasEdited;
use Ramon\Chat\Event\MessageWasMoved;
use Ramon\Chat\Service\UploadPrivacy;

/**
 * Keeps a private channel's attachments off the public disk after the fact.
 *
 * Sending is covered by MessageDispatcher, which moves a message's files as it
 * binds them. Two other paths put an existing file under a private channel: a
 * channel that is switched to private with a history behind it, and a message a
 * moderator moves into one. Both arrive here.
 */
class KeepUploadsPrivate
{
    public function __construct(
        protected UploadPrivacy $privacy
    ) {
    }

    public function whenChannelEdited(ChannelWasEdited $event): void
    {
        $channel = $event->channel;

        // `wasChanged` reads what the save just wrote, so an edit that touched
        // only the description does not walk the whole channel's attachments.
        if (! $channel->wasChanged('is_private') || ! UploadPrivacy::requiredFor($channel)) {
            return;
        }

        $this->privacy->privatizeChannel($channel);
    }

    public function whenMessageMoved(MessageWasMoved $event): void
    {
        if (! UploadPrivacy::requiredFor($event->to)) {
            return;
        }

        $this->privacy->privatizeMessages([$event->message->id]);
    }
}
