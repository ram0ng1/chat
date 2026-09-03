<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\Upload;
use Tobyz\JsonApiServer\Context as OriginalContext;

/**
 * Read-only resource so attachments can be included alongside messages.
 * Creation happens through Api\Controller\UploadController, which handles the
 * multipart body that JSON:API cannot express.
 *
 * @extends AbstractDatabaseResource<Upload>
 */
class UploadResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'chat-uploads';
    }

    public function model(): string
    {
        return Upload::class;
    }

    /**
     * The rule lives in Access\ScopeUploadVisibility, shared with the controller
     * that streams private files: an upload is visible when its message is, and
     * a pending one only to its uploader.
     */
    public function scope(Builder $query, OriginalContext $context): void
    {
        // @phpstan-ignore method.notFound (Flarum model scope)
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make()->authenticated(),

            // Only a pending, own upload may be discarded; removing one already
            // attached to a message would silently mutate history.
            //
            // The two-argument closure is correct here: Delete is model-scoped, so
            // isVisible() passes (model, context). Create has no model yet and gets
            // the context alone — see ChannelResource.
            Endpoint\Delete::make()
                ->authenticated()
                ->visible(fn (Upload $upload, Context $context) => $upload->message_id === null
                    && $upload->user_id === $context->getActor()->id),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('fileName'),
            Schema\Str::make('mimeType')->nullable(),
            Schema\Integer::make('size'),
            Schema\Integer::make('width')->nullable(),
            Schema\Integer::make('height')->nullable(),
            Schema\Integer::make('messageId')->nullable(),

            Schema\Str::make('url')
                ->get(fn (Upload $u) => $u->url()),

            Schema\Boolean::make('isImage')
                ->get(fn (Upload $u) => $u->isImage()),

            // Whether the file is served through the permission check rather
            // than straight off the web server. Informational: the URL already
            // points at the right place either way.
            Schema\Boolean::make('isPrivate'),

            Schema\DateTime::make('createdAt'),

            Schema\Relationship\ToOne::make('user')->type('users')->includable(),
        ];
    }
}
