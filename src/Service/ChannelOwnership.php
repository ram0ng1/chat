<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ramon\Chat\Channel;

/**
 * Who creates and runs channels: a forum-wide switch, then the permissions of
 * the "Members" section, then a per-channel role.
 *
 * In MODE_ADMIN only administrators create channels, whatever the permission
 * grid says, and neither the creator nor anyone they promoted holds anything
 * over a channel. In MODE_MEMBERS:
 *
 *  - `createChannel` opens a room;
 *  - `manageOwnChannels` makes the creator its owner — members, moderators,
 *    closing, archiving, deleting, removing messages, managing threads;
 *  - `editOwnChannels` lets the creator change its settings;
 *  - an owner may promote a member to *channel moderator*, who then adds and
 *    removes members, removes messages and manages threads there — but does
 *    not change the settings, archive or delete the room, or promote others.
 *
 * Owners and channel moderators are exempt from the channel's slow mode, the
 * way `bypassSlowMode` exempts globally. Chat moderators (`ramon-chat.moderate`)
 * keep every channel in both modes.
 */
class ChannelOwnership
{
    public const SETTING = 'ramon-chat.channel_ownership';

    public const MODE_ADMIN = 'admin';

    public const MODE_MEMBERS = 'members';

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function membersOwnChannels(): bool
    {
        return $this->settings->get(self::SETTING, self::MODE_ADMIN) === self::MODE_MEMBERS;
    }

    /**
     * Whether the actor owns this channel: members mode, their own category
     * channel, and `manageOwnChannels` granted.
     */
    public function manages(User $actor, Channel $channel): bool
    {
        return $this->owns($actor, $channel) && $actor->hasPermission('ramon-chat.manageOwnChannels');
    }

    /**
     * Whether the actor may change this channel's settings as its creator.
     * Owning implies it, the way forum-wide `moderate` implies `editChannel`.
     */
    public function edits(User $actor, Channel $channel): bool
    {
        if ($this->manages($actor, $channel)) {
            return true;
        }

        return $this->owns($actor, $channel) && $actor->hasPermission('ramon-chat.editOwnChannels');
    }

    /**
     * Whether the actor was promoted to moderator of this channel by its owner.
     *
     * Read only in members mode, and only from a live membership: a role left on
     * a row after the person left the channel means nothing until they are back.
     */
    public function moderatesChannel(User $actor, Channel $channel): bool
    {
        if (! $this->membersOwnChannels() || ! $channel->isCategory()) {
            return false;
        }

        $membership = $channel->membershipFor($actor);

        return $membership !== null && ! $membership->hasLeft() && $membership->isModerator();
    }

    /**
     * Forum-wide `ramon-chat.moderate`, ownership, or the channel's own
     * moderator role: what it takes to act on other people's messages and on
     * the member list here. Every policy that used to ask for the forum-wide
     * right alone reads this instead, so the three cannot drift apart.
     *
     * `hasPermission` rather than `can`: it reads the actor's groups directly and
     * short-circuits for administrators, without re-entering the Gate.
     */
    public function moderates(User $actor, Channel $channel): bool
    {
        return $actor->hasPermission('ramon-chat.moderate')
            || $this->manages($actor, $channel)
            || $this->moderatesChannel($actor, $channel);
    }

    /**
     * Forum-wide `ramon-chat.moderate` or ownership: the irreversible acts —
     * archiving, deleting — and choosing who moderates. A channel moderator's
     * trust stops short of these.
     */
    public function controls(User $actor, Channel $channel): bool
    {
        return $actor->hasPermission('ramon-chat.moderate') || $this->manages($actor, $channel);
    }

    /**
     * Whether the channel's pace does not apply to the actor: the people running
     * the room are the ones answering in it, and a cooldown on them slows the
     * room down rather than calming it.
     */
    public function exemptFromSlowMode(User $actor, Channel $channel): bool
    {
        return $this->manages($actor, $channel) || $this->moderatesChannel($actor, $channel);
    }

    protected function owns(User $actor, Channel $channel): bool
    {
        return $this->membersOwnChannels() && $channel->isOwnedBy($actor);
    }
}
