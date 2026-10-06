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
use Ramon\Chat\Event\ChannelModeratorChanged;
use Ramon\Chat\Event\ChannelStatusChanged;
use Ramon\Chat\Tests\integration\FlushesCache;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Taking a channel back out of the archive, and the state machine around it.
 *
 * The bug this grew from: the status endpoint accepted "open" and "closed" on
 * an archived channel, which left the archive by status alone and kept the
 * archive stamps, so the archive action never came back for that channel.
 * Now the status endpoint refuses an archive, unarchiving clears the stamps,
 * and a channel that has been through it can be archived again.
 *
 * Also the promotion response, whose `moderatorIds` used to come back empty.
 */
class UnarchiveChannelTest extends TestCase
{
    use FlushesCache;
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const MEMBER = 2;
    private const OWNER = 3;
    private const MOD = 4;

    private const CH_ARCHIVED = 1;
    private const CH_CLOSED = 2;

    /** @var array<int, object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $this->setting('ramon-chat.channel_ownership', 'members');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                $this->user(self::OWNER, 'owner'),
                $this->user(self::MOD, 'moderator'),
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Owner', 'name_plural' => 'Owners'],
                ['id' => 101, 'name_singular' => 'Chatmod', 'name_plural' => 'Chatmods'],
            ],
            'group_user' => [
                ['user_id' => self::OWNER, 'group_id' => 100],
                ['user_id' => self::MOD, 'group_id' => 101],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => 100, 'permission' => 'ramon-chat.createChannel'],
                ['group_id' => 100, 'permission' => 'ramon-chat.manageOwnChannels'],
                ['group_id' => 101, 'permission' => 'ramon-chat.moderate'],
            ],
            'chat_channels' => [
                $this->channel(self::CH_ARCHIVED, 'archived-room', 'archived', $now),
                $this->channel(self::CH_CLOSED, 'closed-room', 'closed', $now),
            ],
            'chat_channel_user' => [
                ['channel_id' => self::CH_ARCHIVED, 'user_id' => self::OWNER, 'created_at' => $now],
                ['channel_id' => self::CH_ARCHIVED, 'user_id' => self::MEMBER, 'created_at' => $now],
                ['channel_id' => self::CH_CLOSED, 'user_id' => self::OWNER, 'created_at' => $now],
                ['channel_id' => self::CH_CLOSED, 'user_id' => self::MEMBER, 'created_at' => $now],
            ],
        ]);

        $this->flushCache();
    }

    private function listen(string $event): void
    {
        $this->app()->getContainer()->make('events')->listen($event, function ($payload) {
            $this->dispatched[] = $payload;
        });
    }

    public function test_a_member_may_not_unarchive(): void
    {
        $response = $this->action(self::CH_ARCHIVED, 'unarchive', self::MEMBER);

        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('archived', $this->row(self::CH_ARCHIVED)->status);
        $this->assertNotNull($this->row(self::CH_ARCHIVED)->archived_at);
    }

    public function test_the_owner_unarchives_into_a_closed_channel_without_the_stamps(): void
    {
        $this->listen(ChannelStatusChanged::class);

        $response = $this->action(self::CH_ARCHIVED, 'unarchive', self::OWNER);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];

        $this->assertSame('closed', $attributes['status']);
        $this->assertNull($attributes['archivedAt']);
        $this->assertNull($attributes['archivedDiscussionId']);
        $this->assertFalse($attributes['canUnarchive']);
        $this->assertTrue($attributes['canArchive'], 'a closed channel can be archived again');
        $this->assertTrue($attributes['canClose'], 'and reopened');

        $row = $this->row(self::CH_ARCHIVED);
        $this->assertSame('closed', $row->status);
        $this->assertNull($row->archived_at);
        $this->assertNull($row->archived_by_id);
        $this->assertNull($row->archived_discussion_id);

        $this->assertCount(1, $this->dispatched, 'the status change is what realtime delivers');
        $this->assertSame('archived', $this->dispatched[0]->previousStatus);
    }

    public function test_a_chat_moderator_and_an_administrator_may_unarchive(): void
    {
        $this->assertSame(200, $this->action(self::CH_ARCHIVED, 'unarchive', self::MOD)->getStatusCode());

        $this->database()->table('chat_channels')->where('id', self::CH_ARCHIVED)->update(['status' => 'archived']);

        $this->assertSame(200, $this->action(self::CH_ARCHIVED, 'unarchive', 1)->getStatusCode());
    }

    public function test_a_channel_that_is_not_archived_cannot_be_unarchived(): void
    {
        $response = $this->action(self::CH_CLOSED, 'unarchive', self::OWNER);

        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_the_status_endpoint_refuses_an_archived_channel(): void
    {
        foreach (['open', 'closed'] as $status) {
            $response = $this->setStatus(self::CH_ARCHIVED, $status, self::OWNER);

            $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        }

        $row = $this->row(self::CH_ARCHIVED);
        $this->assertSame('archived', $row->status);
        $this->assertNotNull($row->archived_at);
    }

    public function test_an_archived_channel_offers_unarchive_and_not_close(): void
    {
        $response = $this->send($this->request('GET', '/api/chat-channels/'.self::CH_ARCHIVED, [
            'authenticatedAs' => self::OWNER,
        ]));

        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];

        $this->assertTrue($attributes['canUnarchive']);
        $this->assertFalse($attributes['canClose']);
        $this->assertFalse($attributes['canArchive']);
    }

    /**
     * The user's own sequence: out of the archive, open, closed, and archived
     * again.
     */
    public function test_a_channel_can_go_round_the_whole_cycle(): void
    {
        $this->assertSame(200, $this->action(self::CH_ARCHIVED, 'unarchive', 1)->getStatusCode());
        $this->assertSame(200, $this->setStatus(self::CH_ARCHIVED, 'open', 1)->getStatusCode());
        $this->assertSame(200, $this->setStatus(self::CH_ARCHIVED, 'closed', 1)->getStatusCode());

        $archived = $this->send($this->request('POST', '/api/chat-channels/'.self::CH_ARCHIVED.'/archive', [
            'authenticatedAs' => 1,
            'json'            => ['data' => ['attributes' => ['title' => 'Archived again']]],
        ]));

        $this->assertSame(200, $archived->getStatusCode(), (string) $archived->getBody());

        $row = $this->row(self::CH_ARCHIVED);
        $this->assertSame('archived', $row->status);
        $this->assertNotNull($row->archived_discussion_id);
    }

