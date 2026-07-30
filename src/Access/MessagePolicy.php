<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Ramon\Chat\Message;

/**
 * @see ChannelPolicy for why these methods return `?bool` rather than `bool`.
 */
class MessagePolicy extends AbstractPolicy
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function view(User $actor, Message $message): ?bool
    {
        return Message::whereVisibleTo($actor)->whereKey($message->id)->exists() ?: null;
    }

    /**
     * Only the author rewrites their own words. Moderators may delete, but never
     * edit, another user's message — silently altering attributed speech is a
     * different power from removing it, and Discourse draws the same line.
     */
    public function edit(User $actor, Message $message): ?bool
    {
        if ($message->isSystem() || $message->isDeleted() || $message->user_id === null) {
            return false;
        }

        if ($actor->id !== $message->user_id) {
            return false;
        }

        if (! $this->view($actor, $message)) {
            return false;
        }

        $channel = $message->channel;

        if ($channel === null || ! $channel->acceptsMessages()) {
            return false;
        }

        return $this->withinEditWindow($message);
    }

    public function delete(User $actor, Message $message): ?bool
    {
        if ($message->isDeleted()) {
            return false;
        }

        if ($actor->can('ramon-chat.moderate')) {
            return true;
        }

        if ($message->isSystem() || $message->user_id === null) {
            return false;
        }

        if ($actor->id === $message->user_id && $this->view($actor, $message)) {
            return true;
        }

        return null;
    }

    public function restore(User $actor, Message $message): ?bool
    {
        if (! $message->isDeleted()) {
            return false;
        }

        return $actor->can('ramon-chat.moderate') ? true : null;
    }

    public function react(User $actor, Message $message): ?bool
    {
        if ($message->isDeleted()) {
            return false;
        }

        $channel = $message->channel;

        if ($channel === null || ! $actor->can('postMessage', $channel)) {
            return false;
        }

        return $actor->can('ramon-chat.react') ? true : null;
    }

    public function bookmark(User $actor, Message $message): ?bool
    {
        if (! $actor->exists) {
            return false;
        }

        return $this->view($actor, $message);
    }

    public function reply(User $actor, Message $message): ?bool
    {
        if ($message->isDeleted()) {
            return false;
        }

        $channel = $message->channel;

        return $channel !== null && $actor->can('postMessage', $channel) ? true : false;
    }

    /**
     * Starting a thread off a reply.
     *
     * Three things have to hold, and they are deliberately separate concerns:
     * threading is enabled on the channel (an admin decision per channel), the
     * actor may post there at all, and the actor holds `ramon-chat.createThread`.
     *
     * The last is its own right because branching a conversation is a structural
     * act, not just another message: a thread reshapes how everyone else reads the
     * channel. A community may want everyone replying but only a subset splitting
     * the conversation up. Replying *inside* an existing thread stays a plain
     * `postMessage`, so this gates only who may open a new one.
     */
    public function createThread(User $actor, Message $message): ?bool
    {
        $channel = $message->channel;

        if ($channel === null || ! $channel->threading_enabled || $message->isDeleted()) {
            return false;
        }

        // Threads are one level deep. A message that already belongs to one cannot
        // be branched again: that includes a thread's own root, which has its
        // thread already and shows an indicator rather than a branch button.
        if ($message->thread_id !== null) {
            return false;
        }

        if (! $actor->hasPermission('ramon-chat.createThread')) {
            return false;
        }

        return $actor->can('postMessage', $channel) ? true : false;
    }

    public function move(User $actor, Message $message): ?bool
    {
        return $actor->can('ramon-chat.moderate') ? true : null;
    }

    /**
     * Pinning is editorial, not moderation: it decides what everyone in the channel
     * sees first. Held behind its own permission (administrators by default) and
     * refused on messages there is no point pinning.
     */
    public function pin(User $actor, Message $message): ?bool
    {
        if ($message->isDeleted() || $message->isSystem()) {
            return false;
        }

        // Thread replies are pinnable too. They are not in the channel window — the
        // channel filter keeps only thread roots — but the pinned list asks for
        // `includeThreadReplies`, so a pin made inside a thread is still reachable
        // from the channel's pinned panel. An answer worth pinning is often in a
        // thread precisely because that is where the discussion happened.
        return $actor->hasPermission('ramon-chat.pinMessage') ? true : false;
    }

    public function viewRevisions(User $actor, Message $message): ?bool
    {
        return $this->view($actor, $message);
    }

    /**
     * An unset or zero window means edits never expire.
     */
    protected function withinEditWindow(Message $message): bool
    {
        $minutes = (int) $this->settings->get('ramon-chat.message_edit_window_minutes', 0);

        if ($minutes <= 0) {
            return true;
        }

        return $message->created_at !== null
            && $message->created_at->greaterThan(Carbon::now()->subMinutes($minutes));
    }
}
