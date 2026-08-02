<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\Extension\ExtensionManager;
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
    public function __construct(protected ExtensionManager $extensions)
    {
    }

    public function __invoke(User $actor, Builder $query): void
    {
        // The global gate. Without it there is nothing to scope.
        //
        // One right, not two. A separate `ramon-chat.view` briefly let guests read
        // public channels; it has been withdrawn, so participating and reading are
        // the same permission again.
        if (! $actor->can('useChat')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereNull('chat_channels.deleted_at');

        // Not `class_exists(Tag::class)`: the class is autoloaded whenever the
        // package sits in vendor/, which says nothing about whether its migrations
        // ran. A forum that pulled flarum/tags in as a dependency and never enabled
        // it has the class and no `tags` table, and the subquery below then fails
        // the whole channel listing with a 500.
        $tagsAvailable = $this->extensions->isEnabled('flarum-tags');

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
            $query->orWhere(function (Builder $query) use ($actor) {
                $query
                    ->where('chat_channels.type', 'category')
                    ->whereNull('chat_channels.tag_id');

                $this->restrictPrivate($query, $actor);
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

                    // Composes with the tag check rather than replacing it: a
                    // private channel on a restricted category is restricted twice.
                    $this->restrictPrivate($query, $actor);
                });
            }
        });
    }

    /**
     * Narrows a category-channel branch so private channels reach members only.
     *
     * A public channel passes straight through. A private one requires a live
     * membership row, which is what makes it invitation-only: it cannot be found in
     * Browse, cannot be joined from there, and its name is never shown to anyone who
     * was not added.
     *
     * Two grants lift that, and both are exemptions from the membership test rather
     * than from the channel's own rules — someone holding either still cannot post
     * without joining first (see ChannelPolicy::postMessage):
     *
     *  - `accessPrivateChannels`, the dedicated right. It exists so that opening
     *    private channels to a group — a staff group, a team, a paid tier — costs
     *    one permission rather than the whole of `moderate`.
     *  - `moderate`, which keeps it because a private channel a moderator cannot
     *    see is one they cannot moderate. The same trade Flarum makes for private
     *    discussions.
     */
    protected function restrictPrivate(Builder $query, User $actor): void
    {
        if ($actor->hasPermission('ramon-chat.accessPrivateChannels')
            || $actor->hasPermission('ramon-chat.moderate')) {
            return;
        }

        $query->where(function (Builder $query) use ($actor) {
            $query->where('chat_channels.is_private', false);

            // A guest has no membership to find, so the branch is simply skipped
            // and every private channel stays hidden from them.
            if (! $actor->exists) {
                return;
            }

            $query->orWhereExists(function ($sub) use ($actor) {
                $sub->selectRaw(1)
                    ->from('chat_channel_user')
                    ->whereColumn('chat_channel_user.channel_id', 'chat_channels.id')
                    ->where('chat_channel_user.user_id', $actor->id)
                    ->whereNull('chat_channel_user.left_at');
            });
        });
    }
}
