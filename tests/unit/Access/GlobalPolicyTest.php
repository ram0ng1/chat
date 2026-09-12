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
use PHPUnit\Framework\TestCase;
use Ramon\Chat\Access\GlobalPolicy;
use Ramon\Chat\Service\ChannelOwnership;

/**
 * Criar canais obedece ao modo de `ramon-chat.channel_ownership` antes da
 * permissão: no modo "administradores" a grade não conta; no modo "membros" a
 * permissão `createChannel` volta a decidir.
 */
class GlobalPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    protected function policy(string $mode): GlobalPolicy
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturnUsing(
            fn (string $key, $default = null) => $key === ChannelOwnership::SETTING ? $mode : $default
        );

        return new GlobalPolicy(new ChannelOwnership($settings));
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    protected function actor(array $permissions, bool $admin = false): User
    {
        $user = Mockery::mock(User::class)->makePartial();

        $user->shouldReceive('isAdmin')->andReturn($admin);
        $user->shouldReceive('hasPermission')->andReturnUsing(
            fn (string $permission) => $admin || ($permissions[$permission] ?? false)
        );

        return $user;
    }

    public function test_in_admin_mode_only_administrators_create_channels(): void
    {
        $policy = $this->policy(ChannelOwnership::MODE_ADMIN);

        $this->assertTrue($policy->createChannel($this->actor([], admin: true)));
        $this->assertFalse($policy->createChannel(
            $this->actor(['ramon-chat.use' => true, 'ramon-chat.createChannel' => true])
        ));
    }

    public function test_in_members_mode_the_permission_decides(): void
    {
        $policy = $this->policy(ChannelOwnership::MODE_MEMBERS);

        $this->assertTrue($policy->createChannel(
            $this->actor(['ramon-chat.use' => true, 'ramon-chat.createChannel' => true])
        ));
        $this->assertFalse($policy->createChannel($this->actor(['ramon-chat.use' => true])));
    }

    /** The base chat gate still applies: a group granted only `createChannel` gets nothing. */
    public function test_creating_a_channel_still_requires_using_the_chat(): void
    {
        $policy = $this->policy(ChannelOwnership::MODE_MEMBERS);

        $this->assertFalse($policy->createChannel($this->actor(['ramon-chat.createChannel' => true])));
    }
}
