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
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Convites para um canal, de ponta a ponta.
 *
 * Ser adicionado a um canal virou uma pergunta. Estes testes fixam as três
 * respostas e o que cada uma deixa para trás: convidar cria um convite e uma
 * notificação, e nada mais; aceitar cria a associação, narra a chegada e tira
 * a notificação do sino; recusar avisa o dono e quem convidou, e deixa o canal
 * privado tão invisível quanto antes. Quem gerencia pode retirar o convite, e
 * um convite nunca contorna a permissão de uma categoria restrita.
 *
 * Em processos separados pela razão que ChannelAccessMatrixTest documenta: a
 * classe habilita o flarum-tags, e o registro estático de scopers sobrevive
 * entre boots dentro de um mesmo processo.
 */
#[RunTestsInSeparateProcesses]
class ChannelInviteTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const OWNER = 3;
    private const INVITER = 4;
    private const INVITEE = 2;
    private const BYSTANDER = 5;

    private const CH_PRIVATE = 1;
    private const CH_PUBLIC = 2;
    private const CH_RESTRICTED = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('flarum-tags', 'ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                $this->user(self::OWNER, 'owner'),
                $this->user(self::INVITER, 'inviter'),
                $this->user(self::BYSTANDER, 'bystander'),
            ],
            'groups' => [
                ['id' => 101, 'name_singular' => 'Chatmod', 'name_plural' => 'Chatmods'],
                ['id' => 102, 'name_singular' => 'Tagholder', 'name_plural' => 'Tagholders'],
            ],
            'group_user' => [
                ['user_id' => self::OWNER, 'group_id' => 101],
                ['user_id' => self::INVITER, 'group_id' => 101],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => 101, 'permission' => 'ramon-chat.moderate'],
                // The managers can see the restricted category; the invitee
                // cannot, which is what the last test is about.
                ['group_id' => 101, 'permission' => 'tag2.viewForum'],
                ['group_id' => 102, 'permission' => 'tag2.viewForum'],
            ],
            'tags' => [
                ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => 1],
            ],
            'chat_channels' => [
                $this->category(self::CH_PRIVATE, 'private-room', tagId: null, private: true),
                $this->category(self::CH_PUBLIC, 'open-room', tagId: null, private: false),
                $this->category(self::CH_RESTRICTED, 'staff-room', tagId: 2, private: false),
            ],
            'chat_channel_user' => [
                $this->membership(self::CH_PRIVATE, self::OWNER),
                $this->membership(self::CH_PRIVATE, self::INVITER),
                $this->membership(self::CH_PUBLIC, self::OWNER),
                $this->membership(self::CH_RESTRICTED, self::OWNER),
            ],
        ]);
    }

    public function test_inviting_creates_an_invite_and_a_notification_but_no_membership(): void
    {
        $response = $this->invite(self::CH_PRIVATE, self::INVITER, self::INVITEE);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $document = json_decode((string) $response->getBody(), true);

        $invitedIds = array_map(
            fn (array $ref) => (int) $ref['id'],
            $document['data']['relationships']['invitedUsers']['data'] ?? []
        );

        $this->assertSame([self::INVITEE], $invitedIds, 'the manager sees who is pending');

        $this->assertSame(1, $this->invites(self::CH_PRIVATE, self::INVITEE)->count());
        $this->assertFalse($this->isMember(self::CH_PRIVATE, self::INVITEE), 'nothing is joined until they say yes');

        $notification = $this->inviteNotification(self::INVITEE, self::CH_PRIVATE);

        $this->assertNotNull($notification);
        $this->assertSame(self::INVITER, (int) $notification->from_user_id);
        $this->assertSame(0, (int) $notification->is_deleted);

        $this->assertSame([], $this->systemKeysIn(self::CH_PRIVATE), 'the room hears the answer, not the question');
    }

    public function test_inviting_twice_is_a_no_op(): void
    {
        $this->invite(self::CH_PRIVATE, self::INVITER, self::INVITEE);
        $second = $this->invite(self::CH_PRIVATE, self::INVITER, self::INVITEE);

        $this->assertSame(200, $second->getStatusCode(), (string) $second->getBody());
        $this->assertSame(1, $this->invites(self::CH_PRIVATE, self::INVITEE)->count());
        $this->assertSame(
            1,
            $this->database()->table('notifications')
                ->where('user_id', self::INVITEE)
                ->where('type', 'chatChannelInvite')
                ->count()
        );
    }

    public function test_an_invitee_sees_the_private_channel_row_but_none_of_its_contents(): void
    {
        $this->database()->table('chat_messages')->insert($this->message(10, self::CH_PRIVATE));
        $this->database()->table('chat_channels')->where('id', self::CH_PRIVATE)->update(['last_message_id' => 10]);

        $before = $this->send(
            $this->request('GET', '/api/chat-channels/'.self::CH_PRIVATE, [
                'authenticatedAs' => self::INVITEE,
            ])
        );

        $this->assertSame(404, $before->getStatusCode(), 'nothing is visible before the invitation');

        $this->invite(self::CH_PRIVATE, self::INVITER, self::INVITEE);

        $show = $this->send(
            $this->request('GET', '/api/chat-channels/'.self::CH_PRIVATE, [
                'authenticatedAs' => self::INVITEE,
            ])->withQueryParams(['include' => 'lastMessage'])
        );

        $this->assertSame(200, $show->getStatusCode(), 'the row is what the invitation is about');

        $document = json_decode((string) $show->getBody(), true);
        $attributes = $document['data']['attributes'];

        $this->assertTrue($attributes['isInvited']);
        $this->assertTrue($attributes['canJoin'], 'accepting is joining');
        $this->assertFalse($attributes['canPostMessage']);
        $this->assertArrayNotHasKey('lastMessage', $document['data']['relationships'] ?? [], 'the last message is content');
        $this->assertSame([], $document['included'] ?? []);

        $messages = $this->send(
            $this->request('GET', '/api/chat-messages', [
                'authenticatedAs' => self::INVITEE,
            ])->withQueryParams(['filter' => ['channel' => self::CH_PRIVATE]])
        );

        $this->assertSame(200, $messages->getStatusCode());
        $this->assertSame([], json_decode((string) $messages->getBody(), true)['data'], 'and neither is the stream');

        $message = $this->send(
            $this->request('GET', '/api/chat-messages/10', [
                'authenticatedAs' => self::INVITEE,
            ])
        );

        $this->assertSame(404, $message->getStatusCode());

        // The notification can be listed only because the row is visible: core
        // hides notifications whose subject the reader cannot see.
        $bell = $this->send(
            $this->request('GET', '/api/notifications', [
                'authenticatedAs' => self::INVITEE,
            ])
        );

        $types = array_map(
            fn (array $row) => $row['attributes']['contentType'],
            json_decode((string) $bell->getBody(), true)['data']
        );

        $this->assertContains('chatChannelInvite', $types);
    }

    public function test_joining_a_private_channel_with_an_invitation_accepts_it(): void
    {
        $this->invite(self::CH_PRIVATE, self::INVITER, self::INVITEE);

        $join = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_PRIVATE.'/join', [
                'authenticatedAs' => self::INVITEE,
                'json'            => ['data' => ['attributes' => []]],
            ])
        );

        $this->assertSame(200, $join->getStatusCode(), (string) $join->getBody());
        $this->assertTrue($this->isMember(self::CH_PRIVATE, self::INVITEE));
        $this->assertSame(0, $this->invites(self::CH_PRIVATE, self::INVITEE)->count());
        $this->assertSame(['user_accepted_invite'], $this->systemKeysIn(self::CH_PRIVATE));
    }

    public function test_an_invitee_to_a_public_channel_sees_the_invitation_on_the_record(): void
    {
        $this->invite(self::CH_PUBLIC, self::OWNER, self::INVITEE);

        $show = $this->send(
            $this->request('GET', '/api/chat-channels/'.self::CH_PUBLIC, [
                'authenticatedAs' => self::INVITEE,
            ])
        );

        $this->assertSame(200, $show->getStatusCode());

        $attributes = json_decode((string) $show->getBody(), true)['data']['attributes'];

        $this->assertTrue($attributes['isInvited']);
        $this->assertSame(self::OWNER, $attributes['invitedById']);
        $this->assertSame('owner', $attributes['invitedByName']);
        $this->assertFalse($attributes['isFollowing']);
        $this->assertArrayNotHasKey(
            'invitedUsers',
            json_decode((string) $show->getBody(), true)['data']['relationships'] ?? [],
            'the pending list is for managers only'
        );
    }

    public function test_accepting_joins_announces_and_clears_the_notification(): void
    {
        $this->invite(self::CH_PRIVATE, self::INVITER, self::INVITEE);

        $accept = $this->send(
            $this->request('POST', '/api/chat/invites/'.self::CH_PRIVATE.'/accept', [
                'authenticatedAs' => self::INVITEE,
            ])
        );

        $this->assertSame(200, $accept->getStatusCode(), (string) $accept->getBody());

        $attributes = json_decode((string) $accept->getBody(), true)['data']['attributes'];

        // The record as the client needs it to draw the composer at once.
        $this->assertTrue($attributes['isFollowing']);
        $this->assertTrue($attributes['canPostMessage']);
        $this->assertFalse($attributes['isInvited']);

        $this->assertTrue($this->isMember(self::CH_PRIVATE, self::INVITEE));
        $this->assertSame(0, $this->invites(self::CH_PRIVATE, self::INVITEE)->count());

        $this->assertSame(['user_accepted_invite'], $this->systemKeysIn(self::CH_PRIVATE));
        $this->assertSame(
            ['actor' => 'inviter', 'username' => 'normal'],
            $this->systemDataIn(self::CH_PRIVATE)
        );

        $notification = $this->inviteNotification(self::INVITEE, self::CH_PRIVATE);

        $this->assertNotNull($notification);
        $this->assertSame(1, (int) $notification->is_deleted, 'an answered invitation leaves the bell');

        $this->assertSame(
            3,
            (int) $this->database()->table('chat_channels')->where('id', self::CH_PRIVATE)->value('user_count')
        );
    }

    public function test_joining_a_public_channel_consumes_a_pending_invitation(): void
    {
        $this->invite(self::CH_PUBLIC, self::OWNER, self::INVITEE);

        $join = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_PUBLIC.'/join', [
                'authenticatedAs' => self::INVITEE,
                'json'            => ['data' => ['attributes' => []]],
            ])
        );

        $this->assertSame(200, $join->getStatusCode(), (string) $join->getBody());

        $attributes = json_decode((string) $join->getBody(), true)['data']['attributes'];

        $this->assertTrue($attributes['canPostMessage'], 'the join answers with the fresh capability flags');
        $this->assertSame(0, $this->invites(self::CH_PUBLIC, self::INVITEE)->count());
        $this->assertSame(['user_accepted_invite'], $this->systemKeysIn(self::CH_PUBLIC));
    }

    public function test_declining_notifies_the_owner_and_the_inviter_and_keeps_the_channel_hidden(): void
    {
        $this->invite(self::CH_PRIVATE, self::INVITER, self::INVITEE);

        $decline = $this->send(
            $this->request('POST', '/api/chat/invites/'.self::CH_PRIVATE.'/decline', [
                'authenticatedAs' => self::INVITEE,
            ])
        );

        $this->assertSame(204, $decline->getStatusCode(), (string) $decline->getBody());

        $this->assertSame(0, $this->invites(self::CH_PRIVATE, self::INVITEE)->count());
        $this->assertFalse($this->isMember(self::CH_PRIVATE, self::INVITEE));
        $this->assertSame([], $this->systemKeysIn(self::CH_PRIVATE));

        $declined = $this->database()->table('notifications')
            ->where('type', 'chatChannelInviteDeclined')
            ->where('subject_id', self::CH_PRIVATE)
            ->where('from_user_id', self::INVITEE)
            ->where('is_deleted', 0)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([self::OWNER, self::INVITER], $declined, 'both the owner and whoever asked are told');

        $this->assertSame(
            1,
            (int) $this->inviteNotification(self::INVITEE, self::CH_PRIVATE)?->is_deleted
        );

        $show = $this->send(
            $this->request('GET', '/api/chat-channels/'.self::CH_PRIVATE, [
                'authenticatedAs' => self::INVITEE,
            ])
        );

        $this->assertSame(404, $show->getStatusCode());
    }

    public function test_declining_without_an_invitation_is_not_found(): void
    {
        $decline = $this->send(
            $this->request('POST', '/api/chat/invites/'.self::CH_PRIVATE.'/decline', [
                'authenticatedAs' => self::BYSTANDER,
            ])
        );

        $this->assertSame(404, $decline->getStatusCode());

        $accept = $this->send(
            $this->request('POST', '/api/chat/invites/'.self::CH_PRIVATE.'/accept', [
                'authenticatedAs' => self::BYSTANDER,
            ])
        );

        $this->assertSame(404, $accept->getStatusCode());
        $this->assertFalse($this->isMember(self::CH_PRIVATE, self::BYSTANDER));
    }

    public function test_a_manager_can_withdraw_an_invitation(): void
    {
        $this->invite(self::CH_PRIVATE, self::INVITER, self::INVITEE);

        $cancel = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_PRIVATE.'/invites/cancel', [
                'authenticatedAs' => self::OWNER,
                'json'            => ['data' => ['attributes' => ['userId' => self::INVITEE]]],
            ])
        );

        $this->assertSame(200, $cancel->getStatusCode(), (string) $cancel->getBody());

        $document = json_decode((string) $cancel->getBody(), true);

        $this->assertSame([], $document['data']['relationships']['invitedUsers']['data'] ?? ['missing']);
        $this->assertSame(0, $this->invites(self::CH_PRIVATE, self::INVITEE)->count());
        $this->assertSame(
            1,
            (int) $this->inviteNotification(self::INVITEE, self::CH_PRIVATE)?->is_deleted,
            'a withdrawn invitation leaves the bell too'
        );

        $accept = $this->send(
            $this->request('POST', '/api/chat/invites/'.self::CH_PRIVATE.'/accept', [
                'authenticatedAs' => self::INVITEE,
            ])
        );

        $this->assertSame(404, $accept->getStatusCode());
    }

    public function test_only_a_manager_may_invite_or_withdraw(): void
    {
        $invite = $this->invite(self::CH_PUBLIC, self::BYSTANDER, self::INVITEE);

        $this->assertSame(403, $invite->getStatusCode());
        $this->assertSame(0, $this->invites(self::CH_PUBLIC, self::INVITEE)->count());

        $this->invite(self::CH_PUBLIC, self::OWNER, self::INVITEE);

        $cancel = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_PUBLIC.'/invites/cancel', [
                'authenticatedAs' => self::BYSTANDER,
                'json'            => ['data' => ['attributes' => ['userId' => self::INVITEE]]],
            ])
        );

        $this->assertSame(403, $cancel->getStatusCode());
        $this->assertSame(1, $this->invites(self::CH_PUBLIC, self::INVITEE)->count());
    }

    public function test_an_invitation_does_not_open_a_restricted_category(): void
    {
        $this->invite(self::CH_RESTRICTED, self::OWNER, self::INVITEE);

        $this->assertSame(1, $this->invites(self::CH_RESTRICTED, self::INVITEE)->count());

        $accept = $this->send(
            $this->request('POST', '/api/chat/invites/'.self::CH_RESTRICTED.'/accept', [
                'authenticatedAs' => self::INVITEE,
            ])
        );

        $this->assertSame(403, $accept->getStatusCode());
        $this->assertFalse($this->isMember(self::CH_RESTRICTED, self::INVITEE));
        $this->assertSame(1, $this->invites(self::CH_RESTRICTED, self::INVITEE)->count(), 'a refused accept keeps the invite for later');
    }

    private function invite(int $channel, int $actor, int $user): \Psr\Http\Message\ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/chat-channels/'.$channel.'/members', [
                'authenticatedAs' => $actor,
                'json'            => ['data' => ['attributes' => ['userIds' => [$user]]]],
            ])
        );
    }

    private function invites(int $channel, int $user): \Illuminate\Database\Query\Builder
    {
        return $this->database()->table('chat_channel_invites')
            ->where('channel_id', $channel)
            ->where('user_id', $user);
    }

    private function isMember(int $channel, int $user): bool
    {
        return $this->database()->table('chat_channel_user')
            ->where('channel_id', $channel)
            ->where('user_id', $user)
            ->whereNull('left_at')
            ->exists();
    }

    private function inviteNotification(int $user, int $channel): ?object
    {
        return $this->database()->table('notifications')
            ->where('user_id', $user)
            ->where('type', 'chatChannelInvite')
            ->where('subject_id', $channel)
            ->orderByDesc('id')
            ->first();
    }

    /** @return string[] */
    private function systemKeysIn(int $channel): array
    {
        return $this->database()->table('chat_messages')
            ->where('channel_id', $channel)
            ->where('type', 'system')
            ->orderBy('id')
            ->pluck('system_key')
            ->all();
    }

    /** @return array<string, string> */
    private function systemDataIn(int $channel): array
    {
        $raw = $this->database()->table('chat_messages')
            ->where('channel_id', $channel)
            ->where('type', 'system')
            ->orderBy('id')
            ->value('system_data');

        $data = json_decode((string) $raw, true) ?: [];
        ksort($data);

        return $data;
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

    /** @return array<string, mixed> */
    private function category(int $id, string $slug, ?int $tagId, bool $private): array
    {
        return [
            'id'                          => $id,
            'type'                        => 'category',
            'name'                        => ucfirst(str_replace('-', ' ', $slug)),
            'slug'                        => $slug,
            'status'                      => 'open',
            'tag_id'                      => $tagId,
            'is_private'                  => $private ? 1 : 0,
            'post_permission'             => 'all',
            'creator_id'                  => self::OWNER,
            'threading_enabled'           => 0,
            'auto_join'                   => 0,
            'auto_join_on_reply'          => 0,
            'post_discussions'            => 0,
            'allow_channel_wide_mentions' => 1,
            'messages_count'              => 0,
            'user_count'                  => $id === self::CH_PRIVATE ? 2 : 1,
            'created_at'                  => Carbon::now()->toDateTimeString(),
            'updated_at'                  => Carbon::now()->toDateTimeString(),
        ];
    }

    private function membership(int $channelId, int $userId): array
    {
        return [
            'channel_id' => $channelId,
            'user_id'    => $userId,
            'joined_at'  => Carbon::now()->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    private function message(int $id, int $channelId): array
    {
        return [
            'id'         => $id,
            'channel_id' => $channelId,
            'user_id'    => self::OWNER,
            'type'       => 'text',
            'content'    => '<t><p>secret of channel '.$channelId.'</p></t>',
            'number'     => 1,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }
}
