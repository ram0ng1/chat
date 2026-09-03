<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Http\Exception\RouteNotFoundException;
use Flarum\Http\RequestUtil;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Upload;

/**
 * Streams an attachment that lives outside the webroot.
 *
 * This is the only way to a private channel's files. The web server cannot see
 * them, so every fetch comes through here and is answered with the upload's own
 * visibility scope — the same rule the JSON:API resource applies — before a byte
 * is read. Someone who cannot see the message gets a 404, not a 403: the URL
 * must not confirm that the file exists.
 *
 * Headers are derived from the row, never from the request. The mime type was
 * sniffed from the bytes at upload time against an allow-list, so it is trusted
 * here; the display name is quoted twice, as an ASCII fallback and as an RFC
 * 5987 value, so a name in any script survives the round trip.
 *
 * Range requests are honoured for a single range. Not an optimisation: Safari
 * refuses to play audio or video from a server that ignores them at all.
 */
class ServeUploadController implements RequestHandlerInterface
{
    /**
     * Types the browser may render in place. Everything else is offered as a
     * download, which keeps a text file from being interpreted as a page.
     */
    protected const INLINE = ['image/', 'audio/', 'video/', 'application/pdf'];

    /**
     * An hour in the browser's private cache. The check runs again after that,
     * so someone removed from a channel stops being able to refetch its files
     * within the hour — and never could from another browser.
     */
    protected const MAX_AGE = 3600;

    public function __construct(
        protected Factory $filesystem
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $id = (int) Arr::get($request->getQueryParams(), 'id');

        /** @var Upload|null $upload */
        $upload = $id > 0 ? Upload::whereVisibleTo($actor)->find($id) : null;

        if ($upload === null) {
            throw new RouteNotFoundException();
        }

        $disk = $this->filesystem->disk($upload->diskName());

        if (! $disk->exists($upload->path)) {
            throw new RouteNotFoundException();
        }

        $size = (int) $disk->size($upload->path);
        $etag = '"'.hash('sha256', $upload->id.':'.$size.':'.$upload->updated_at->getTimestamp()).'"';

        $headers = [
            'Content-Type'           => $upload->mime_type ?: 'application/octet-stream',
            'Content-Disposition'    => $this->disposition($upload),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age='.self::MAX_AGE,
            'ETag'                   => $etag,
            'Accept-Ranges'          => 'bytes',
        ];

        // A PDF can carry scripts; a sandboxed document cannot reach the forum's
        // origin, cookies or DOM even when it is opened in place.
        if ($upload->mime_type === 'application/pdf') {
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return new EmptyResponse(304, Arr::only($headers, ['ETag', 'Cache-Control']));
        }

        $stream = $disk->readStream($upload->path);

        if (! is_resource($stream)) {
            throw new RouteNotFoundException();
        }

        $body = Utils::streamFor($stream);
        $range = $this->range($request->getHeaderLine('Range'), $size);

        if ($range === false) {
            $body->close();

            return new EmptyResponse(416, ['Content-Range' => 'bytes */'.$size]);
        }

        if ($range !== null) {
            [$start, $end] = $range;

            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
            $headers['Content-Length'] = (string) ($end - $start + 1);

            return new Response(new LimitStream($body, $end - $start + 1, $start), 206, $headers);
        }

        $headers['Content-Length'] = (string) $size;

        return new Response($body, 200, $headers);
    }

    /**
     * The `Range` header as [start, end], null when absent or not something this
     * serves (only a single byte range is), false when unsatisfiable.
     *
     * @return array{0: int, 1: int}|null|false
     */
    protected function range(string $header, int $size): array|null|false
    {
        if ($header === '' || $size === 0 || ! preg_match('/^bytes=(\d*)-(\d*)$/', $header, $m)) {
            return null;
        }

        if ($m[1] === '' && $m[2] === '') {
            return null;
        }

        if ($m[1] === '') {
            // A suffix range: the last N bytes.
            $length = min((int) $m[2], $size);

            return [$size - $length, $size - 1];
        }

        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);

        if ($start >= $size || $start > $end) {
            return false;
        }

        return [$start, $end];
    }

    protected function disposition(Upload $upload): string
    {
        $type = (string) $upload->mime_type;
        $inline = false;

        foreach (self::INLINE as $prefix) {
            if (str_starts_with($type, $prefix)) {
                $inline = true;
                break;
            }
        }

        // The stored name has already been stripped of path components and
        // control characters; this narrows it further to what a quoted-string
        // can carry safely, with the full name alongside for browsers that read
        // the extended form.
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $upload->file_name) ?: 'file';

        return sprintf(
            "%s; filename=\"%s\"; filename*=UTF-8''%s",
            $inline ? 'inline' : 'attachment',
            trim($ascii, '_') ?: 'file',
            rawurlencode($upload->file_name)
        );
    }
}
