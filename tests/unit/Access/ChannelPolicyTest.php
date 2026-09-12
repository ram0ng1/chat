<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Access;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Mockery;
use Ramon\Chat\Access\ChannelPolicy;
use Ramon\Chat\Access\VisibilityCache;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Service\ChannelOwnership;
use Ramon\Chat\Tests\unit\QueryTestCase;

/**
 * O que o criador de um canal pode fazer com ele: o modo de
 * `ramon-chat.channel_ownership` primeiro, depois as permissões da seção
 * "Canais dos membros".
 *
 * No modo "administradores" o criador não tem nada além do que qualquer membro
 * tem, seja qual for a grade. No modo "membros", `manageOwnChannels` faz dele o
 * responsável pelo canal que criou — e só por esse — e `editOwnChannels` dá só
 * as configurações. Fora disso a policy se abstém (`null`), para que o fallback
 * do Gate continue decidindo por administradores e detentores do `moderate`.
 */
class ChannelPolicyTest extends QueryTestCase
{
    protected const OWNER_ID = 7;

    protected const MANAGE = ['ramon-chat.manageOwnChannels' => true];

    protected const EDIT = ['ramon-chat.editOwnChannels' => true];

    protected function policy(string $mode = ChannelOwnership::MODE_MEMBERS): ChannelPolicy
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturnUsing(
            fn (string $key, $default = null) => $key === ChannelOwnership::SETTING ? $mode : true
        );

