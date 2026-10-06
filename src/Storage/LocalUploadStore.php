<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage;

use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Ramon\Chat\Service\UploadPrivacy;
use Ramon\Chat\Upload;

/**
 * The chat's own disks: `chat` under the webroot and `chat-private` under
 * storage. Which of the two is read from the row's `is_private`, so the same
 * store serves both and a row can never point at the wrong one.
 */
class LocalUploadStore implements UploadStore
{
    public function __construct(
        protected Factory $filesystem
    ) {
    }

    public function put(Upload $upload, $stream, string $extension): void
    {
        // Random basename: the client filename is kept only as a display label,
        // never as a path component, so traversal and collisions are impossible.
        $path = sprintf('%s/%s.%s', Carbon::now()->format('Y/m'), Str::random(28), $extension);

        if (! $this->disk($upload)->writeStream($path, $stream)) {
            throw new StorageFailed("Could not write {$path} to the chat disk.");
        }

        $upload->path = $path;
        $upload->storage = Upload::STORAGE_LOCAL;
        $upload->storage_adapter = null;
        $upload->remote_url = null;
    }

    public function delete(Upload $upload): void
    {
        if (! $this->disk($upload)->delete($upload->path) && $this->disk($upload)->exists($upload->path)) {
            throw new StorageFailed("Could not delete {$upload->path} from the chat disk.");
        }
    }

    public function readStream(Upload $upload)
    {
        $disk = $this->disk($upload);

        if (! $disk->exists($upload->path)) {
            return null;
        }

        $stream = $disk->readStream($upload->path);

        return is_resource($stream) ? $stream : null;
    }

    public function size(Upload $upload): ?int
    {
        $disk = $this->disk($upload);

        return $disk->exists($upload->path) ? (int) $disk->size($upload->path) : null;
    }

    protected function disk(Upload $upload): Filesystem
    {
        return $this->filesystem->disk(UploadPrivacy::diskFor((bool) $upload->is_private));
    }
}
