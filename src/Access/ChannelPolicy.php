<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Ramon\Chat\Channel;

/**
 * Return semantics matter here. Flarum's Gate only applies its admin fallback
 * when *no* policy reached a decision, so returning `false` denies admins too.
 * We therefore return:
 *
 *   - `true`  to grant,
 *   - `false` only for structural invariants that must hold for everyone
 *             (posting into an archived channel, editing a system message),
 *   - `null`  when the actor merely lacks a permission, letting the Gate fall
 *             through to its `isAdmin() || hasPermission()` default.
 */
class ChannelPolicy extends AbstractPolicy
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function view(User $actor, Channel $channel): ?bool
    {
        return Channel::whereVisibleTo($actor)->whereKey($channel->id)->exists() ?: null;
    }

    /**
     * Whether the actor may post into the channel. Visibility alone is not
     * enough: closed and archived channels stay readable but frozen.
     */
    public function postMessage(User $actor, Channel $channel): ?bool
    {
        // The chat needs an account. Guests do not reach this in practice — the
        // `/chat/*` routes 404 for them and the read endpoints are authenticated —
        // but a policy that answers correctly on its own is worth more than one
        // that relies on every caller having checked first.
        if (! $actor->exists) {
            return false;
        }

        // Structural: nobody posts into a frozen channel, admins included. An
        // admin who wants to post reopens the channel first.
        if (! $channel->acceptsMessages()) {
            return false;
        }

        if (! $this->view($actor, $channel)) {
            return null;
        }

        // An announcement channel: everyone reads, moderators write. Administrators
        // pass because `hasPermission` grants them everything, so there is no
        // separate admin branch to keep in step with this one.
        if ($channel->restrictsPostingToModerators()
            && ! $actor->hasPermission('ramon-chat.moderate')) {
            return false;
        }

        // Membership is required to post in *any* channel, not only a direct one.
        //
        // Reading and writing are deliberately different rights here. A public
        // channel stays readable to anyone who can see its category — that is what
        // makes it public — but writing into a room you are not in is not something
        // a link should grant. Somebody removed from a channel who still holds the
        // URL could otherwise keep talking in it, which is the whole point of having
        // removed them.
        //
        // Joining is one click for a public channel, and the composer is replaced by
        // that button when this returns false, so the cost to a legitimate visitor
        // is a single deliberate act. For a private channel the join is refused
        // outright, so removal is final until somebody adds them back.
        return $channel->membershipFor($actor) !== null ? true : false;
    }

    public function join(User $actor, Channel $channel): ?bool
    {
        // A guest reading a public channel has nothing to join: membership is a row
        // keyed to a user id. The endpoint is authenticated so nothing could come of
        // it either way, but the policy is also what the client draws from, and
        // answering `true` here put a Join button in front of someone who cannot.
        if (! $actor->exists) {
            return false;
        }

        // Structural: direct channels are joined by invitation, never self-served.
        if ($channel->isDirect() || ! $channel->isOpen()) {
            return false;
        }

        // A private channel is invitation-only for ordinary members: the visibility
        // scope hides it from non-members, and someone who was never invited has no
        // business letting themselves in.
        //
        // Moderators are the exception, and deliberately so — the request is that a
        // moderator who left a channel can get back in to moderate it. They can
        // already read every private channel; being unable to *enter* one only means
        // moderation has to happen from outside the room.
        if ($channel->isPrivate()
            && $channel->membershipFor($actor) === null
            && ! $actor->hasPermission('ramon-chat.moderate')) {
            return false;
        }

        return $this->view($actor, $channel);
    }

    /**
     * Joining without appearing to anyone else.
     *
     * Moderators only. A hidden member is absent from the participant list and the
     * member count, so this is the ability to be in a room unannounced — which is a
     * moderation power, not a preference.
     */
    public function joinHidden(User $actor, Channel $channel): ?bool
    {
        if (! $actor->hasPermission('ramon-chat.moderate')) {
            return false;
        }

        return $this->join($actor, $channel);
    }

    /**
     * Editing a channel's name, description, emoji, bound tag and threading.
     *
     * Three independent grants, checked in order of specificity:
     *  - the creator of a direct group chat manages their own conversation;
     *  - `editChannel` is the dedicated right, for a group that should curate
     *    channels without being able to create or moderate them;
     *  - `moderate` implies it, since a chat moderator manages channels anyway.
     */
    public function edit(User $actor, Channel $channel): ?bool
    {
        // A direct channel has no settings worth editing: no name of its own (it is
        // labelled from the participant list), no category, no threading, no
        // auto-join. Granting `edit` to its creator handed them the whole
        // category-channel form — including the bound tag, which decides who can
        // see a channel. Only a chat moderator has any business there.
        //
        // Renaming a group DM is a narrower right and belongs in `manageMembers`
        // alongside the participant list, not here.
        if ($channel->isDirect()) {
            return $actor->hasPermission('ramon-chat.moderate') ? true : false;
        }

        if ($actor->hasPermission('ramon-chat.editChannel')) {
            return true;
        }

        return $actor->hasPermission('ramon-chat.moderate') ? true : null;
    }

    public function close(User $actor, Channel $channel): ?bool
    {
        if ($channel->isDirect()) {
            return false;
        }

        return $actor->can('ramon-chat.moderate') ? true : null;
    }

    /**
     * Archiving copies the transcript into a discussion, so it is only offered
     * once the channel is closed — otherwise the transcript would be a moving
     * target while messages keep arriving.
     */
    public function archive(User $actor, Channel $channel): ?bool
    {
        if (! (bool) $this->settings->get('ramon-chat.allow_archiving_channels', true)) {
            return false;
        }

        if ($channel->isDirect() || ! $channel->isClosed()) {
            return false;
        }

        return $actor->can('ramon-chat.moderate') ? true : null;
    }

    public function delete(User $actor, Channel $channel): ?bool
    {
        if ($channel->isDirect()) {
            // Direct history belongs to its participants; only an admin may
            // destroy it outright.
            return $actor->isAdmin() ? true : false;
        }

        return $actor->can('ramon-chat.moderate') ? true : null;
    }

    public function manageMembers(User $actor, Channel $channel): ?bool
    {
        if ($channel->isDirect()) {
            if ($channel->creator_id === $actor->id && $channel->membershipFor($actor) !== null) {
                return true;
            }
        }

        return $actor->can('ramon-chat.moderate') ? true : null;
    }

    /**
     * @see \Ramon\Chat\MessageMention::TYPE_HERE
     * @see \Ramon\Chat\MessageMention::TYPE_ALL
     */
    public function mentionChannelWide(User $actor, Channel $channel): ?bool
    {
        // Structural: the channel has opted out of @here/@all entirely.
        if (! $channel->allow_channel_wide_mentions) {
            return false;
        }

        if (! $actor->can('postMessage', $channel)) {
            return false;
        }

        return $actor->can('ramon-chat.mentionChannelWide') ? true : null;
    }

    public function viewMembers(User $actor, Channel $channel): ?bool
    {
        return $this->view($actor, $channel);
    }

    public function manageWebhooks(User $actor, Channel $channel): ?bool
    {
        return $actor->isAdmin() ? true : null;
    }
}
