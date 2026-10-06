<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Database\AbstractModel;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Event\ChannelWasEdited;
use Ramon\Chat\Event\MessageWasMoved;
use Ramon\Chat\Service\UploadPrivacy;
use Ramon\Chat\Storage\Job\PrivatizeTagChannels;

/**
 * Keeps a private channel's attachments off the public disk after the fact.
 *
 * Sending is covered by MessageDispatcher, which moves a message's files as it
 * binds them. Three other paths put an existing file under a private channel: a
 * channel that is switched to private or rebound to a restricted category with
 * a history behind it, a message a moderator moves into one, and a category
 * that becomes restricted — or is moved under one that is — while channels are
 * bound to it. All arrive here. What none of them catches (flarum/tags reorders
 * its hierarchy with bulk updates that fire no event) the hourly
 * `chat:uploads:privatize-pending` sweep picks up.
 */
class KeepUploadsPrivate
{
    public function __construct(
        protected UploadPrivacy $privacy,
        protected Queue $queue,
        protected LoggerInterface $log
    ) {
    }

    public function whenChannelEdited(ChannelWasEdited $event): void
    {
        $channel = $event->channel;

        // `wasChanged` reads what the save just wrote, so an edit that touched
        // only the description does not walk the whole channel's attachments.
        if (! $channel->wasChanged(['is_private', 'tag_id']) || ! UploadPrivacy::requiredFor($channel)) {
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

    /**
     * A flarum/tags tag was saved. Typed loosely so this file never names a
     * class from an extension that may be absent; it is only registered when
     * flarum/tags is enabled.
     *
     * A tag is saved on every post in it (its last-posted columns), so the test
     * is on what changed and runs no query; the channels are found in a job.
     */
    public function whenTagSaved(AbstractModel $tag): void
    {
        $restricted = $tag->wasChanged('is_restricted') && $tag->getAttribute('is_restricted');
        $moved = $tag->wasChanged('parent_id') && $tag->getAttribute('parent_id') !== null;

        if ($tag->wasRecentlyCreated || (! $restricted && ! $moved)) {
            return;
        }

        try {
            $this->queue->push(new PrivatizeTagChannels((int) $tag->getKey()));
        } catch (\Throwable $e) {
            // The tag edit already committed and must not fail on this; the
            // hourly sweep finds the channels the job would have.
            $this->log->error('[ramon-chat] could not queue privatizing the channels of a restricted tag', [
                'tag'   => $tag->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
