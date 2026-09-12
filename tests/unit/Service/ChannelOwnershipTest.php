<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Mockery;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Service\ChannelOwnership;
use Ramon\Chat\Tests\unit\QueryTestCase;

/**
 * O papel de moderador de canal e a isenção do modo lento.
 *
 * Um moderador de canal age sobre membros e mensagens do canal em que foi
 * promovido, mas não controla o canal (arquivar, excluir, promover) — isso fica
 * com o dono e com quem tem o `moderate` global. Nada disso vale no modo
 * "administradores".
 */
class ChannelOwnershipTest extends QueryTestCase
{
    protected const OWNER_ID = 7;

    protected const MOD_ID = 8;

    protected function ownership(string $mode = ChannelOwnership::MODE_MEMBERS): ChannelOwnership
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturnUsing(
            fn (string $key, $default = null) => $key === ChannelOwnership::SETTING ? $mode : $default
        );

        return new ChannelOwnership($settings);
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

        return $user;
    }

    /**
     * A channel whose membership row for `$memberId` is already loaded, so the
     * lookup answers from the relation instead of the database.
     */
    protected function channel(int $memberId, bool $moderator, ?string $leftAt = null): Channel
    {
        $channel = new Channel();
        $channel->setRawAttributes([
            'id'         => 1,
            'type'       => Channel::TYPE_CATEGORY,
            'status'     => Channel::STATUS_OPEN,
            'creator_id' => self::OWNER_ID,
        ], true);

        $membership = new ChannelUser();
        $membership->setRawAttributes([
            'channel_id'   => 1,
            'user_id'      => $memberId,
            'is_moderator' => $moderator,
            'left_at'      => $leftAt,
        ], true);

        $channel->setRelation('actorMembership', $membership);

        return $channel;
    }

    public function test_a_promoted_member_moderates_the_channel_but_does_not_control_it(): void
    {
        $ownership = $this->ownership();
        $moderator = $this->actor(self::MOD_ID);
        $channel = $this->channel(self::MOD_ID, moderator: true);

        $this->assertTrue($ownership->moderatesChannel($moderator, $channel));
        $this->assertTrue($ownership->moderates($moderator, $channel));
        $this->assertTrue($ownership->exemptFromSlowMode($moderator, $channel));

        $this->assertFalse($ownership->controls($moderator, $channel));
        $this->assertFalse($ownership->edits($moderator, $channel));
    }

    public function test_an_ordinary_member_is_neither(): void
    {
        $ownership = $this->ownership();
        $member = $this->actor(self::MOD_ID);
        $channel = $this->channel(self::MOD_ID, moderator: false);

        $this->assertFalse($ownership->moderates($member, $channel));
        $this->assertFalse($ownership->exemptFromSlowMode($member, $channel));
    }

    /** The role means nothing once the person has left the channel. */
    public function test_the_role_does_not_survive_leaving(): void
    {
        $ownership = $this->ownership();
        $former = $this->actor(self::MOD_ID);
        $channel = $this->channel(self::MOD_ID, moderator: true, leftAt: '2026-01-01 00:00:00');

        $this->assertFalse($ownership->moderatesChannel($former, $channel));
    }

    public function test_the_role_is_off_in_admin_mode(): void
    {
        $ownership = $this->ownership(ChannelOwnership::MODE_ADMIN);
        $moderator = $this->actor(self::MOD_ID);
        $channel = $this->channel(self::MOD_ID, moderator: true);

        $this->assertFalse($ownership->moderatesChannel($moderator, $channel));
        $this->assertFalse($ownership->exemptFromSlowMode($moderator, $channel));
    }

    public function test_the_owner_controls_and_is_exempt_from_slow_mode(): void
    {
        $ownership = $this->ownership();
        $owner = $this->actor(self::OWNER_ID, ['ramon-chat.manageOwnChannels' => true]);
        $channel = $this->channel(self::OWNER_ID, moderator: false);

        $this->assertTrue($ownership->controls($owner, $channel));
        $this->assertTrue($ownership->exemptFromSlowMode($owner, $channel));
    }
}
