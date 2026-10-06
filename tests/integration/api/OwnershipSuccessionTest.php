<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Psr\Http\Message\ResponseInterface;
use Ramon\Chat\Event\ChannelOwnershipTransferred;
use Ramon\Chat\Tests\integration\FlushesCache;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * When the owner leaves, the longest-serving moderator takes over.
 *
 * Longest in the role (`moderator_since`), then by channel join date, and only
 * among those who can own channels. With none, the channel is left without an
 * owner, and an administrator can still hand it over. The departed owner's
 * pending transfer is cancelled. In "administrators" mode nothing changes.
 */
class OwnershipSuccessionTest extends TestCase
{
    use FlushesCache;
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const ADMIN = 1;
    private const PLAIN = 2;
    private const OWNER = 3;
    private const SENIOR = 4;
    private const JUNIOR = 5;
    private const POWERLESS = 6;

    private const CHANNEL = 1;

    /** @var array<int, object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');
    }

    /**
     * @param  array<int, array{0: bool, 1: string|null, 2: string}>  $roles  user => [moderator, moderator_since, joined_at]
     */
    private function seed(array $roles, string $mode = 'members'): void
    {
        $this->setting('ramon-chat.channel_ownership', $mode);

        $now = Carbon::now()->toDateTimeString();

        $memberships = [];

        foreach ($roles as $userId => [$moderator, $since, $joined]) {
            $memberships[] = [
                'channel_id'      => self::CHANNEL,
                'user_id'         => $userId,
                'is_moderator'    => $moderator ? 1 : 0,
                'moderator_since' => $since,
                'joined_at'       => $joined,
                'created_at'      => $now,
            ];
        }

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                $this->user(self::OWNER, 'owner'),
                $this->user(self::SENIOR, 'senior'),
                $this->user(self::JUNIOR, 'junior'),
                $this->user(self::POWERLESS, 'powerless'),
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Owner', 'name_plural' => 'Owners'],
            ],
            'group_user' => [
                ['user_id' => self::OWNER, 'group_id' => 100],
                ['user_id' => self::SENIOR, 'group_id' => 100],
                ['user_id' => self::JUNIOR, 'group_id' => 100],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => 100, 'permission' => 'ramon-chat.createChannel'],
                ['group_id' => 100, 'permission' => 'ramon-chat.manageOwnChannels'],
            ],
            'chat_channels' => [
                [
                    'id'                          => self::CHANNEL,
                    'type'                        => 'category',
                    'name'                        => 'Room',
                    'slug'                        => 'room',
                    'status'                      => 'open',
                    'is_private'                  => 0,
                    'post_permission'             => 'all',
                    'creator_id'                  => self::OWNER,
                    'threading_enabled'           => 0,
                    'auto_join'                   => 0,
                    'auto_join_on_reply'          => 0,
                    'post_discussions'            => 0,
                    'allow_channel_wide_mentions' => 1,
                    'messages_count'              => 0,
                    'user_count'                  => count($roles),
                    'created_at'                  => $now,
                    'updated_at'                  => $now,
                ],
            ],
            'chat_channel_user' => $memberships,
        ]);

        $this->flushCache();

        $this->app();

        // A fresh install grants `manageOwnChannels` to every member (migration
        // 2026_09_11_000000), which would make the fixtures' plain members able to
        // own channels. These tests need them not to, so the grant goes once the
        // app has seeded the database.
        $this->database()->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.manageOwnChannels')
            ->delete();

        $this->app()->getContainer()->make('events')->listen(ChannelOwnershipTransferred::class, function ($event) {
            $this->dispatched[] = $event;
        });
    }

    private function standardRoles(): array
    {
        return [
            self::OWNER     => [false, null, '2026-01-01 00:00:00'],
            self::PLAIN     => [false, null, '2026-01-02 00:00:00'],
            self::POWERLESS => [true, '2026-02-01 00:00:00', '2026-01-03 00:00:00'],
            self::JUNIOR    => [true, '2026-03-01 00:00:00', '2026-01-04 00:00:00'],
            self::SENIOR    => [true, '2026-02-15 00:00:00', '2026-01-05 00:00:00'],
        ];
    }

    public function test_the_oldest_moderator_who_may_own_channels_inherits(): void
    {
        $this->seed($this->standardRoles());

        $this->assertSame(204, $this->leave(self::OWNER)->getStatusCode());

        $this->assertSame(self::SENIOR, $this->creatorId(), 'appointed before JUNIOR; POWERLESS, older still, cannot own channels');
        $this->assertFalse($this->isModerator(self::SENIOR), 'an owner is not also a moderator');
        $this->assertTrue($this->isModerator(self::JUNIOR));
        $this->assertTrue($this->isModerator(self::POWERLESS));

        $notification = $this->database()->table('notifications')->where('type', 'chatOwnershipInherited')->get();
        $this->assertCount(1, $notification);
        $this->assertSame(self::SENIOR, (int) $notification[0]->user_id);
        $this->assertSame(self::OWNER, (int) $notification[0]->from_user_id);
        $this->assertSame(['channelId' => self::CHANNEL, 'userId' => self::SENIOR], json_decode((string) $notification[0]->data, true));

        $this->assertCount(1, $this->dispatched, 'the same event an accepted transfer dispatches');
        $this->assertTrue($this->dispatched[0]->inherited);
        $this->assertSame(self::SENIOR, (int) $this->dispatched[0]->newOwner->id);

        $show = $this->send($this->request('GET', '/api/chat-channels/'.self::CHANNEL, ['authenticatedAs' => self::SENIOR]));
        $attributes = json_decode((string) $show->getBody(), true)['data']['attributes'];
        $this->assertTrue($attributes['canManageModerators'], 'the heir holds every owner right');
        $this->assertTrue($attributes['canTransferOwnership']);
    }

    public function test_without_appointment_dates_the_earliest_to_join_inherits(): void
    {
        $this->seed([
            self::OWNER  => [false, null, '2026-01-01 00:00:00'],
            self::JUNIOR => [true, null, '2026-01-02 00:00:00'],
            self::SENIOR => [true, null, '2026-01-03 00:00:00'],
        ]);

        $this->leave(self::OWNER);

        $this->assertSame(self::JUNIOR, $this->creatorId());
    }

    public function test_with_no_moderator_the_channel_is_left_without_an_owner(): void
    {
        $this->seed([
            self::OWNER  => [false, null, '2026-01-01 00:00:00'],
            self::SENIOR => [false, null, '2026-01-02 00:00:00'],
            self::PLAIN  => [false, null, '2026-01-03 00:00:00'],
        ]);

        $this->leave(self::OWNER);

        $this->assertSame(0, $this->creatorId(), 'nobody is handed a room they were never trusted with');
        $this->assertCount(0, $this->dispatched);
        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'chatOwnershipInherited')->count());

        $show = $this->send($this->request('GET', '/api/chat-channels/'.self::CHANNEL, ['authenticatedAs' => self::ADMIN]));
        $this->assertTrue(json_decode((string) $show->getBody(), true)['data']['attributes']['canTransferOwnership'], 'an administrator can still hand it to someone');
    }

    public function test_the_leaving_owners_pending_transfer_is_cancelled(): void
    {
        $this->seed($this->standardRoles());

        $start = $this->send($this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/transfer', [
            'authenticatedAs' => self::OWNER,
            'json'            => ['data' => ['attributes' => ['userId' => self::JUNIOR]]],
        ]));
        $this->assertSame(200, $start->getStatusCode(), (string) $start->getBody());

        $this->leave(self::OWNER);

        $this->assertSame(0, $this->database()->table('chat_channel_transfers')->count());
        $this->assertSame(self::SENIOR, $this->creatorId());
    }

    public function test_removal_by_an_administrator_also_hands_the_channel_on(): void
    {
        $this->seed($this->standardRoles());

        $response = $this->send($this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/members/remove', [
            'authenticatedAs' => self::ADMIN,
            'json'            => ['data' => ['attributes' => ['userId' => self::OWNER]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame(self::SENIOR, $this->creatorId());
    }

    public function test_a_moderator_leaving_changes_nothing(): void
    {
        $this->seed($this->standardRoles());

        $this->leave(self::SENIOR);

        $this->assertSame(self::OWNER, $this->creatorId());
        $this->assertCount(0, $this->dispatched);
    }

    public function test_a_deleted_account_hands_its_channels_on(): void
    {
        $this->seed($this->standardRoles());

        $response = $this->send($this->request('DELETE', '/api/users/'.self::OWNER, ['authenticatedAs' => self::ADMIN]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame(self::SENIOR, $this->creatorId());
    }

    public function test_in_administrator_mode_nothing_changes_hands(): void
    {
        $this->seed($this->standardRoles(), 'admin');

        $this->leave(self::OWNER);

        $this->assertSame(self::OWNER, $this->creatorId());
        $this->assertCount(0, $this->dispatched);
    }

    private function leave(int $user): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/leave', [
            'authenticatedAs' => $user,
        ]));
    }

    private function creatorId(): int
    {
        return (int) $this->database()->table('chat_channels')->where('id', self::CHANNEL)->value('creator_id');
    }

    private function isModerator(int $user): bool
    {
        return (bool) $this->database()->table('chat_channel_user')
            ->where('channel_id', self::CHANNEL)
            ->where('user_id', $user)
            ->value('is_moderator');
    }

    private function user(int $id, string $username): array
    {
        return [
            'id'                 => $id,
            'username'           => $username,
            'password'           => self::PASSWORD_HASH,
            'email'              => $username.'@machine.local',
            'is_email_confirmed' => 1,
        ];
    }
}
