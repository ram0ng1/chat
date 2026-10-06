<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage\Job;

use Flarum\Queue\AbstractJob;
use Ramon\Chat\Channel;
use Ramon\Chat\Service\UploadPrivacy;

/**
 * Takes one channel's public attachments off the public disk, if the channel
 * has since become one the world cannot read.
 *
 * Queued by whatever noticed the change from outside the channel itself: a tag
 * above it turning restricted, or the hourly sweep. The rule is asked again when
 * the job runs rather than trusted from when it was queued, so a channel that
 * was made public again in between is left alone.
 */
class PrivatizeChannelUploads extends AbstractJob
{
    public int $tries = 3;

    public function __construct(
        protected int $channelId
    ) {
        parent::__construct();
    }

    public function __invoke(UploadPrivacy $privacy): void
    {
        $channel = Channel::query()->find($this->channelId);

        if ($channel === null || ! UploadPrivacy::requiredFor($channel)) {
            return;
        }

        $privacy->privatizeChannel($channel);
    }
}
