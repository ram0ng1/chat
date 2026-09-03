<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Illuminate\Contracts\Filesystem\Factory;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Upload;

/**
 * Decides which disk an attachment lives on, and moves it there.
 *
 * The chat has two disks. `chat` is under `public/assets/chat`, served by the web
 * server with nothing in front of it: fine for a channel anyone can read, and the
 * cheapest way to deliver a picture. `chat-private` is under `storage`, outside
 * the webroot, reachable only through ServeUploadController — which asks the
 * upload's visibility scope before it streams a byte.
 *
 * A private channel's attachment has to be on the second disk, or the channel is
 * private in name only: anyone holding the URL, which after one screenshot is
 * anyone at all, could fetch the file with no session. The same goes for a direct
 * conversation, which is private by construction.
 *
 * Privacy is one-way. A channel that is made public later keeps its existing
 * attachments on the private disk; they are still served, just through the
 * controller. Moving them back out would republish files people posted under a
 * different promise.
 */
class UploadPrivacy
{
    public const PUBLIC_DISK = 'chat';
    public const PRIVATE_DISK = 'chat-private';

    public function __construct(
        protected Factory $filesystem,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Whether attachments posted in this channel must be on the private disk.
     *
     * Three kinds of channel are not readable by the world: a direct
     * conversation, a channel marked private, and a channel bound to a restricted
     * tag — the last one is what the README means by "a private category produces
     * a private channel", and its pictures are no less private for having been
     * restricted by the tag rather than by the channel.
     */
    public static function requiredFor(Channel $channel): bool
    {
        if ($channel->isDirect() || $channel->isPrivate()) {
            return true;
        }

        if ($channel->tag_id === null) {
            return false;
        }

        // Null when flarum/tags is not enabled, in which case a tag id on the row
        // is a leftover and restricts nothing.
        $relation = $channel->tag();

        if ($relation === null) {
            return false;
        }

        $tag = $channel->relationLoaded('tag') ? $channel->getRelation('tag') : $relation->first();

        return (bool) ($tag->is_restricted ?? false);
    }

    public static function diskFor(bool $private): string
    {
        return $private ? self::PRIVATE_DISK : self::PUBLIC_DISK;
    }

    /**
     * Moves the file off the public disk and flags the row. The file goes first:
     * if the row flips and the move fails, the URL 404s, which is a broken image
     * and not a leak; the other order leaves a public file behind a private URL.
     *
     * @throws \RuntimeException when the file cannot be moved.
     */
    public function privatize(Upload $upload): void
    {
        if ($upload->is_private) {
            return;
        }

        $this->move($upload->path);

        $upload->is_private = true;
        $upload->save();
    }

    /**
     * Every attachment of the given messages that is still public.
     *
     * @param  int[]  $messageIds
     * @return int How many were moved.
     */
    public function privatizeMessages(array $messageIds): int
    {
        $messageIds = array_values(array_filter(array_map('intval', $messageIds)));

        if ($messageIds === []) {
            return 0;
        }

        return $this->privatizeEach(
            Upload::query()
                ->where('is_private', false)
                ->whereHas('message', fn ($query) => $query->whereKey($messageIds))
                ->get()
        );
    }

    /**
     * Every public attachment in a channel, for when a channel becomes private
     * after the fact. Chunked: a busy room can hold thousands.
     *
     * @return int How many were moved.
     */
    public function privatizeChannel(Channel $channel): int
    {
        $moved = 0;

        Upload::query()
            ->where('is_private', false)
            ->whereHas('message', fn ($query) => $query->where('channel_id', $channel->id))
            ->chunkById(200, function (iterable $uploads) use (&$moved) {
                $moved += $this->privatizeEach($uploads);
            });

        return $moved;
    }

    /**
     * Best effort over a set: one file that will not move must not leave the
     * rest published. The failure is logged at error level, because a file that
     * stayed public in a private channel is exactly what an operator needs to
     * hear about.
     *
     * @param  iterable<Upload>  $uploads
     */
    protected function privatizeEach(iterable $uploads): int
    {
        $moved = 0;

        foreach ($uploads as $upload) {
            try {
                $this->privatize($upload);
                $moved++;
            } catch (\Throwable $e) {
                $this->log->error('[ramon-chat] could not move an upload to the private disk', [
                    'upload' => $upload->id,
                    'path'   => $upload->path,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        return $moved;
    }

    protected function move(string $path): void
    {
        $from = $this->filesystem->disk(self::PUBLIC_DISK);
        $to = $this->filesystem->disk(self::PRIVATE_DISK);

        if (! $from->exists($path)) {
            // Already moved, or never written. Either way there is nothing public
            // to take down, and the row may still be flagged.
            if (! $to->exists($path)) {
                $this->log->warning('[ramon-chat] upload file missing from both disks', ['path' => $path]);
            }

            return;
        }

        $stream = $from->readStream($path);

        if (! is_resource($stream)) {
            throw new \RuntimeException("Could not read {$path} from the public chat disk.");
        }

        // Flysystem reads from the handle and leaves closing it to the caller.
        try {
            $written = $to->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        if (! $written) {
            throw new \RuntimeException("Could not write {$path} to the private chat disk.");
        }

        $from->delete($path);
    }
}