    public function test_promoting_answers_with_the_new_moderator_and_announces_it(): void
    {
        $this->listen(ChannelModeratorChanged::class);

        $promote = fn () => $this->send($this->request('POST', '/api/chat-channels/'.self::CH_CLOSED.'/moderators', [
            'authenticatedAs' => self::OWNER,
            'json'            => ['data' => ['attributes' => ['userId' => self::MEMBER]]],
        ]));

        $response = $promote();

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(
            [self::MEMBER],
            json_decode((string) $response->getBody(), true)['data']['attributes']['moderatorIds']
        );

        // Promoting again changes nothing, and announces nothing.
        $this->assertSame(200, $promote()->getStatusCode());

        $this->assertCount(1, $this->dispatched);
        $this->assertTrue($this->dispatched[0]->isModerator);

        $demote = $this->send($this->request('POST', '/api/chat-channels/'.self::CH_CLOSED.'/moderators/remove', [
            'authenticatedAs' => self::OWNER,
            'json'            => ['data' => ['attributes' => ['userId' => self::MEMBER]]],
        ]));

        $this->assertSame([], json_decode((string) $demote->getBody(), true)['data']['attributes']['moderatorIds']);
        $this->assertCount(2, $this->dispatched);
        $this->assertFalse($this->dispatched[1]->isModerator);
    }

    private function action(int $channel, string $action, int $as): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/chat-channels/'.$channel.'/'.$action, [
            'authenticatedAs' => $as,
            'json'            => ['data' => ['attributes' => []]],
        ]));
    }

    private function setStatus(int $channel, string $status, int $as): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/chat-channels/'.$channel.'/status', [
            'authenticatedAs' => $as,
            'json'            => ['data' => ['attributes' => ['status' => $status]]],
        ]));
    }

    private function row(int $channel): object
    {
        return $this->database()->table('chat_channels')->where('id', $channel)->first();
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
    private function channel(int $id, string $slug, string $status, string $now): array
    {
        $archived = $status === 'archived';

        return [
            'id'                          => $id,
            'type'                        => 'category',
            'name'                        => $slug,
            'slug'                        => $slug,
            'status'                      => $status,
            'is_private'                  => 0,
            'post_permission'             => 'all',
            'creator_id'                  => self::OWNER,
            'threading_enabled'           => 0,
            'auto_join'                   => 0,
            'auto_join_on_reply'          => 0,
            'post_discussions'            => 0,
            'allow_channel_wide_mentions' => 1,
            'messages_count'              => 0,
            'user_count'                  => 2,
            'archived_at'                 => $archived ? $now : null,
            'archived_by_id'              => $archived ? 1 : null,
            'archived_discussion_id'      => $archived ? 77 : null,
            'created_at'                  => $now,
            'updated_at'                  => $now,
        ];
    }
}
