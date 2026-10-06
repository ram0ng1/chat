<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Service\UploadPrivacy;
use Ramon\Chat\Storage\StorageFailed;
use Ramon\Chat\Storage\UploadStorage;
use Ramon\Chat\Upload;

/**
 * Accepts a composer attachment and returns the created upload.
 *
 * The upload is created *unattached* (`message_id` null) and bound to a message
 * later by MessageDispatcher. That is what lets the composer show a pending
 * attachment before the message is sent; the retention command sweeps uploads
 * whose composer session never completed.
 */
class UploadController implements RequestHandlerInterface
{
    /**
     * Allowed mime → extension. An allow-list rather than a deny-list: anything
     * not named here is rejected, so a new dangerous type cannot slip through by
     * omission.
     */
    protected const ALLOWED = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'image/avif'      => 'avif',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'text/plain'      => 'txt',
        'text/csv'        => 'csv',
        'audio/mpeg'      => 'mp3',
        'audio/ogg'       => 'ogg',
        'audio/wav'       => 'wav',
        'audio/mp4'       => 'm4a',
        'video/mp4'       => 'mp4',
        'video/webm'      => 'webm',
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected UploadStorage $storage,
        protected Translator $translator,
        protected LoggerInterface $log
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('useChat');
        $actor->assertCan('ramon-chat.upload');

        if (! (bool) $this->settings->get('ramon-chat.allow_uploads', true)) {
            throw new ValidationException([
                'file' => $this->translator->trans('ramon-chat.api.uploads_disabled'),
            ]);
        }

        /** @var UploadedFileInterface|null $file */
        $file = $request->getUploadedFiles()['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException([
                'file' => $this->translator->trans('ramon-chat.api.upload_missing'),
            ]);
        }

        // The composer says where the file is headed, so one meant for a private
        // channel never touches the public disk. A hint and not the gate: the
        // dispatcher checks again when the message is sent, against the channel
        // it is actually sent to. The channel must be visible to the uploader —
        // a private one they cannot see answers "not found" like everywhere else.
        $channelId = (int) Arr::get($request->getParsedBody(), 'channelId', 0);
        $private = false;

        if ($channelId > 0) {
            /** @var Channel|null $channel */
            $channel = Channel::whereVisibleTo($actor)->find($channelId);

            if ($channel === null) {
                throw new ValidationException([
                    'channelId' => $this->translator->trans('ramon-chat.api.channel_not_found'),
                ]);
            }

            $private = UploadPrivacy::requiredFor($channel);
        }

        $maxSize = (int) $this->settings->get('ramon-chat.max_upload_size', 10485760);

        if ($maxSize > 0 && (int) $file->getSize() > $maxSize) {
            throw new ValidationException([
                'file' => $this->translator->trans('ramon-chat.api.upload_too_large'),
            ]);
        }

        // Sniff the real mime from the temp file. The client-supplied
        // Content-Type is attacker-controlled and must never decide storage.
        $tmp = $file->getStream()->getMetadata('uri');
        $mime = is_string($tmp) ? (mime_content_type($tmp) ?: '') : '';

        if (! isset(self::ALLOWED[$mime])) {
            throw new ValidationException([
                'file' => $this->translator->trans('ramon-chat.api.upload_unsupported', ['type' => $mime]),
            ]);
        }

        $extension = self::ALLOWED[$mime];

        $width = null;
        $height = null;

        // Read from the temp file before the store consumes the stream.
        if (str_starts_with($mime, 'image/') && is_string($tmp)) {
            $size = @getimagesize($tmp);

            if ($size !== false) {
                [$width, $height] = $size;
            }
        }

        $upload = new Upload();
        $upload->user_id = $actor->id;
        $upload->is_private = $private;
        $upload->mime_type = $mime;
        $upload->size = (int) $file->getSize();
        $upload->file_name = $this->safeFileName($file->getClientFilename() ?? 'file.'.$extension);
        $upload->width = $width;
        $upload->height = $height;

        // The store names the path. A private file always lands on the chat's
        // private disk; a public one goes through fof/upload when the admin chose
        // it. Either way the basename is random and the client filename is kept
        // only as a display label, never as a path component.
        $this->put($upload, $file, $extension);

        try {
            $upload->save();
        } catch (\Throwable $e) {
            // Without a row nothing would ever find these bytes again: no prune,
            // no delete, and on a bucket no way to tell them from anything else.
            $this->storage->delete($upload);

            throw $e;
        }

        return new JsonResponse([
            'data' => [
                'type'       => 'chat-uploads',
                'id'         => (string) $upload->id,
                'attributes' => [
                    'fileName' => $upload->file_name,
                    'mimeType' => $upload->mime_type,
                    'size'     => $upload->size,
                    'width'    => $upload->width,
                    'height'   => $upload->height,
                    'url'       => $upload->url(),
                    'isImage'   => $upload->isImage(),
                    'isPrivate' => $upload->is_private,
                ],
            ],
        ], 201);
    }

    /**
     * Writes the bytes through the chosen store, or refuses the upload.
     *
     * No fallback to the local disk when fof/upload fails: an admin who sent
     * public files to a bucket did so for a reason (bandwidth, disk, a CDN), and
     * quietly filling the webroot instead would hide the outage until the disk
     * was full. The member sees a plain "could not be stored"; the reason, which
     * may name a bucket, goes to the log.
     */
    protected function put(Upload $upload, UploadedFileInterface $file, string $extension): void
    {
        $stream = $file->getStream();
        $stream->rewind();
        $resource = StreamWrapper::getResource($stream);

        try {
            $this->storage->forNew((bool) $upload->is_private)->put($upload, $resource, $extension);
        } catch (StorageFailed $e) {
            $this->log->error('[ramon-chat] could not store an upload', [
                'user'  => $upload->user_id,
                'error' => $e->getMessage(),
            ]);

            throw new ValidationException([
                'file' => $this->translator->trans('ramon-chat.api.upload_storage_failed'),
            ]);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * Strips directory components and control characters from the display name.
     * This value is echoed back to clients, so it must not carry markup either.
     */
    protected function safeFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F<>"\']/u', '', $name) ?? 'file';

        return mb_substr(trim($name) ?: 'file', 0, 200);
    }
}