        return new ChannelPolicy($settings, new VisibilityCache(), new ChannelOwnership($settings));
    }

    /**
     * A partial mock rather than a bare model: the ownership rules look up the
     * actor's membership to answer the channel-moderator role, and there is no
     * database here to look it up in. Nobody is a member unless a test says so.
     *
     * @param  array<int, ChannelUser>  $memberships  loaded rows, keyed by user id
     */
    protected function channel(array $attributes = [], array $memberships = []): Channel
    {
        $channel = Mockery::mock(Channel::class)->makePartial();
        $channel->setRawAttributes(array_merge([
            'id'         => 1,
            'type'       => Channel::TYPE_CATEGORY,
            'status'     => Channel::STATUS_OPEN,
            'creator_id' => self::OWNER_ID,
        ], $attributes), true);

        $channel->shouldReceive('membershipFor')->andReturnUsing(
            fn (?User $user) => $user ? ($memberships[(int) $user->id] ?? null) : null
        );

        return $channel;
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    protected function actor(int $id, array $permissions = []): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['id' => $id], true);
        $user->exists = true;

        $user->shouldReceive('hasPermission')->andReturnUsing(
            fn (string $permission) => $permissions[$permission] ?? false
        );

        $user->shouldReceive('can')->andReturnUsing(
            fn (string $ability) => $permissions[$ability] ?? false
        );

        $user->shouldReceive('isAdmin')->andReturn(false);

        return $user;
    }

    protected function owner(array $permissions = []): User
    {
        return $this->actor(self::OWNER_ID, $permissions);
    }

    protected function stranger(array $permissions = []): User
    {
        return $this->actor(self::OWNER_ID + 1, $permissions);
    }

    // ── administrators mode: the grid does not count ───────────────────────────

    public function test_in_admin_mode_the_creator_gets_no_decision_whatever_the_grid_says(): void
    {
        $policy = $this->policy(ChannelOwnership::MODE_ADMIN);
        $owner = $this->owner(self::MANAGE + self::EDIT);
        $channel = $this->channel();

        $this->assertNull($policy->edit($owner, $channel));
        $this->assertNull($policy->manageMembers($owner, $channel));
        $this->assertNull($policy->close($owner, $channel));
        $this->assertNull($policy->delete($owner, $channel));
    }

    // ── members mode: the permissions decide ───────────────────────────────────

    public function test_the_creator_without_the_permissions_gets_no_decision(): void
    {
        $this->assertNull($this->policy()->edit($this->owner(), $this->channel()));
        $this->assertNull($this->policy()->manageMembers($this->owner(), $this->channel()));
    }

    public function test_managing_grants_members_state_and_settings(): void
    {
        $owner = $this->owner(self::MANAGE);

        $this->assertTrue($this->policy()->manageMembers($owner, $this->channel()));
        $this->assertTrue($this->policy()->close($owner, $this->channel()));
        $this->assertTrue($this->policy()->delete($owner, $this->channel()));
        $this->assertTrue($this->policy()->edit($owner, $this->channel()));
    }

    public function test_editing_grants_settings_only(): void
    {
        $owner = $this->owner(self::EDIT);

        $this->assertTrue($this->policy()->edit($owner, $this->channel()));
        $this->assertNull($this->policy()->manageMembers($owner, $this->channel()));
        $this->assertNull($this->policy()->close($owner, $this->channel()));
        $this->assertNull($this->policy()->delete($owner, $this->channel()));
    }

    /** Archiving still waits for the channel to be closed first. */
    public function test_the_creator_archives_only_a_closed_channel(): void
    {
        $owner = $this->owner(self::MANAGE);

        $this->assertFalse($this->policy()->archive($owner, $this->channel()));
        $this->assertTrue(
            $this->policy()->archive($owner, $this->channel(['status' => Channel::STATUS_CLOSED]))
        );
    }

    public function test_the_permissions_do_not_reach_someone_elses_channel(): void
    {
        $stranger = $this->stranger(self::MANAGE + self::EDIT);
        $channel = $this->channel();

        $this->assertNull($this->policy()->edit($stranger, $channel));
        $this->assertNull($this->policy()->manageMembers($stranger, $channel));
        $this->assertNull($this->policy()->close($stranger, $channel));
        $this->assertNull($this->policy()->delete($stranger, $channel));
    }

    public function test_the_permissions_do_not_reach_a_channel_with_no_creator(): void
    {
        $this->assertNull(
            $this->policy()->edit($this->owner(self::MANAGE), $this->channel(['creator_id' => null]))
        );
    }

    /** A direct channel's creator gets no settings form and no state actions. */
    public function test_the_permissions_do_not_reach_a_direct_channel(): void
    {
        $owner = $this->owner(self::MANAGE + self::EDIT);
        $direct = $this->channel(['type' => Channel::TYPE_DIRECT]);

        $this->assertFalse($this->policy()->edit($owner, $direct));
        $this->assertFalse($this->policy()->close($owner, $direct));
        $this->assertFalse($this->policy()->delete($owner, $direct));
    }

    /** Being unannounced in a room is a moderation power, never an ownership one. */
    public function test_managing_does_not_grant_hidden_join(): void
    {
        $this->assertFalse($this->policy()->joinHidden($this->owner(self::MANAGE), $this->channel()));
    }

    // ── channel moderators: people and messages, not the room itself ───────────

    /** A channel where the stranger is a member holding the moderator role. */
    protected function channelModeratedByStranger(): Channel
    {
        $membership = new ChannelUser();
        $membership->setRawAttributes([
            'channel_id'   => 1,
            'user_id'      => self::OWNER_ID + 1,
            'is_moderator' => true,
        ], true);

        return $this->channel([], [self::OWNER_ID + 1 => $membership]);
    }

    public function test_a_channel_moderator_manages_members_but_not_the_channel(): void
    {
        $moderator = $this->stranger();
        $channel = $this->channelModeratedByStranger();

        $this->assertTrue($this->policy()->manageMembers($moderator, $channel));
        $this->assertNull($this->policy()->edit($moderator, $channel));
        $this->assertNull($this->policy()->delete($moderator, $channel));
        $this->assertNull($this->policy()->manageModerators($moderator, $channel));
    }

    public function test_only_the_owner_and_chat_moderators_promote(): void
    {
        $this->assertTrue($this->policy()->manageModerators($this->owner(self::MANAGE), $this->channel()));
        $this->assertTrue($this->policy()->manageModerators($this->stranger(['ramon-chat.moderate' => true]), $this->channel()));
        $this->assertNull($this->policy()->manageModerators($this->owner(self::EDIT), $this->channel()));
        $this->assertFalse(
            $this->policy(ChannelOwnership::MODE_ADMIN)->manageModerators($this->owner(self::MANAGE), $this->channel())
        );
    }

    // ── forum-wide rights are untouched in either mode ─────────────────────────

    public function test_forum_wide_moderate_reaches_any_channel_in_either_mode(): void
    {
        $moderator = $this->stranger(['ramon-chat.moderate' => true]);

        foreach ([ChannelOwnership::MODE_ADMIN, ChannelOwnership::MODE_MEMBERS] as $mode) {
            $this->assertTrue($this->policy($mode)->manageMembers($moderator, $this->channel()));
            $this->assertTrue($this->policy($mode)->edit($moderator, $this->channel()));
        }
    }
}
