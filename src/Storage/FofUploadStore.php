<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage;

use Flarum\Settings\SettingsRepositoryInterface;
use FoF\Upload\Adapters\Flysystem;
use FoF\Upload\Adapters\Manager;
use FoF\Upload\Downloader\DefaultDownloader;
use FoF\Upload\File;
use FoF\Upload\Helpers\Util;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Ramon\Chat\Upload;

/**
 * Public attachments written through fof/upload's storage adapters.
 *
 * fof/upload is used as a driver and nothing more. Its adapters know how to put
 * bytes on S3, Qiniu or a CDN-fronted local directory and how to name the URL
 * that results, which is the part worth borrowing. Everything else stays the
 * chat's: the permission is `ramon-chat.upload`, the mime allow-list and size
 * limit are the chat's own, and no `fof_upload_files` row is ever written.
 *
 * That last point is load-bearing. fof/upload treats a file with no post as
 * abandoned, and its cleanup command deletes those. A chat attachment never has
 * a post, so a row there would put every chat file on fof's delete list. The
 * `File` passed to the adapter is built in memory, used, and dropped unsaved.
 *
 * Only Flysystem-backed adapters are accepted. Imgur (the one adapter that is
 * not) re-encodes and re-hosts the image under its own terms and cannot delete
 * by path, which breaks every delete path the chat relies on.
 *
 * This is the only class that names anything in the FoF namespace. It is built
 * by UploadStorage, which first checks that fof/upload is installed and
 * enabled, so a forum without it never loads these classes.
 */
class FofUploadStore implements UploadStore
{
    public function __construct(
        protected Container $container,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * Whether fof/upload's classes are present at all. Enabled or not is
     * UploadStorage's question.
     */
    public static function supported(): bool
    {
        return class_exists(Manager::class);
    }

    /**
     * The adapters an admin may pick for chat attachments: every one fof/upload
     * reports as usable, minus Imgur.
     *
     * @return string[]
     */
    public function adapterKeys(): array
    {
        return $this->manager()->adapters()
            ->filter(fn ($available) => (bool) $available)
            ->keys()
            ->reject(fn ($key) => $key === 'imgur')
            ->values()
            ->all();
    }

    public function put(Upload $upload, $stream, string $extension): void
    {
        $adapter = $this->adapterFor((string) $upload->mime_type);

        $file = $this->file($upload);
        // Server generated, like the local store's: the client's filename never
        // reaches a path. The adapter prefixes it with a date and a timestamp.
        $file->base_name = Str::random(28).'.'.$extension;

        try {
            $result = $adapter->upload($file, null, $stream);
        } catch (\Throwable $e) {
            throw new StorageFailed('fof/upload adapter failed: '.$e->getMessage(), 0, $e);
        }

        if ($result === false || ! $file->path || ! $file->url) {
            throw new StorageFailed("fof/upload adapter {$adapter->adapterKey} refused the write.");
        }

        $upload->path = (string) $file->path;
        $upload->storage = Upload::STORAGE_FOF;
        $upload->storage_adapter = $adapter->adapterKey;
        $upload->remote_url = (string) $file->url;
    }

    public function delete(Upload $upload): void
    {
        $adapter = $this->adapterOf($upload);

        // Flysystem's delete is quiet about a missing file and returns false only
        // when the backend refused.
        if ($adapter->delete($this->file($upload)) === false) {
            throw new StorageFailed("fof/upload adapter {$adapter->adapterKey} could not delete {$upload->path}.");
        }
    }

    /**
     * Fetched the way fof/upload fetches its own files: read off disk for the
     * local adapter, over HTTP from the public URL for everything else. Only a
     * 200 counts; an error page from the bucket must not be served as the file.
     */
    public function readStream(Upload $upload)
    {
        try {
            $file = $this->file($upload);
            $file->upload_method = (string) $upload->storage_adapter;

            $response = $this->container->make(DefaultDownloader::class)->download($file);
        } catch (\Throwable $e) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        try {
            return StreamWrapper::getResource($response->getBody());
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function size(Upload $upload): ?int
    {
        return null;
    }

    /**
     * The adapter a new file goes to: the one picked in the chat's settings, or,
     * when none is, the one fof/upload's own mime mapping names for this type.
     */
    protected function adapterFor(string $mime): Flysystem
    {
        $key = trim((string) $this->settings->get('ramon-chat.fof_upload_adapter', ''));

        try {
            $adapter = $key !== ''
                ? $this->manager()->instantiate($key)
                : $this->container->make(Util::class)->getAdapterForMime($mime);
        } catch (\Throwable $e) {
            throw new StorageFailed('fof/upload adapter unavailable: '.$e->getMessage(), 0, $e);
        }

        return $this->accept($adapter, $key !== '' ? $key : "mapping for {$mime}");
    }

    /**
     * The adapter an existing file was written with. The recorded key, not the
     * current settings: an admin who switches adapter must not strand every file
     * written before the switch.
     */
    protected function adapterOf(Upload $upload): Flysystem
    {
        $key = (string) $upload->storage_adapter;

        if ($key === '') {
            throw new StorageFailed("Upload {$upload->id} has no recorded fof/upload adapter.");
        }

        try {
            $adapter = $this->manager()->instantiate($key);
        } catch (\Throwable $e) {
            throw new StorageFailed("fof/upload adapter {$key} unavailable: ".$e->getMessage(), 0, $e);
        }

        return $this->accept($adapter, $key);
    }

    protected function accept(mixed $adapter, string $label): Flysystem
    {
        if (! $adapter instanceof Flysystem) {
            throw new StorageFailed("fof/upload adapter ({$label}) is not a Flysystem adapter and cannot hold chat attachments.");
        }

        return $adapter;
    }

    /**
     * The in-memory record the adapter API is shaped around. Never saved; see
     * the class note for why.
     */
    protected function file(Upload $upload): File
    {
        $file = new File();
        $file->type = (string) $upload->mime_type;
        $file->size = (int) $upload->size;

        if ($upload->path) {
            $file->path = $upload->path;
            $file->base_name = basename(str_replace('\\', '/', $upload->path));
        }

        if ($upload->remote_url) {
            $file->url = $upload->remote_url;
        }

        return $file;
    }

    protected function manager(): Manager
    {
        return $this->container->make(Manager::class);
    }
}
