<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
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
        protected Factory $filesystem,
        protected Translator $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('ramon-chat.use');
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

        // Random basename: the client filename is kept only as a display label,
        // never as a path component, so traversal and collisions are impossible.
        $path = sprintf('%s/%s.%s', Carbon::now()->format('Y/m'), Str::random(28), $extension);

        $this->filesystem->disk('chat')->put($path, $file->getStream()->getContents());

        $width = null;
        $height = null;

        if (str_starts_with($mime, 'image/') && is_string($tmp)) {
            $size = @getimagesize($tmp);

            if ($size !== false) {
                [$width, $height] = $size;
            }
        }

        $upload = new Upload();
        $upload->user_id = $actor->id;
        $upload->path = $path;
        $upload->file_name = $this->safeFileName($file->getClientFilename() ?? 'file.'.$extension);
        $upload->mime_type = $mime;
        $upload->size = (int) $file->getSize();
        $upload->width = $width;
        $upload->height = $height;
        $upload->save();

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
                    'url'      => $upload->url(),
                    'isImage'  => $upload->isImage(),
                ],
            ],
        ], 201);
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
