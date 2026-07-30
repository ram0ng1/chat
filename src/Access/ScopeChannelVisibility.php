<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts channel queries to what the actor may see.
 *
 * Category channels inherit their bound tag's `viewForum` permission, which is
 * what makes "a channel on the #lounge category is automatically TL3+ only"
 * work without a parallel permission system. Direct channels are visible only
 * to their participants.
 */
class ScopeChannelVisibility
{
    public function __invoke(User $actor, Builder $query): void
    {
        // The global gate. Without it there is nothing to scope.
        if (! $actor->hasPermissionLike('ramon-chat.use')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereNull('chat_channels.deleted_at');

        $tagsAvailable = class_exists(Tag::class);

        $query->where(function (Builder $query) use ($actor, $tagsAvailable) {
            // Direct channels: participants only, and only while they have not
            // left. Guests can never be participants, so this branch is a no-op
            // for them.
            if ($actor->exists) {
                $query->orWhere(function (Builder $query) use ($actor) {
                    $query
                        ->where('chat_channels.type', 'direct')
                        ->whereExists(function ($sub) use ($actor) {
                            $sub->selectRaw(1)
                                ->from('chat_channel_user')
                                ->whereColumn('chat_channel_user.channel_id', 'chat_channels.id')
                                ->where('chat_channel_user.user_id', $actor->id)
                                ->whereNull('chat_channel_user.left_at');
                        });
                });
            }

            // Forum-wide category channels (no bound tag).
            $query->orWhere(function (Builder $query) {
                $query
                    ->where('chat_channels.type', 'category')
                    ->whereNull('chat_channels.tag_id');
            });

            // Tag-bound category channels. When flarum/tags is unavailable these
            // stay hidden rather than becoming public — failing closed is the
            // only safe direction for an inherited-permission model.
            if ($tagsAvailable) {
                $query->orWhere(function (Builder $query) use ($actor) {
                    $query
                        ->where('chat_channels.type', 'category')
                        ->whereNotNull('chat_channels.tag_id')
                        ->whereIn('chat_channels.tag_id', function ($sub) use ($actor) {
                            Tag::query()
                                ->setQuery($sub->from('tags'))
                                ->whereHasPermission($actor, 'viewForum')
                                ->select('tags.id');
                        });
                });
            }
        });
    }
}
