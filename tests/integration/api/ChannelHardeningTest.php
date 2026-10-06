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
use Flarum\Post\Event\Posted;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;
use Ramon\Chat\Api\Resource\ChannelResource;
use Ramon\Chat\Tests\integration\FlushesCache;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * The channel-level findings of the October 2026 audit, one test per hole.
 *
 * Each of these was reproduced against a live forum running in "members" mode,
 * where an ordinary account may open and run a channel. That is the setup here:
 * the owner holds `createChannel` and `manageOwnChannels` and nothing else, and
 * every assertion is about what that account, or one like it, could do that it
 * should not have been able to.
 *
 * In separate processes for the reason ChannelAccessMatrixTest documents: tags
 * is enabled, and the static scoper registry survives boots within a process.
 */
#[RunTestsInSeparateProcesses]
class ChannelHardeningTest extends TestCase
{
    use FlushesCache;
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const MEMBER = 2;
    private const OWNER = 3;
    private const INSPECTOR = 4;
    private const MOD = 5;
    private const POSTING_MOD = 6;

    private const CH_OPEN = 1;
    private const CH_PRIVATE = 2;
    private const CH_LOUNGE = 3;
    private const CH_STAFF = 4;

    private const D_OPEN = 10;
    private const D_RESTRICTED = 11;
    private const D_PRIVATE = 12;
    private const D_HIDDEN = 13;

    /** The first id of the crowd of users the invitation budget is spent on. */
    private const CROWD = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('flarum-tags', 'ramon-chat');

        $this->setting('ramon-chat.channel_ownership', 'members');

        $users = [
            $this->normalUser(),
            $this->user(self::OWNER, 'owner'),
            $this->user(self::INSPECTOR, 'inspector'),
            $this->user(self::MOD, 'moderator'),
            $this->user(self::POSTING_MOD, 'postingmod'),
        ];

        for ($i = 0; $i <= ChannelResource::INVITES_PER_HOUR; $i++) {
            $users[] = $this->user(self::CROWD + $i, 'crowd'.$i);
        }

