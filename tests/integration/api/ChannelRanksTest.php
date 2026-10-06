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
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Pusher\Pusher;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\ChannelRanksChanged;
use Ramon\Chat\Realtime\BroadcastListener;
use Ramon\Chat\Tests\integration\FlushesCache;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Cargos de canal: quem os administra, o que é aceito, quem pode recebê-los,
 * qual aparece ao lado do nome, e quanto custa servi-los numa página.
 */
class ChannelRanksTest extends TestCase
{
    use FlushesCache;
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const ADMIN = 1;
    private const OWNER = 3;
    private const CHANNEL_MOD = 4;
    private const MEMBER = 5;
    private const OUTSIDER = 6;
    private const CHAT_MOD = 7;
    private const HIDDEN = 8;
    private const GONE = 9;

    /** Authors 20.. post into the busy channel for the query count. */
    private const FIRST_AUTHOR = 20;

    private const CHANNEL = 1;
    private const OTHER_CHANNEL = 2;
    private const DIRECT = 3;
    private const BUSY = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');
    }

    private function seed(string $mode = 'members', int $busyAuthors = 0): void
    {
        $this->setting('ramon-chat.channel_ownership', $mode);

        $now = Carbon::now()->toDateTimeString();

        $users = [
            $this->user(self::OWNER, 'owner'),
            $this->user(self::CHANNEL_MOD, 'chanmod'),
            $this->user(self::MEMBER, 'member'),
            $this->user(self::OUTSIDER, 'outsider'),
            $this->user(self::CHAT_MOD, 'chatmod'),
            $this->user(self::HIDDEN, 'hidden'),
            $this->user(self::GONE, 'gone'),
        ];

        $memberships = [
            $this->membership(self::CHANNEL, self::OWNER),
            $this->membership(self::CHANNEL, self::CHANNEL_MOD, moderator: true),
            $this->membership(self::CHANNEL, self::MEMBER),
            $this->membership(self::CHANNEL, self::HIDDEN, hidden: true),
            $this->membership(self::CHANNEL, self::GONE, left: true),
            $this->membership(self::OTHER_CHANNEL, self::MEMBER),
            $this->membership(self::DIRECT, self::OWNER),
            $this->membership(self::DIRECT, self::MEMBER),
            $this->membership(self::BUSY, self::MEMBER),
        ];

        $messages = [
            $this->message(1, self::CHANNEL, self::OWNER, 1),
            $this->message(2, self::CHANNEL, self::CHANNEL_MOD, 2),
            $this->message(3, self::CHANNEL, self::MEMBER, 3),
        ];

        for ($i = 0; $i < $busyAuthors; $i++) {
            $id = self::FIRST_AUTHOR + $i;
            $users[] = $this->user($id, 'author'.$i);
            $memberships[] = $this->membership(self::BUSY, $id);
            $messages[] = $this->message(100 + $i, self::BUSY, $id, $i + 1);
        }

        $this->prepareDatabase([
            'users'  => $users,
            'groups' => [
                ['id' => 100, 'name_singular' => 'Owner', 'name_plural' => 'Owners'],
                ['id' => 101, 'name_singular' => 'Chatmod', 'name_plural' => 'Chatmods'],
            ],
            'group_user' => [
                ['user_id' => self::OWNER, 'group_id' => 100],
                ['user_id' => self::CHAT_MOD, 'group_id' => 101],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => 100, 'permission' => 'ramon-chat.createChannel'],
                ['group_id' => 100, 'permission' => 'ramon-chat.manageOwnChannels'],
                ['group_id' => 101, 'permission' => 'ramon-chat.moderate'],
            ],
            'chat_channels' => [
                $this->channel(self::CHANNEL, 'category', 'room', self::OWNER),
                $this->channel(self::OTHER_CHANNEL, 'category', 'other', self::ADMIN),
                $this->channel(self::DIRECT, 'direct', null, self::OWNER),
                $this->channel(self::BUSY, 'category', 'busy', self::ADMIN),
            ],
            'chat_channel_user' => $memberships,
            'chat_messages'     => $messages,
        ]);

        $this->flushCache();
    }

    public function test_the_owner_creates_a_rank_and_every_member_reads_it(): void
    {
        $this->seed();

        $created = $this->rank(self::OWNER, '', ['name' => '  Veteran   player ', 'color' => '#F1C40F', 'icon' => 'fas fa-star']);
        $this->assertSame(200, $created->getStatusCode(), (string) $created->getBody());

        $book = $this->book(self::MEMBER);
        $custom = $this->customRanks($book);

        $this->assertCount(1, $custom);
        $this->assertSame('Veteran player', $custom[0]['name'], 'whitespace collapsed and trimmed');
        $this->assertSame('#f1c40f', $custom[0]['color']);
        $this->assertSame('fas fa-star', $custom[0]['icon']);
        $this->assertTrue($custom[0]['showBadge']);
        $this->assertSame('#16161a', $custom[0]['textColor'], 'a light tag takes dark text');
        $this->assertNotSame('#f1c40f', $custom[0]['nameLight'], 'too light to read as a name on white, so darkened');
        $this->assertSame(['owner', 'moderator'], array_column(array_slice($book['ranks'], 0, 2), 'builtin'));
        $this->assertSame(self::OWNER, $book['ownerId']);
        $this->assertSame([self::CHANNEL_MOD], $book['moderatorIds']);
    }

    public function test_extra_fields_are_ignored(): void
    {
        $this->seed();

        $this->rank(self::OWNER, '', [
            'name'       => 'Plain',
            'color'      => '#336699',
            'builtin'    => 'owner',
            'channel_id' => self::OTHER_CHANNEL,
            'channelId'  => self::OTHER_CHANNEL,
            'position'   => 99,
        ]);

        $row = $this->database()->table('chat_channel_ranks')->first();
        $this->assertSame(self::CHANNEL, (int) $row->channel_id);
        $this->assertNull($row->builtin);
        $this->assertSame(1, (int) $row->position);
    }

    public function test_who_may_manage_ranks(): void
    {
        $this->seed();

        $this->assertSame(200, $this->rank(self::OWNER, '', ['name' => 'A', 'color' => '#111111'])->getStatusCode());
        $this->assertSame(200, $this->rank(self::CHAT_MOD, '', ['name' => 'B', 'color' => '#222222'])->getStatusCode());
        $this->assertSame(200, $this->rank(self::ADMIN, '', ['name' => 'C', 'color' => '#333333'])->getStatusCode());

        $this->assertSame(403, $this->rank(self::CHANNEL_MOD, '', ['name' => 'D', 'color' => '#444444'])->getStatusCode(), 'a channel moderator does not hand out ranks');
        $this->assertSame(403, $this->rank(self::MEMBER, '', ['name' => 'E', 'color' => '#555555'])->getStatusCode());
        $this->assertSame(403, $this->rank(self::OUTSIDER, '/assign', ['userId' => self::MEMBER, 'rankIds' => []])->getStatusCode());
        $this->assertContains($this->send($this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/ranks', [
            'json' => ['data' => ['attributes' => ['name' => 'F', 'color' => '#666666']]],
        ]))->getStatusCode(), [400, 401], 'a guest is turned away');

        $this->assertSame(3, $this->database()->table('chat_channel_ranks')->count());

        $this->assertTrue($this->attributes(self::OWNER)['canManageRanks']);
        $this->assertFalse($this->attributes(self::CHANNEL_MOD)['canManageRanks']);
        $this->assertFalse($this->attributes(self::MEMBER)['canManageRanks']);
    }

    public function test_while_administrators_run_channels_the_creator_cannot_manage_ranks_but_still_shows_as_owner(): void
    {
        $this->seed('admin');

        $this->assertSame(403, $this->rank(self::OWNER, '', ['name' => 'A', 'color' => '#111111'])->getStatusCode());
        $this->assertSame(200, $this->rank(self::CHAT_MOD, '', ['name' => 'B', 'color' => '#222222'])->getStatusCode());

        $ranks = $this->authorRanks(self::MEMBER, self::CHANNEL);
        $this->assertSame('owner', $ranks[self::OWNER]['key'] ?? null);
    }

    public function test_a_direct_conversation_has_no_ranks(): void
    {
        $this->seed();

        $this->assertSame(403, $this->rank(self::OWNER, '', ['name' => 'A', 'color' => '#111111'], self::DIRECT)->getStatusCode());
        $this->assertSame(404, $this->rank(self::ADMIN, '', ['name' => 'A', 'color' => '#111111'], self::DIRECT)->getStatusCode(), 'not even visible to an administrator outside it');
        $this->assertNull($this->attributes(self::MEMBER, self::DIRECT)['rankBook']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidRanks(): array
    {
        return [
            'colour name'         => [['name' => 'A', 'color' => 'red'], 'color'],
            'short hex'           => [['name' => 'A', 'color' => '#fff'], 'color'],
            'bad hex digits'      => [['name' => 'A', 'color' => '#GGGGGG'], 'color'],
            'hex with css'        => [['name' => 'A', 'color' => '#ffffff;background:url(x)'], 'color'],
            'missing colour'      => [['name' => 'A'], 'color'],
            'empty name'          => [['name' => '   ', 'color' => '#123456'], 'name'],
            'missing name'        => [['color' => '#123456'], 'name'],
            'name too long'       => [['name' => str_repeat('a', 33), 'color' => '#123456'], 'name'],
            'name not a string'   => [['name' => ['x'], 'color' => '#123456'], 'name'],
            'icon with a quote'   => [['name' => 'A', 'color' => '#123456', 'icon' => 'fas fa-star" onmouseover="x'], 'icon'],
            'icon not fa'         => [['name' => 'A', 'color' => '#123456', 'icon' => 'glyphicon glyphicon-star'], 'icon'],
            'icon two classes'    => [['name' => 'A', 'color' => '#123456', 'icon' => 'fas fa-star fa-spin'], 'icon'],
            'show badge string'   => [['name' => 'A', 'color' => '#123456', 'showBadge' => 'yes'], 'showBadge'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRanks')]
    public function test_an_invalid_rank_is_refused(array $attributes, string $field): void
    {
        $this->seed();

        $response = $this->rank(self::OWNER, '', $attributes);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('/data/attributes/'.$field, json_decode((string) $response->getBody(), true)['errors'][0]['source']['pointer']);
        $this->assertSame(0, $this->database()->table('chat_channel_ranks')->count());
    }

    public function test_a_name_of_exactly_thirty_two_characters_is_accepted_however_wide(): void
    {
        $this->seed();

        $name = str_repeat('é', 32);

        $this->assertSame(200, $this->rank(self::OWNER, '', ['name' => $name, 'color' => '#123456', 'icon' => 'fa-solid fa-shield-halved'])->getStatusCode());
        $this->assertSame($name, $this->customRanks($this->book(self::MEMBER))[0]['name']);
    }

    public function test_a_channel_holds_at_most_twenty_ranks(): void
    {
        $this->seed();

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(200, $this->rank(self::ADMIN, '', ['name' => 'R'.$i, 'color' => '#123456'])->getStatusCode());
        }

        $this->assertSame(422, $this->rank(self::ADMIN, '', ['name' => 'one more', 'color' => '#123456'])->getStatusCode());
        $this->assertSame(20, $this->database()->table('chat_channel_ranks')->count());
    }

    public function test_ranks_go_only_to_present_visible_members(): void
    {
        $this->seed();

        $rank = $this->createRank('Regular', '#2e86de');
        $foreign = $this->createRank('Elsewhere', '#2e86de', self::OTHER_CHANNEL, self::ADMIN);

        foreach ([self::OUTSIDER, self::GONE, self::HIDDEN] as $user) {
            $response = $this->rank(self::OWNER, '/assign', ['userId' => $user, 'rankIds' => [$rank]]);
            $this->assertSame(422, $response->getStatusCode(), 'user '.$user);
        }

        $this->assertSame(422, $this->rank(self::OWNER, '/assign', ['userId' => self::MEMBER, 'rankIds' => [$foreign]])->getStatusCode(), "another channel's rank");
        $this->assertSame(422, $this->rank(self::OWNER, '/assign', ['userId' => self::MEMBER, 'rankIds' => [9999]])->getStatusCode());
        $this->assertSame(0, $this->database()->table('chat_channel_rank_user')->count());

        $this->assertSame(200, $this->rank(self::OWNER, '/assign', ['userId' => self::MEMBER, 'rankIds' => [$rank, $rank]])->getStatusCode());
        $this->assertSame([(string) self::MEMBER => [$rank]], $this->book(self::MEMBER)['assignments']);

        $this->assertSame(200, $this->rank(self::OWNER, '/assign', ['userId' => self::MEMBER, 'rankIds' => []])->getStatusCode());
        $this->assertSame(0, $this->database()->table('chat_channel_rank_user')->count(), 'an empty list takes every rank away');
    }

    public function test_the_shown_rank_is_owner_then_moderator_then_the_owners_order(): void
    {
        $this->seed();

        $first = $this->createRank('First', '#c0392b');
        $second = $this->createRank('Second', '#27ae60');

        $this->assign(self::MEMBER, [$second, $first]);
        $this->assign(self::OWNER, [$first]);
        $this->assign(self::CHANNEL_MOD, [$second]);

        $ranks = $this->authorRanks(self::MEMBER, self::CHANNEL);
        $this->assertSame('owner', $ranks[self::OWNER]['key']);
        $this->assertSame('moderator', $ranks[self::CHANNEL_MOD]['key']);
        $this->assertSame((string) $first, $ranks[self::MEMBER]['key']);

        $this->assertSame(200, $this->rank(self::OWNER, '/order', ['rankIds' => [$second, $first]])->getStatusCode());
        $this->assertSame((string) $second, $this->authorRanks(self::MEMBER, self::CHANNEL)[self::MEMBER]['key'], 'reordering moves priority');

        $this->assertSame(200, $this->rank(self::OWNER, '/delete', ['rankId' => $second])->getStatusCode());
        $this->assertSame((string) $first, $this->authorRanks(self::MEMBER, self::CHANNEL)[self::MEMBER]['key'], 'deleting falls back to the next');
        $this->assertSame(0, $this->database()->table('chat_channel_rank_user')->where('rank_id', $second)->count());

        $this->assertSame(200, $this->rank(self::OWNER, '/delete', ['rankId' => $first])->getStatusCode());
        $this->assertNull($this->authorRanks(self::MEMBER, self::CHANNEL)[self::MEMBER]);
    }

    public function test_an_order_must_name_every_rank_once(): void
    {
        $this->seed();

        $a = $this->createRank('A', '#111111');
        $b = $this->createRank('B', '#222222');
        $foreign = $this->createRank('X', '#333333', self::OTHER_CHANNEL, self::ADMIN);

        foreach ([[$a], [$a, $a], [$a, $b, $foreign], [$b, $foreign]] as $order) {
            $this->assertSame(422, $this->rank(self::OWNER, '/order', ['rankIds' => $order])->getStatusCode(), json_encode($order));
        }
    }

    public function test_a_rank_shown_without_its_tag_still_says_so_everywhere(): void
    {
        $this->seed();

        $rank = $this->createRank('Quiet', '#1e3799');
        $this->assign(self::MEMBER, [$rank]);

        $this->assertSame(200, $this->rank(self::OWNER, '/update', ['rankId' => $rank, 'showBadge' => false])->getStatusCode());

        $entry = $this->customRanks($this->book(self::MEMBER))[0];
        $this->assertFalse($entry['showBadge']);
        $this->assertSame('#1e3799', $entry['nameLight'], 'dark enough to read on white as it is');
        $this->assertNotSame('#1e3799', $entry['nameDark'], 'lightened to read on the dark theme');

        $author = $this->authorRanks(self::MEMBER, self::CHANNEL)[self::MEMBER];
        $this->assertFalse($author['showBadge']);
        $this->assertSame($entry['nameDark'], $author['nameDark']);
    }

    public function test_the_built_in_ranks_can_be_customised_and_reset(): void
    {
        $this->seed();

        $this->assertSame(200, $this->rank(self::OWNER, '/update', ['rankId' => 'owner', 'name' => 'Founder', 'color' => '#8E44AD', 'icon' => 'fas fa-crown'])->getStatusCode());
        $this->assertSame(200, $this->rank(self::OWNER, '/update', ['rankId' => 'moderator', 'showBadge' => false])->getStatusCode());

        $book = $this->book(self::MEMBER);
        $this->assertSame(['Founder', '#8e44ad', 'fas fa-crown'], [$book['ranks'][0]['name'], $book['ranks'][0]['color'], $book['ranks'][0]['icon']]);
        $this->assertFalse($book['ranks'][1]['showBadge']);
        $this->assertNull($book['ranks'][1]['color'], 'a built-in may keep the theme colour');

        $this->assertSame('Founder', $this->authorRanks(self::MEMBER, self::CHANNEL)[self::OWNER]['name']);

        $this->assertSame(200, $this->rank(self::OWNER, '/delete', ['rankId' => 'owner'])->getStatusCode());
        $this->assertNull($this->book(self::MEMBER)['ranks'][0]['name'], 'reset to the translated default');
        $this->assertCount(0, $this->customRanks($this->book(self::MEMBER)));
    }

    public function test_the_cached_book_follows_promotions_and_departures(): void
    {
        $this->seed();

        $rank = $this->createRank('Regular', '#2e86de');
        $this->assign(self::MEMBER, [$rank]);
        $this->assertSame([self::CHANNEL_MOD], $this->book(self::MEMBER)['moderatorIds']);

        $promote = $this->send($this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/moderators', [
            'authenticatedAs' => self::OWNER,
            'json'            => ['data' => ['attributes' => ['userId' => self::MEMBER]]],
        ]));
        $this->assertSame(200, $promote->getStatusCode(), (string) $promote->getBody());
        $this->assertEqualsCanonicalizing([self::CHANNEL_MOD, self::MEMBER], json_decode((string) $promote->getBody(), true)['data']['attributes']['rankBook']['moderatorIds']);
        $this->assertSame('moderator', $this->authorRanks(self::OWNER, self::CHANNEL)[self::MEMBER]['key']);

        $leave = $this->send($this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/leave', ['authenticatedAs' => self::MEMBER]));
        $this->assertSame(204, $leave->getStatusCode());

        $book = $this->book(self::OWNER);
        $this->assertNotContains(self::MEMBER, $book['moderatorIds']);
        $this->assertArrayNotHasKey((string) self::MEMBER, (array) $book['assignments'], 'only members present show a rank');
    }

    public function test_rank_changes_are_pushed_to_the_room_with_the_whole_book(): void
    {
        $this->seed();
        $rank = $this->createRank('Regular', '#2e86de');

        $recorder = new class('key', 'secret', '1') extends Pusher {
            /** @var array<int, array<string, mixed>> */
            public array $calls = [];

            public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object
            {
                $this->calls[] = ['channels' => (array) $channels, 'event' => $event, 'data' => $data];

                return new \stdClass();
            }
        };
        $this->app()->getContainer()->instance(Pusher::class, $recorder);

        $this->app()->getContainer()->make(BroadcastListener::class)->whenRanksChanged(new ChannelRanksChanged(
            Channel::query()->findOrFail(self::CHANNEL)
        ));

        $this->assertCount(1, $recorder->calls);
        $push = $recorder->calls[0];
        $this->assertSame(BroadcastListener::EVENT_RANKS, $push['event']);
        $this->assertSame(self::CHANNEL, $push['data']['channelId']);
        $this->assertSame($rank, $push['data']['rankBook']['ranks'][2]['id']);
        $this->assertNotContains('private-user='.self::OUTSIDER, $push['channels']);
        $this->assertNotContains('private-user='.self::GONE, $push['channels']);
        $this->assertContains('private-user='.self::MEMBER, $push['channels']);
    }

    public function test_a_page_of_messages_costs_the_same_whatever_the_number_of_ranked_authors(): void
    {
        $this->seed(busyAuthors: 12);

        $few = $this->createRank('Few', '#2e86de', self::BUSY, self::ADMIN);
        $many = $this->createRank('Many', '#c0392b', self::BUSY, self::ADMIN);

        $this->assign(self::FIRST_AUTHOR, [$few], self::BUSY, self::ADMIN);
        $this->assign(self::FIRST_AUTHOR + 1, [$many], self::BUSY, self::ADMIN);

        $small = $this->countMessageQueries(3);

        for ($i = 2; $i < 12; $i++) {
            $this->assign(self::FIRST_AUTHOR + $i, $i % 2 ? [$few] : [$few, $many], self::BUSY, self::ADMIN);
        }

        $large = $this->countMessageQueries(12);

        $this->assertSame($small, $large, 'no query per author or per message for ranks');
    }

    private function countMessageQueries(int $limit): int
    {
        $path = '/api/chat-messages';
        $query = ['filter' => ['channel' => (string) self::BUSY], 'sort' => 'id', 'page' => ['limit' => (string) $limit]];

        $warm = $this->send($this->request('GET', $path, ['authenticatedAs' => self::MEMBER])->withQueryParams($query));
        $this->assertSame(200, $warm->getStatusCode(), (string) $warm->getBody());

        $count = 0;
        /** @var \Illuminate\Database\Connection $db */
        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        $db->listen(function () use (&$count) {
            $count++;
        });

        $response = $this->send($this->request('GET', $path, ['authenticatedAs' => self::MEMBER])->withQueryParams($query));
        $listened = $count;

        $data = json_decode((string) $response->getBody(), true)['data'];
        $this->assertCount($limit, $data);
        $this->assertSame((string) $this->rankIdNamed('Few'), $data[0]['attributes']['authorRank']['key'] ?? null, 'the authors carry their ranks');

        $db->flushQueryLog();

        return $listened;
    }

    private function rankIdNamed(string $name): int
    {
        return (int) $this->database()->table('chat_channel_ranks')->where('name', $name)->value('id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rank(int $actor, string $path, array $attributes, int $channel = self::CHANNEL): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/chat-channels/'.$channel.'/ranks'.$path, [
            'authenticatedAs' => $actor,
            'json'            => ['data' => ['attributes' => $attributes]],
        ]));
    }

    private function createRank(string $name, string $color, int $channel = self::CHANNEL, int $actor = self::OWNER): int
    {
        $response = $this->rank($actor, '', ['name' => $name, 'color' => $color], $channel);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (int) $this->database()->table('chat_channel_ranks')
            ->where('channel_id', $channel)
            ->where('name', $name)
            ->value('id');
    }

    /**
     * @param  array<int, int>  $rankIds
     */
    private function assign(int $user, array $rankIds, int $channel = self::CHANNEL, int $actor = self::OWNER): void
    {
        $response = $this->rank($actor, '/assign', ['userId' => $user, 'rankIds' => $rankIds], $channel);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(int $actor, int $channel = self::CHANNEL): array
    {
        $response = $this->send($this->request('GET', '/api/chat-channels/'.$channel, ['authenticatedAs' => $actor]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data']['attributes'];
    }

    /**
     * @return array<string, mixed>
     */
    private function book(int $actor): array
    {
        return $this->attributes($actor)['rankBook'];
    }

    /**
     * @param  array<string, mixed>  $book
     * @return list<array<string, mixed>>
     */
    private function customRanks(array $book): array
    {
        return array_values(array_filter($book['ranks'], fn ($rank) => $rank['builtin'] === null));
    }

    /**
     * Author id => the rank their messages carry.
     *
     * @return array<int, array<string, mixed>|null>
     */
    private function authorRanks(int $actor, int $channel): array
    {
        $response = $this->send(
            $this->request('GET', '/api/chat-messages', ['authenticatedAs' => $actor])
                ->withQueryParams(['filter' => ['channel' => (string) $channel], 'sort' => '-id'])
        );
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $ranks = [];

        foreach (json_decode((string) $response->getBody(), true)['data'] as $message) {
            $ranks[(int) $message['relationships']['user']['data']['id']] = $message['attributes']['authorRank'];
        }

        return $ranks;
    }

    /** @return array<string, mixed> */
    private function channel(int $id, string $type, ?string $name, int $creator): array
    {
        $now = Carbon::now()->toDateTimeString();

        return [
            'id'                          => $id,
            'type'                        => $type,
            'name'                        => $name,
            'slug'                        => $name,
            'status'                      => 'open',
            'is_private'                  => 0,
            'post_permission'             => 'all',
            'creator_id'                  => $creator,
            'threading_enabled'           => 0,
            'auto_join'                   => 0,
            'auto_join_on_reply'          => 0,
            'post_discussions'            => 0,
            'allow_channel_wide_mentions' => 1,
            'messages_count'              => 0,
            'user_count'                  => 0,
            'created_at'                  => $now,
            'updated_at'                  => $now,
        ];
    }

    /** @return array<string, mixed> */
    private function membership(int $channel, int $user, bool $moderator = false, bool $hidden = false, bool $left = false): array
    {
        $now = Carbon::now()->toDateTimeString();

        return [
            'channel_id'      => $channel,
            'user_id'         => $user,
            'is_moderator'    => $moderator ? 1 : 0,
            'moderator_since' => $moderator ? $now : null,
            'hidden'          => $hidden ? 1 : 0,
            'joined_at'       => $now,
            'left_at'         => $left ? $now : null,
            'created_at'      => $now,
        ];
    }

    /** @return array<string, mixed> */
    private function message(int $id, int $channel, int $user, int $number): array
    {
        $now = Carbon::now()->toDateTimeString();

        return [
            'id'         => $id,
            'channel_id' => $channel,
            'user_id'    => $user,
            'number'     => $number,
            'type'       => 'text',
            'content'    => '<t><p>hello '.$id.'</p></t>',
            'created_at' => $now,
            'updated_at' => $now,
        ];
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
}
