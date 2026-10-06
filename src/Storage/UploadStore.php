<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage;

use Ramon\Chat\Upload;

/**
 * Where the bytes of a chat attachment are kept.
 *
 * Two implementations: LocalUploadStore, the chat's own pair of disks, and
 * FofUploadStore, whatever storage fof/upload is configured with. UploadStorage
 * decides which one a given upload uses; nothing else should pick one directly.
 *
 * A store only moves bytes. It never decides whether a file is private — that is
 * Service\UploadPrivacy's rule — and it never writes the row: `put` fills in the
 * columns that say where the bytes went and leaves saving to the caller.
 */
interface UploadStore
{
    /**
     * Writes the stream and records on the row where it went: `path`, `storage`,
     * `storage_adapter` and `remote_url`. The row's `is_private` and `mime_type`
     * must already be set, since they decide the destination.
     *
     * @param  resource  $stream
     *
     * @throws StorageFailed when the bytes could not be written.
     */
    public function put(Upload $upload, $stream, string $extension): void;

    /**
     * Removes the bytes. A file that is already gone is not a failure.
     *
     * @throws StorageFailed when the store refused.
     */
    public function delete(Upload $upload): void;

    /**
     * The bytes, or null when the file cannot be found or read.
     *
     * @return resource|null
     */
    public function readStream(Upload $upload);

    /**
     * The stored size in bytes, or null when the store cannot tell without
     * fetching the file.
     */
    public function size(Upload $upload): ?int;
}
