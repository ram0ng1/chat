<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Storage\Job\PrivatizeRemoteUploads;
use Ramon\Chat\Storage\UploadStorage;
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
 * When fof/upload is the configured storage, public files live in its bucket
 * instead of on `chat` (see Storage\UploadStorage). Private files never do: a
 * bucket is public by design. So making a file private also means bringing its
 * bytes home, which privatize() does.
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

    /**
     * flarum/tags nests two levels deep. The bound is there for a corrupted
     * hierarchy that loops, which is treated as restricted rather than walked
     * forever.
     */
    protected const MAX_TAG_DEPTH = 8;

    public function __construct(
        protected Factory $filesystem,
        protected LoggerInterface $log,
        protected UploadStorage $storage,
        protected Queue $queue
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

        // A relation loaded before the channel was rebound to another tag would
        // answer for the old one.
        $loaded = $channel->relationLoaded('tag') ? $channel->getRelation('tag') : null;
        $tag = $loaded !== null && (int) $loaded->getKey() === (int) $channel->tag_id
            ? $loaded
            : $relation->first();

        return self::tagIsRestricted($tag);
    }

    /**
     * Whether a tag, or any tag above it, is restricted.
     *
     * flarum/tags hides a child whose parent the reader cannot see, so an
     * unrestricted child of a restricted parent is no more public than the
     * parent. A tag that is missing — the bound one deleted, or a parent gone
     * from under its child — counts as restricted: the channel is hidden from
     * everyone but admins then, and its files must not be readable by URL.
     */
    public static function tagIsRestricted(?AbstractModel $tag): bool
    {
        for ($depth = 0; $depth < self::MAX_TAG_DEPTH; $depth++) {
            if ($tag === null) {
                return true;
            }

            if ($tag->getAttribute('is_restricted')) {
                return true;
            }

            if ($tag->getAttribute('parent_id') === null) {
                return false;
            }

            $tag = $tag->newQuery()->whereKey((int) $tag->getAttribute('parent_id'))->first();
        }

        return true;
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
     * A file on fof/upload goes the other way round. Its public URL lives in the
     * bucket, outside anything this code can take down quickly, so the row flips
     * first: from that moment Upload::url() hands out the controller route and
     * no new copy of the bucket URL leaves the forum. Then the bytes are pulled
     * onto the private disk and the remote copy is deleted. A row that is
     * already private but still remote is one of those moves left unfinished,
     * and is finished here.
     *
     * @throws \RuntimeException when the file cannot be moved.
     */
    public function privatize(Upload $upload): void
    {
        if ($upload->isRemote()) {
            if (! $upload->is_private) {
                $upload->is_private = true;
                $upload->save();
            }

            $this->pullRemote($upload);

            return;
        }

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
     * The given rows, whatever channel they belong to. For callers that found
     * them by something other than a message or a channel.
     *
     * @param  iterable<Upload>  $uploads
     * @return int How many were moved.
     */
    public function privatizeUploads(iterable $uploads): int
    {
        return $this->privatizeEach($uploads);
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

        // Files on fof/upload are flagged here, in this request, so none of them
        // is handed out by its bucket URL again; the bytes follow in a job,
        // because each is a download and an upload and a busy room's history
        // does not fit in one request. Flagged first, so the local pass below
        // does not see them.
        $remote = Upload::query()
            ->where('storage', Upload::STORAGE_FOF)
            ->where('is_private', false)
            ->whereHas('message', fn ($query) => $query->where('channel_id', $channel->id))
            ->update(['is_private' => true, 'updated_at' => Carbon::now()]);

        if ($remote > 0) {
            $this->queueRemote((int) $channel->id);
        }

        Upload::query()
            ->where('is_private', false)
            ->whereHas('message', fn ($query) => $query->where('channel_id', $channel->id))
            ->chunkById(200, function (iterable $uploads) use (&$moved) {
                $moved += $this->privatizeEach($uploads);
            });

        return $moved;
    }

    /**
     * The job's half of privatizeChannel(): every file in the channel that is
     * flagged private but whose bytes are still on fof/upload. Safe to run again;
     * a file already pulled back is local and no longer matches.
     *
     * @return array{0: int, 1: int} How many were moved, and how many were found.
     */
    public function pullRemoteInChannel(int $channelId): array
    {
        $moved = 0;
        $found = 0;

        Upload::query()
            ->where('storage', Upload::STORAGE_FOF)
            ->where('is_private', true)
            ->whereHas('message', fn ($query) => $query->where('channel_id', $channelId))
            ->chunkById(100, function (Collection $uploads) use (&$moved, &$found) {
                $found += $uploads->count();
                $moved += $this->privatizeEach($uploads);
            });

        return [$moved, $found];
    }

    /**
     * Pushed, not run: on Flarum's default sync queue that is the same thing,
     * and on a real queue it takes the downloads off the request. A failure to
     * push is logged rather than thrown — the rows are already flagged, so
     * nothing is handed out publicly, and a channel edit must not fail on it.
     */
    protected function queueRemote(int $channelId): void
    {
        try {
            $this->queue->push(new PrivatizeRemoteUploads($channelId));
        } catch (\Throwable $e) {
            $this->log->error('[ramon-chat] could not move a channel\'s remote uploads to the private disk', [
                'channel' => $channelId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Copies a remote file onto the private disk under a fresh local path, then
     * deletes the remote copy, then points the row at the local one.
     *
     * If the remote copy will not go, the local copy is removed again and the
     * row is left as it was — private, still remote — so the next attempt starts
     * from the same place. Recording the move anyway would leave a public copy
     * in the bucket that no row points at and nothing would ever clean up.
     */
    protected function pullRemote(Upload $upload): void
    {
        $remote = $this->storage->for($upload);
        $stream = $remote->readStream($upload);

        if (! is_resource($stream)) {
            throw new \RuntimeException("Could not read upload {$upload->id} from fof/upload.");
        }

        $extension = strtolower(pathinfo(str_replace('\\', '/', $upload->path), PATHINFO_EXTENSION));
        $path = sprintf('%s/%s.%s', Carbon::now()->format('Y/m'), Str::random(28), $extension ?: 'bin');
        $to = $this->filesystem->disk(self::PRIVATE_DISK);

        try {
            $written = $to->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        if (! $written) {
            throw new \RuntimeException("Could not write {$path} to the private chat disk.");
        }

        try {
            $remote->delete($upload);
        } catch (\Throwable $e) {
            $to->delete($path);

            throw $e;
        }

        $upload->path = $path;
        $upload->storage = Upload::STORAGE_LOCAL;
        $upload->storage_adapter = null;
        $upload->remote_url = null;
        $upload->save();
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