        $this->prepareDatabase([
            'users' => $users,
            'groups' => [
                ['id' => 100, 'name_singular' => 'Owner', 'name_plural' => 'Owners'],
                ['id' => 101, 'name_singular' => 'Chatmod', 'name_plural' => 'Chatmods'],
                ['id' => 102, 'name_singular' => 'Inspector', 'name_plural' => 'Inspectors'],
                ['id' => 103, 'name_singular' => 'Poster', 'name_plural' => 'Posters'],
            ],
            'group_user' => [
                ['user_id' => self::OWNER, 'group_id' => 100],
                ['user_id' => self::MOD, 'group_id' => 101],
                ['user_id' => self::INSPECTOR, 'group_id' => 102],
                ['user_id' => self::POSTING_MOD, 'group_id' => 101],
                ['user_id' => self::POSTING_MOD, 'group_id' => 103],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => 100, 'permission' => 'ramon-chat.createChannel'],
                ['group_id' => 100, 'permission' => 'ramon-chat.manageOwnChannels'],
                ['group_id' => 101, 'permission' => 'ramon-chat.moderate'],
                ['group_id' => 101, 'permission' => 'tag2.viewForum'],
                ['group_id' => 102, 'permission' => 'ramon-chat.inspectChannels'],
                ['group_id' => 103, 'permission' => 'startDiscussion'],
                ['group_id' => 103, 'permission' => 'discussion.reply'],
            ],
            'tags' => [
                ['id' => 1, 'name' => 'Lounge', 'slug' => 'lounge', 'position' => 0, 'is_restricted' => 0],
                ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => 1],
            ],
            'chat_channels' => [
                $this->channel(self::CH_OPEN, 'open-room', tagId: null, private: false),
                $this->channel(self::CH_PRIVATE, 'private-room', tagId: null, private: true),
                $this->channel(self::CH_LOUNGE, 'lounge-room', tagId: 1, private: false, announces: true),
                $this->channel(self::CH_STAFF, 'staff-room', tagId: 2, private: false),
            ],
            'chat_channel_user' => [
                $this->membership(self::CH_OPEN, self::OWNER),
                $this->membership(self::CH_OPEN, self::INSPECTOR, hidden: true),
                $this->membership(self::CH_PRIVATE, self::OWNER),
                $this->membership(self::CH_LOUNGE, self::OWNER),
                $this->membership(self::CH_STAFF, self::MOD),
            ],
            'discussions' => [
                $this->discussion(self::D_OPEN, 'Open thread'),
                $this->discussion(self::D_RESTRICTED, 'Staff and lounge thread'),
                $this->discussion(self::D_PRIVATE, 'Private conversation', private: true),
                $this->discussion(self::D_HIDDEN, 'Hidden thread', hidden: true),
            ],
            'discussion_tag' => [
                ['discussion_id' => self::D_OPEN, 'tag_id' => 1],
                ['discussion_id' => self::D_RESTRICTED, 'tag_id' => 1],
                ['discussion_id' => self::D_RESTRICTED, 'tag_id' => 2],
                ['discussion_id' => self::D_PRIVATE, 'tag_id' => 1],
                ['discussion_id' => self::D_HIDDEN, 'tag_id' => 1],
            ],
            'posts' => [
                $this->post(self::D_OPEN),
                $this->post(self::D_RESTRICTED),
                $this->post(self::D_PRIVATE),
                $this->post(self::D_HIDDEN),
            ],
        ]);

        $this->flushCache();

        // The installer grants members both of these. Withdrawn so the plain chat
        // moderator is someone who could not have posted the transcript by hand.
        $this->database()->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->whereIn('permission', ['startDiscussion', 'discussion.reply'])
            ->delete();
    }

    /*
     * F1: a new discussion is announced only where the channel's weakest reader
     * could already open it.
     */
    public function test_only_discussions_the_whole_audience_can_see_are_announced(): void
    {
        foreach ([self::D_RESTRICTED, self::D_PRIVATE, self::D_HIDDEN] as $discussion) {
            $this->announce($discussion);
        }

        $this->assertSame([], $this->announcedIn(self::CH_LOUNGE), 'restricted, private and hidden stay out');

        $this->announce(self::D_OPEN);

        $this->assertSame([self::D_OPEN], $this->announcedIn(self::CH_LOUNGE));
    }

    /*
     * F2: archiving publishes, so it is held to publishing's rules.
     */
    public function test_a_private_channel_is_not_archived_by_a_moderator(): void
    {
        $response = $this->archive(self::CH_PRIVATE, self::POSTING_MOD, ['title' => 'Leak']);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0, $this->database()->table('discussions')->where('title', 'Leak')->count());
        $this->assertNull($this->database()->table('chat_channels')->where('id', self::CH_PRIVATE)->value('archived_discussion_id'));
    }

    public function test_an_administrator_may_archive_a_private_channel(): void
    {
        $response = $this->archive(self::CH_PRIVATE, 1, ['title' => 'Kept by an admin']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_archiving_into_a_new_discussion_needs_start_discussion(): void
    {
        $refused = $this->archive(self::CH_OPEN, self::MOD, ['title' => 'Not allowed']);

        $this->assertSame(403, $refused->getStatusCode(), (string) $refused->getBody());
        $this->assertSame(0, $this->database()->table('discussions')->where('title', 'Not allowed')->count());

        $allowed = $this->archive(self::CH_OPEN, self::POSTING_MOD, ['title' => 'Allowed']);

        $this->assertSame(200, $allowed->getStatusCode(), (string) $allowed->getBody());
    }

    public function test_archiving_into_a_discussion_needs_reply(): void
    {
        $response = $this->archive(self::CH_OPEN, self::MOD, ['discussionId' => self::D_OPEN]);

        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, $this->database()->table('posts')->where('discussion_id', self::D_OPEN)->count());
    }

    public function test_a_restricted_channel_is_not_archived_into_a_wider_discussion(): void
    {
        $response = $this->archive(self::CH_STAFF, self::POSTING_MOD, ['discussionId' => self::D_OPEN]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, $this->database()->table('posts')->where('discussion_id', self::D_OPEN)->count());
    }

    /*
     * F5: the room hears about a real arrival or departure, once.
     */
    public function test_joining_twice_and_leaving_twice_announce_once_each(): void
    {
        $this->assertSame(200, $this->channelAction(self::CH_OPEN, 'join', self::MEMBER)->getStatusCode());
        $this->assertSame(200, $this->channelAction(self::CH_OPEN, 'join', self::MEMBER)->getStatusCode());
        $this->assertSame(204, $this->channelAction(self::CH_OPEN, 'leave', self::MEMBER)->getStatusCode());
        $this->assertSame(204, $this->channelAction(self::CH_OPEN, 'leave', self::MEMBER)->getStatusCode());

        $this->assertSame(['user_joined', 'user_left'], $this->systemKeysIn(self::CH_OPEN));
    }

    public function test_leaving_a_channel_never_joined_says_nothing(): void
    {
        $this->assertSame(204, $this->channelAction(self::CH_OPEN, 'leave', self::MEMBER)->getStatusCode());

        $this->assertSame([], $this->systemKeysIn(self::CH_OPEN));
    }

    public function test_joining_and_leaving_is_throttled(): void
    {
        $statuses = [];

        for ($i = 0; $i <= ChannelResource::MEMBERSHIP_CHANGES_PER_MINUTE; $i++) {
            $statuses[] = $this->channelAction(self::CH_OPEN, $i % 2 === 0 ? 'join' : 'leave', self::MEMBER)->getStatusCode();
        }

        $this->assertSame(422, end($statuses), 'one change past the budget is refused');
        $this->assertNotContains(422, array_slice($statuses, 0, -1));
    }

    /*
     * F6: only category channels are created through the resource.
     */
    public function test_a_direct_or_unknown_channel_type_is_refused(): void
    {
        foreach (['direct', 'zzz'] as $type) {
            $response = $this->createChannel(self::OWNER, ['type' => $type, 'name' => 'forged '.$type]);

            $this->assertSame(422, $response->getStatusCode(), $type.': '.$response->getBody());
        }

        $this->assertSame(0, $this->database()->table('chat_channels')->where('name', 'like', 'forged%')->count());
    }

    /*
     * F7 and F1's fields: switches that reach past the room are not the
     * creator's to turn on, and a category is chosen among the visible ones.
     */
    public function test_an_owner_cannot_turn_on_the_audience_switches(): void
    {
        foreach (['autoJoin', 'autoJoinOnReply', 'postDiscussions'] as $field) {
            $response = $this->createChannel(self::OWNER, ['name' => 'room '.$field, 'tagId' => 1, $field => true]);

            $this->assertSame(422, $response->getStatusCode(), $field.': '.$response->getBody());
        }

        $unchanged = $this->createChannel(self::OWNER, [
            'name'            => 'plain room',
            'autoJoin'        => false,
            'autoJoinOnReply' => false,
            'postDiscussions' => false,
        ]);

        $this->assertSame(201, $unchanged->getStatusCode(), 'the form sends them off on every save');
    }

    public function test_a_chat_moderator_can_turn_on_the_audience_switches(): void
    {
        $response = $this->send(
            $this->request('PATCH', '/api/chat-channels/'.self::CH_LOUNGE, [
                'authenticatedAs' => self::MOD,
                'json'            => ['data' => ['attributes' => ['autoJoinOnReply' => true, 'postDiscussions' => true]]],
            ])
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, (int) $this->database()->table('chat_channels')->where('id', self::CH_LOUNGE)->value('auto_join_on_reply'));
    }

    public function test_an_owner_binds_only_a_category_they_can_see(): void
    {
        $hidden = $this->createChannel(self::OWNER, ['name' => 'staff squat', 'tagId' => 2]);

        $this->assertSame(422, $hidden->getStatusCode(), (string) $hidden->getBody());

        $visible = $this->createChannel(self::OWNER, ['name' => 'lounge corner', 'tagId' => 1]);

        $this->assertSame(201, $visible->getStatusCode(), (string) $visible->getBody());
    }

    /*
     * F10: preferences are not a way in.
     */
    public function test_notification_preferences_need_a_membership(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_OPEN.'/notifications', [
                'authenticatedAs' => self::MEMBER,
                'json'            => ['data' => ['attributes' => ['notificationLevel' => 2]]],
            ])
        );

        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $this->assertFalse($this->isMember(self::CH_OPEN, self::MEMBER));
    }

    /*
     * F15: an owner cannot find, nor evict, an inspector they are not meant to see.
     */
    public function test_an_owner_cannot_remove_a_hidden_member(): void
    {
        $hidden = $this->removeMember(self::CH_OPEN, self::OWNER, self::INSPECTOR);
        $stranger = $this->removeMember(self::CH_OPEN, self::OWNER, self::MEMBER);

        $this->assertSame(422, $hidden->getStatusCode(), (string) $hidden->getBody());
        $this->assertSame($stranger->getStatusCode(), $hidden->getStatusCode());
        $this->assertSame(
            json_decode((string) $stranger->getBody(), true),
            json_decode((string) $hidden->getBody(), true),
            'the same answer a stranger gets'
        );
        $this->assertTrue($this->isMember(self::CH_OPEN, self::INSPECTOR));
    }

    public function test_a_chat_moderator_can_remove_a_hidden_member(): void
    {
        $response = $this->removeMember(self::CH_OPEN, self::MOD, self::INSPECTOR);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertFalse($this->isMember(self::CH_OPEN, self::INSPECTOR));
    }

    public function test_inviting_a_hidden_member_looks_like_inviting_anyone(): void
    {
        $response = $this->invite(self::CH_OPEN, self::OWNER, [self::INSPECTOR]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, $this->database()->table('chat_channel_invites')
            ->where('channel_id', self::CH_OPEN)->where('user_id', self::INSPECTOR)->count());
    }

    /*
     * F17: invitations are a budget, and a refusal holds for a while.
     */
    public function test_a_declined_invitation_is_not_repeated_straight_away(): void
    {
        $this->invite(self::CH_PRIVATE, self::OWNER, [self::MEMBER]);

        $decline = $this->send(
            $this->request('POST', '/api/chat/invites/'.self::CH_PRIVATE.'/decline', [
                'authenticatedAs' => self::MEMBER,
            ])
        );

        $this->assertSame(204, $decline->getStatusCode(), (string) $decline->getBody());

        $again = $this->invite(self::CH_PRIVATE, self::OWNER, [self::MEMBER]);

        $this->assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        $this->assertSame(0, $this->database()->table('chat_channel_invites')
            ->where('channel_id', self::CH_PRIVATE)->where('user_id', self::MEMBER)->count());
    }

    public function test_invitations_are_throttled_per_inviter(): void
    {
        $crowd = range(self::CROWD, self::CROWD + ChannelResource::INVITES_PER_HOUR - 1);

        foreach (array_chunk($crowd, 50) as $chunk) {
            $response = $this->invite(self::CH_OPEN, self::OWNER, $chunk);

            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        }

        $over = $this->invite(self::CH_OPEN, self::OWNER, [self::CROWD + ChannelResource::INVITES_PER_HOUR]);

        $this->assertSame(422, $over->getStatusCode(), (string) $over->getBody());
        $this->assertSame(
            ChannelResource::INVITES_PER_HOUR,
            $this->database()->table('chat_channel_invites')->where('channel_id', self::CH_OPEN)->count()
        );
    }

    private function announce(int $discussionId): void
    {
        $container = $this->app()->getContainer();

        $post = Post::query()->where('discussion_id', $discussionId)->where('number', 1)->firstOrFail();

        $container->make('events')->dispatch(new Posted($post, User::query()->findOrFail(1)));
    }

    /** @return int[] */
    private function announcedIn(int $channel): array
    {
        return $this->database()->table('chat_messages')
            ->where('channel_id', $channel)
            ->orderBy('id')
            ->pluck('content')
            ->map(fn ($content) => preg_match('~/d/(\d+)~', (string) $content, $m) ? (int) $m[1] : 0)
            ->all();
    }

    /** @param array<string, mixed> $attributes */
    private function archive(int $channel, int $as, array $attributes): ResponseInterface
    {
        // Archiving is the step after closing; ChannelPolicy refuses an open room.
        $this->database()->table('chat_channels')->where('id', $channel)->update(['status' => 'closed']);

        return $this->send(
            $this->request('POST', '/api/chat-channels/'.$channel.'/archive', [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => $attributes]],
            ])
        );
    }

    private function channelAction(int $channel, string $action, int $as): ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/chat-channels/'.$channel.'/'.$action, [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => []]],
            ])
        );
    }

    /** @param array<string, mixed> $attributes */
    private function createChannel(int $as, array $attributes): ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/chat-channels', [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => $attributes + ['type' => 'category']]],
            ])
        );
    }

    private function removeMember(int $channel, int $as, int $user): ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/chat-channels/'.$channel.'/members/remove', [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => ['userId' => $user]]],
            ])
        );
    }

    /** @param int[] $users */
    private function invite(int $channel, int $as, array $users): ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/chat-channels/'.$channel.'/members', [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => ['userIds' => $users]]],
            ])
        );
    }

    private function isMember(int $channel, int $user): bool
    {
        return $this->database()->table('chat_channel_user')
            ->where('channel_id', $channel)
            ->where('user_id', $user)
            ->whereNull('left_at')
            ->exists();
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

    /** @return array<string, mixed> */
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
    private function channel(int $id, string $slug, ?int $tagId, bool $private, bool $announces = false): array
    {
        return [
            'id'                          => $id,
            'type'                        => 'category',
            'name'                        => $slug,
            'slug'                        => $slug,
            // Archiving is offered once a channel is closed; the lounge stays open
            // because an announcement needs somewhere to land.
            'status'                      => $id === self::CH_LOUNGE || $id === self::CH_OPEN ? 'open' : 'closed',
            'tag_id'                      => $tagId,
            'is_private'                  => $private ? 1 : 0,
            'post_permission'             => 'all',
            'creator_id'                  => self::OWNER,
            'threading_enabled'           => 0,
            'auto_join'                   => 0,
            'auto_join_on_reply'          => 0,
            'post_discussions'            => $announces ? 1 : 0,
            'allow_channel_wide_mentions' => 1,
            'messages_count'              => 0,
            'user_count'                  => 1,
            'created_at'                  => Carbon::now()->toDateTimeString(),
            'updated_at'                  => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function membership(int $channelId, int $userId, bool $hidden = false): array
    {
        return [
            'channel_id' => $channelId,
            'user_id'    => $userId,
            'hidden'     => $hidden ? 1 : 0,
            'joined_at'  => Carbon::now()->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function discussion(int $id, string $title, bool $private = false, bool $hidden = false): array
    {
        return [
            'id'            => $id,
            'title'         => $title,
            'slug'          => 'd-'.$id,
            'user_id'       => 1,
            'first_post_id' => $id,
            'comment_count' => 1,
            'created_at'    => Carbon::now()->subHour()->toDateTimeString(),
            'is_private'    => $private ? 1 : 0,
            'hidden_at'     => $hidden ? Carbon::now()->toDateTimeString() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function post(int $discussionId): array
    {
        return [
            'id'            => $discussionId,
            'discussion_id' => $discussionId,
            'number'        => 1,
            'user_id'       => 1,
            'type'          => 'comment',
            'content'       => '<t><p>opening post</p></t>',
            'created_at'    => Carbon::now()->subHour()->toDateTimeString(),
            'is_private'    => 0,
        ];
    }
}
