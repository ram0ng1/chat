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
use Ramon\Chat\Message;
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
     * An upload is visible when its message is. Unattached uploads are visible
     * only to their uploader, which is what keeps a pending composer attachment
     * private until the message is sent.
     */
    public function scope(Builder $query, OriginalContext $context): void
    {
        $actor = $context->getActor();

        $query->where(function (Builder $query) use ($actor) {
            $query->whereIn('chat_uploads.message_id', function ($sub) use ($actor) {
                Message::query()
                    ->setQuery($sub->from('chat_messages'))
                    ->whereVisibleTo($actor)
                    ->select('chat_messages.id');
            });

            if ($actor->exists) {
                $query->orWhere(function (Builder $query) use ($actor) {
                    $query
                        ->whereNull('chat_uploads.message_id')
                        ->where('chat_uploads.user_id', $actor->id);
                });
            }
        });
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

            Schema\DateTime::make('createdAt'),

            Schema\Relationship\ToOne::make('user')->type('users')->includable(),
        ];
    }
}
