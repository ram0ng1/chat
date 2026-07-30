<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Api\Controller\UploadImageController;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Uploads the chat bot's avatar.
 *
 * Built on core's UploadImageController rather than reading the upload directly.
 * That base already asserts admin, writes to the `flarum-assets` disk, deletes the
 * file it replaces and records the path in settings — but the reason that matters
 * most is `makeImage`: the image is decoded and *re-encoded*, so whatever was in
 * the uploaded bytes does not survive. A file that is a valid PNG with PHP appended
 * to it — the classic way an image upload becomes remote code execution — comes out
 * of here as a plain webp.
 *
 * @see DeleteBotAvatarController for the other half.
 */
class UploadBotAvatarController extends UploadImageController
{
    protected string $filePathSettingKey = 'ramon-chat.bot_avatar_path';
    protected string $filenamePrefix = 'ramon-chat-bot';
    protected ?string $validator = BotAvatarValidator::class;

    private string $resolvedExtension = 'webp';

    protected function makeImage(UploadedFileInterface $file): EncodedImageInterface
    {
        // Square and small: it is drawn at 34px in the stream and never larger, so
        // storing the original would cost bandwidth on every message the bot posts
        // for no visible gain.
        $image = $this->imageManager->read($file->getStream()->getMetadata('uri'))
            ->cover(200, 200);

        // An animated avatar stays animated — re-encoding it to webp would silently
        // freeze a GIF the admin deliberately chose.
        if ($image->isAnimated()) {
            $this->resolvedExtension = 'gif';

            return $image->toGif();
        }

        $this->resolvedExtension = 'webp';

        return $image->toWebp();
    }

    protected function fileExtension(ServerRequestInterface $request, UploadedFileInterface $file): string
    {
        return $this->resolvedExtension;
    }
}
