<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\Message;

/**
 * An upload is visible when its message is. Unattached uploads are visible only
 * to their uploader, which is what keeps a pending composer attachment private
 * until the message is sent.
 *
 * Registered as a model scope rather than written into UploadResource alone,
 * because two doors now open onto the same files: the JSON:API resource and
 * ServeUploadController, which streams a private channel's attachments. One rule
 * in one place is what keeps the two from drifting apart.
 */
class ScopeUploadVisibility
{
    public function __invoke(User $actor, Builder $query): void
    {
        $query->where(function (Builder $query) use ($actor) {
            $query->whereIn('chat_uploads.message_id', function ($sub) use ($actor) {
                Message::query()
                    ->setQuery($sub->from('chat_messages'))
                    // @phpstan-ignore method.notFound (Flarum model scope)
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
}
