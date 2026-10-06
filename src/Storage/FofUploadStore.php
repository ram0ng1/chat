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
use Flarum\Foundation\Paths;
use FoF\Upload\File;
use FoF\Upload\Helpers\Util;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;
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
    /**
     * Seconds. Generous for a bucket, short enough that a request serving a
     * private file, or the job moving one, gives up rather than hangs.
     */
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT = 30;

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
     * Fetched the way fof/upload fetches its own files — off disk for the local
     * adapter, over HTTP from the public URL for everything else — but not with
     * fof/upload's downloader, whose HTTP client has no timeout: one slow bucket
     * would hold a request, or a queue worker, for as long as it liked.
     *
     * The URL is the row's, so it is only fetched when its host is the one the
     * adapter would name for the same path today. A row edited to point at an
     * internal address is refused before any connection is made. Only a 200
     * counts; an error page from the bucket must not be served as the file.
     */
    public function readStream(Upload $upload)
    {
        if ($upload->storage_adapter === 'local') {
            return $this->readLocal($upload);
        }

        try {
            $url = $this->trustedUrl($upload);
        } catch (\Throwable $e) {
            return null;
        }

        return $url === null ? null : $this->fetch($url, $upload);
    }

    public function size(Upload $upload): ?int
    {
        return null;
    }

    /**
     * fof/upload's local adapter writes under `public/assets/files`. Read from
     * there directly, confined to that directory: the path comes from the row.
     *
     * @return resource|null
     */
    protected function readLocal(Upload $upload)
    {
        $base = realpath($this->container->make(Paths::class)->public.'/assets/files');
        $relative = (string) $upload->path;

        if ($base === false || $relative === '' || str_contains($relative, "\0") || str_contains($relative, '://')) {
            return null;
        }

        $resolved = realpath($base.DIRECTORY_SEPARATOR.ltrim($relative, '/\\'));

        if ($resolved === false
            || ! is_file($resolved)
            || ! str_starts_with($resolved.DIRECTORY_SEPARATOR, $base.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $stream = fopen($resolved, 'rb');

        return is_resource($stream) ? $stream : null;
    }

    /**
     * The row's URL, if it is http(s) on the host the file's adapter would
     * generate for the same path now. Asking the adapter is what makes the
     * check independent of the row: its host comes from the admin's settings.
     */
    protected function trustedUrl(Upload $upload): ?string
    {
        $url = (string) $upload->remote_url;
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $expected = strtolower((string) parse_url($this->generatedUrl($upload), PHP_URL_HOST));

        return $expected !== '' && hash_equals($expected, $host) ? $url : null;
    }

    /**
     * The URL the file's adapter names for its path. `generateUrl` is protected
     * on fof/upload's Flysystem base, so it is called from that scope; it reads
     * only settings and the path, and writes nothing but the File it is given.
     */
    protected function generatedUrl(Upload $upload): string
    {
        $adapter = $this->adapterOf($upload);
        $file = $this->file($upload);
        $file->url = '';

        $generate = \Closure::bind(function (File $file): void {
            $this->meta = ['path' => $file->path];
            $this->generateUrl($file);
        }, $adapter, Flysystem::class);

        $generate($file);

        return (string) $file->url;
    }

    /**
     * Downloads into a temporary stream. Bounded three ways: connecting, the
     * whole transfer, and size — the file is a copy of one the chat wrote, so a
     * body much larger than the recorded size is not it. Redirects are not
     * followed, since they would lead off the host just checked.
     *
     * @return resource|null
     */
    protected function fetch(string $url, Upload $upload)
    {
        $sink = Utils::streamFor(fopen('php://temp/maxmemory:'.(2 * 1024 * 1024), 'w+b'));
        $limit = max((int) $upload->size, 1) * 2 + 1024 * 1024;

        try {
            $response = $this->http()->request('GET', $url, [
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout'         => self::TIMEOUT,
                'allow_redirects' => false,
                'http_errors'     => false,
                'sink'            => $sink,
                'progress'        => function ($expected, $downloaded) use ($limit) {
                    if ($expected > $limit || $downloaded > $limit) {
                        throw new \RuntimeException('Remote file is larger than the upload it should be.');
                    }
                },
            ]);
        } catch (\Throwable $e) {
            $sink->close();

            return null;
        }

        // Checked again once it is in: the progress callback is how cURL aborts
        // early, and not every handler calls it.
        if ($response->getStatusCode() !== 200 || ($response->getBody()->getSize() ?? 0) > $limit) {
            $sink->close();

            return null;
        }

        // Wrapped rather than detached: the wrapper keeps the PSR stream alive,
        // which would otherwise close the handle when it went out of scope.
        try {
            $body = $response->getBody();
            $body->rewind();

            return StreamWrapper::getResource($body);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function http(): ClientInterface
    {
        return new Client();
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
