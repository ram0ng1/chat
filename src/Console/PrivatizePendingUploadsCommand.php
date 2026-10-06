<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Ramon\Chat\Channel;
use Ramon\Chat\Service\UploadPrivacy;
use Ramon\Chat\Storage\Job\PrivatizeChannelUploads;
use Ramon\Chat\Storage\Job\PrivatizeRemoteUploads;
use Ramon\Chat\Upload;

/**
 * Finishes moves to the private disk that nothing else will.
 *
 * Two kinds of leftover. A file on fof/upload that was flagged private but
 * whose bytes are still in the bucket, because PrivatizeRemoteUploads ran out
 * of tries against a bucket that was down: it is already served only through
 * the permission check, but a public copy sits in the bucket until the move
 * finishes. And a file still on the public disk in a channel the world can no
 * longer read, because what made it private fired no event — flarum/tags
 * reorders its hierarchy with bulk updates, so a category moved under a
 * restricted parent is noticed only here.
 *
 * Scheduled hourly (see extend.php). Only queues work; the moves themselves
 * run in the jobs, a channel at a time.
 */
class PrivatizePendingUploadsCommand extends AbstractCommand
{
    public function __construct(
        protected UploadPrivacy $privacy,
        protected Queue $queue
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('chat:uploads:privatize-pending')
            ->setDescription('Finish moving chat attachments that should be private onto the private disk.');
    }

    protected function fire(): int
    {
        $remote = $this->queueRemote();
        $orphans = $this->privatizeRemoteOrphans();
        $public = $this->queuePublicInPrivateChannels();

        $this->info(sprintf(
            'Queued %d channel(s) with remote files still to bring home, %d channel(s) with public files to move; moved %d unattached remote file(s).',
            $remote,
            $public,
            $orphans
        ));

        return 0;
    }

    protected function queueRemote(): int
    {
        $queued = 0;

        Channel::query()
            ->whereHas('messages', fn (Builder $messages) => $messages->whereHas(
                'uploads',
                fn (Builder $uploads) => $uploads->where('storage', Upload::STORAGE_FOF)->where('is_private', true)
            ))
            ->select('id')
            ->chunkById(200, function (Collection $channels) use (&$queued) {
                foreach ($channels as $channel) {
                    $this->queue->push(new PrivatizeRemoteUploads((int) $channel->id));
                    $queued++;
                }
            });

        return $queued;
    }

    /**
     * A flagged remote file with no message has no channel to queue under. The
     * flag is only ever set on a file that was sent, so this is a message
     * deleted mid-move; there are never many, and each is moved here.
     */
    protected function privatizeRemoteOrphans(): int
    {
        $moved = 0;

        Upload::query()
            ->where('storage', Upload::STORAGE_FOF)
            ->where('is_private', true)
            ->where('message_id', null)
            ->chunkById(100, function (Collection $uploads) use (&$moved) {
                $moved += $this->privacy->privatizeUploads($uploads);
            });

        return $moved;
    }

    protected function queuePublicInPrivateChannels(): int
    {
        $queued = 0;

        Channel::query()
            ->where(fn (Builder $query) => $query
                ->where('type', Channel::TYPE_DIRECT)
                ->orWhere('is_private', true)
                ->orWhereNotNull('tag_id'))
            ->whereHas('messages', fn (Builder $messages) => $messages->whereHas(
                'uploads',
                fn (Builder $uploads) => $uploads->where('is_private', false)
            ))
            ->chunkById(100, function (Collection $channels) use (&$queued) {
                foreach ($channels as $channel) {
                    if (! UploadPrivacy::requiredFor($channel)) {
                        continue;
                    }

                    $this->queue->push(new PrivatizeChannelUploads((int) $channel->id));
                    $queued++;
                }
            });

        return $queued;
    }
}
