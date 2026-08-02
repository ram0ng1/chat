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

/**
 * A forum that runs the chat for one group only.
 *
 * Reported from an install that had granted `ramon-chat.use` to a staff group:
 * the header button was drawn for everyone, `/chat` served, and the badge
 * offered five channels nobody outside the group could open. Three separate
 * causes, one visible symptom, and each is asserted below.
 *
 * Note what the fixture does *not* do: it never grants `ramon-chat.use` to
 * MEMBER. The extension seeds that on install and a forum is free to revoke it,
 * which is the state being reproduced here.
 */
class RestrictedChatAccessTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt for "too-obscure", the same hash `normalUser()` carries. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'staffer', 'password' => self::PASSWORD_HASH, 'email' => 'staffer@machine.local', 'is_email_confirmed' => 1],
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Staff', 'name_plural' => 'Staff'],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 100],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => 100, 'permission' => 'ramon-chat.use'],
            ],
        ]);
    }

    private function canUseChat(int $as): bool
    {
        $response = $this->send($this->request('GET', '/api', ['authenticatedAs' => $as]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);

        return (bool) ($body['data']['attributes']['canUseChat'] ?? false);
    }

    public function test_the_header_button_is_not_offered_to_a_member_without_the_permission(): void
    {
        // What the button is drawn from. It used to be read through the Gate with
        // no policy behind it, so the answer came from the fallback — and from any
        // other extension's global `can()` catch-all.
        $this->assertFalse($this->canUseChat(2));
    }

    public function test_the_header_button_is_offered_to_the_permitted_group(): void
    {
        $this->assertTrue($this->canUseChat(3));
    }

    public function test_the_chat_route_is_not_found_for_a_member_without_the_permission(): void
    {
        $response = $this->send($this->request('GET', '/chat', ['authenticatedAs' => 2]));

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function test_the_chat_route_serves_the_permitted_group(): void
    {
        $response = $this->send($this->request('GET', '/chat', ['authenticatedAs' => 3]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_an_auto_join_channel_only_joins_users_who_may_use_the_chat(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels', [
                'authenticatedAs' => 1,
                'json'            => ['data' => ['attributes' => [
                    'name'     => 'Announcements',
                    'type'     => 'category',
                    'autoJoin' => true,
                ]]],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());

        $channelId = (int) json_decode((string) $response->getBody(), true)['data']['id'];

        $joined = $this->database()->table('chat_channel_user')
            ->where('channel_id', $channelId)
            ->pluck('user_id')
            ->map(static fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        // 1 is the admin, 3 is in the permitted group. 2 is a plain member: the
        // listener used to add every row in `users`, which gave them a membership
        // feeding the unread badge for a channel they could not open.
        $this->assertSame([1, 3], $joined);
    }

    public function test_a_stale_membership_does_not_feed_the_badge(): void
    {
        // The state an install is left in by the earlier listener: rows that
        // outlived the permission that wrote them. They cannot be reached, so
        // they must not be counted either.
        $this->database()->table('chat_channels')->insert([
            'id'         => 50,
            'type'       => 'category',
            'name'       => 'stale',
            'slug'       => 'stale',
            'status'     => 'open',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->database()->table('chat_channel_user')->insert([
            'channel_id'   => 50,
            'user_id'      => 2,
            'following'    => 1,
            'muted'        => 0,
            'unread_count' => 5,
            'created_at'   => Carbon::now()->toDateTimeString(),
            'updated_at'   => Carbon::now()->toDateTimeString(),
        ]);

        $response = $this->send($this->request('GET', '/api/users/2', ['authenticatedAs' => 2]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];

        $this->assertSame(0, $attributes['chatUnreadMessagesCount']);
        $this->assertSame(0, $attributes['chatUnreadChannelsCount']);
    }

    public function test_a_membership_in_an_unreachable_channel_does_not_feed_the_badge(): void
    {
        // The case a blanket "may this actor use the chat at all" guard misses:
        // user 3 holds the permission, so the gate says yes, but the channel
        // itself is gone. Counting through Channel::whereVisibleTo() is what
        // catches it — the same shape flarum/messages uses for `messageCount`.
        $this->database()->table('chat_channels')->insert([
            'id'         => 60,
            'type'       => 'category',
            'name'       => 'deleted',
            'slug'       => 'deleted',
            'status'     => 'open',
            'deleted_at' => Carbon::now()->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->database()->table('chat_channel_user')->insert([
            'channel_id'            => 60,
            'user_id'               => 3,
            'following'             => 1,
            'muted'                 => 0,
            'unread_count'          => 7,
            'unread_mentions_count' => 2,
            'created_at'            => Carbon::now()->toDateTimeString(),
            'updated_at'            => Carbon::now()->toDateTimeString(),
        ]);

        $response = $this->send($this->request('GET', '/api/users/3', ['authenticatedAs' => 3]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];

        $this->assertSame(0, $attributes['chatUnreadMessagesCount']);
        $this->assertSame(0, $attributes['chatUnreadChannelsCount']);
        $this->assertSame(0, $attributes['chatUnreadMentionsCount']);
    }
}
