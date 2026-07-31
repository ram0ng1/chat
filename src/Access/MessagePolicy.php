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

    /**
     * Removing the row itself, tombstone and all.
     *
     * Only on a message that is already deleted. Two steps, deliberately: a
     * tombstone is reversible and this is not, so the destructive act is never one
     * click away from a live conversation. It also matches what it is for —
     * clearing tombstones that have piled up, not moderating in the first place.
     *
     * A thread's root is refused. `chat_threads.original_message_id` is a plain
     * column with no foreign key, so purging the root would leave the thread
     * pointing at a row that no longer exists; and destroying a branch of the
     * conversation is not what "tidy up this tombstone" should mean.
     *
     * `ramon-chat.moderate` rather than a permission of its own: it already means
     * "may act on other people's messages here", and this is the strongest form of
     * an act that permission already grants.
     */
    public function forceDelete(User $actor, Message $message): ?bool
    {
        if (! $message->isDeleted()) {
            return false;
        }

        if ($message->isThreadRoot()) {
            return false;
        }

        return $actor->hasPermission('ramon-chat.moderate') ? true : false;
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

        if ($channel === null) {
            return false;
        }

        // Deliberately *not* `postMessage`. Deferring to it made an announcement
        // channel completely inert: everyone can read it, only moderators may
        // write, and so nobody else could even acknowledge what was posted. The
        // point of such a channel is that people read it, and a reaction is the
        // cheapest way to show they did.
        //
        // The other conditions posting checks still apply, because a reaction is
        // still activity:
        //  - the channel has to accept it (a closed or archived one does not);
        //  - the actor has to be able to see the message;
        //  - the actor has to be in the channel, for the same reason posting
        //    requires it — a link is not membership.
        if (! $channel->acceptsMessages()) {
            return false;
        }

        if (! $this->view($actor, $message)) {
            return false;
        }

        if ($channel->membershipFor($actor) === null) {
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

    /**
     * Reporting a message to the moderators.
     *
     * Refused on your own message — there is nobody to tell — and on a message
     * already deleted, whose report would arrive about something no longer there.
     * System messages have no author to hold responsible.
     *
     * Moderators are not excluded: someone who may act on this channel may still
     * find a message in a channel they do not watch, and filing it is how it
     * reaches whoever does.
     */
    public function flag(User $actor, Message $message): ?bool
    {
        if (! $actor->exists) {
            return false;
        }

        if ($message->isDeleted() || $message->isSystem() || $message->user_id === null) {
            return false;
        }

        if ($actor->id === $message->user_id) {
            return false;
        }

        if (! $this->view($actor, $message)) {
            return false;
        }

        return $actor->hasPermission('ramon-chat.flagMessage') ? true : false;
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
