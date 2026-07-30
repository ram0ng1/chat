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
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Channel;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * Sets or clears a channel's picture.
 *
 * Not built on core's UploadImageController, which asserts *admin*: editing a
 * channel is governed by the `edit` policy, so a moderator who may rename a channel
 * may also give it a picture. The parts worth borrowing are borrowed instead — the
 * validator, and the decode-and-re-encode that means the stored file is one this
 * server produced rather than one an uploader handed us.
 *
 * POST sets, DELETE clears; one controller because the two share the channel
 * lookup, the permission check and the delete-the-old-file step, and splitting them
 * would mean keeping three of those in sync twice.
 */
class ChannelImageController implements RequestHandlerInterface
{
    protected Filesystem $uploadDir;

    /** The multipart field name, and the prefix of the stored file. */
    protected const FIELD = 'image';

    public function __construct(
        protected ImageManager $imageManager,
        protected Container $container,
        Factory $filesystemFactory
    ) {
        $this->uploadDir = $filesystemFactory->disk('flarum-assets');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $id = (int) Arr::get($request->getQueryParams(), 'id');

        // whereVisibleTo before the policy: a channel the actor cannot see must
        // answer the same way whether or not it exists.
        $channel = Channel::whereVisibleTo($actor)->find($id);

        if ($channel === null) {
            throw new ForbiddenException();
        }

        if (! $actor->can('edit', $channel)) {
            throw new ForbiddenException();
        }

        $previous = $channel->image_path;

        if ($request->getMethod() === 'DELETE') {
            $channel->image_path = null;
            $channel->save();

            $this->discard($previous);

            return new JsonResponse(['data' => ['imageUrl' => null]]);
        }

        $file = Arr::get($request->getUploadedFiles(), self::FIELD);

        if ($file === null) {
            throw new ValidationException([self::FIELD => 'No file was uploaded.']);
        }

        // Size and real mime type, read from the file's own bytes rather than the
        // Content-Type the browser claimed.
        $this->container->make(BotAvatarValidator::class)->assertImageValid(self::FIELD, $file);

        $image = $this->imageManager->read($file->getStream()->getMetadata('uri'))
            ->cover(128, 128);

        $animated = $image->isAnimated();
        $encoded = $animated ? $image->toGif() : $image->toWebp();

        $name = 'ramon-chat-channel-'.$channel->id.'-'
            .Str::lower(Str::random(8)).'.'.($animated ? 'gif' : 'webp');

        $this->uploadDir->put($name, (string) $encoded);

        $channel->image_path = $name;
        $channel->save();

        // Only after the new one is safely stored — a failed write must not leave
        // the channel with neither.
        $this->discard($previous);

        return new JsonResponse(['data' => [
            'id'       => (int) $channel->id,
            'imageUrl' => $channel->imageUrl(),
        ]]);
    }

    protected function discard(?string $path): void
    {
        if ($path && $this->uploadDir->exists($path)) {
            $this->uploadDir->delete($path);
        }
    }
}
