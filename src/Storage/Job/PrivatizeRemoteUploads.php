<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage\Job;

use Flarum\Queue\AbstractJob;
use Ramon\Chat\Service\UploadPrivacy;

/**
 * Brings a channel's fof/upload attachments back onto the private disk after
 * the channel was made private.
 *
 * The rows were flagged private before this was queued, so they are already
 * served only through the permission check; this moves the bytes and deletes
 * the public copies. It carries the channel id rather than upload ids so a
 * retry picks up whatever is still left, and running it twice is harmless.
 *
 * Extends Flarum's AbstractJob and is pushed onto the queue contract, like
 * Realtime\Job\SendChatEventJob — Laravel's Dispatchable trait does not exist in
 * Flarum.
 */
class PrivatizeRemoteUploads extends AbstractJob
{
    /**
     * A few attempts, for a bucket that is briefly unreachable. Each one only
     * touches what the previous left behind.
     */
    public int $tries = 3;

    public function __construct(
        protected int $channelId
    ) {
        parent::__construct();
    }

    public function __invoke(UploadPrivacy $privacy): void
    {
        [$moved, $found] = $privacy->pullRemoteInChannel($this->channelId);

        // Each failure is already logged per file. Thrown so a real queue retries
        // the remainder; on the sync queue UploadPrivacy catches it.
        if ($moved < $found) {
            throw new \RuntimeException(sprintf(
                'Moved %d of %d remote uploads in chat channel %d to the private disk.',
                $moved,
                $found,
                $this->channelId
            ));
        }
    }
}
