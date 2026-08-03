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

/**
 * Every actor against every kind of channel, through every door.
 *
 * Four actors × four channels × four operations. The operations are the four
 * ways in — being listed, being fetched, being joined, being posted into — and
 * they are asserted as one grid rather than as separate cases because the bugs
 * this is for are disagreements *between* them: a channel that lists but cannot
 * be opened, one that can be joined but not posted in, one the scope hides and
 * the policy would have let through.
 *
 * The grid is also where a permission is proved not to be a skeleton key. Three
 * of the rows hold a chat grant of some kind, and none of them may reach a
 * channel restricted by a *tag* — chat permissions and forum permissions are
 * separate systems, and `accessPrivateChannels` opening a restricted category
 * would be a silent hole in the second one.
 *
 * Runs in its own process for the reason TagBoundChannelTest documents:
 * `ScopeVisibilityTrait::$visibilityScopers` is a static registry that survives
 * between boots, and a sibling class that enabled ramon-chat without tags leaves
 * behind a scoper with no tag-bound branch.
 */
#[RunTestsInSeparateProcesses]
class ChannelAccessMatrixTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt for "too-obscure", the same hash `normalUser()` carries. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const ADMIN = 1;
    private const PLAIN = 2;
    private const ACCESS = 3;
    private const MODERATOR = 4;

    private const CH_PUBLIC = 1;
    private const CH_PRIVATE = 2;
    private const CH_RESTRICTED = 3;
    private const CH_DIRECT = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                $this->user(self::ACCESS, 'accessor'),
                $this->user(self::MODERATOR, 'moderator'),
                // The two ends of the direct channel. Nobody in the matrix is one
                // of them, which is the point: a DM is not reachable by permission.
                $this->user(5, 'dmalice'),
                $this->user(6, 'dmbob'),
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Accessor', 'name_plural' => 'Accessors'],
                ['id' => 101, 'name_singular' => 'Chatmod', 'name_plural' => 'Chatmods'],
                // Holds the restricted tag. Deliberately empty: the restricted
                // column has to be unreachable for every actor in the grid but the
                // administrator.
                ['id' => 102, 'name_singular' => 'Tagholder', 'name_plural' => 'Tagholders'],
            ],
            'group_user' => [
                ['user_id' => self::ACCESS, 'group_id' => 100],
                ['user_id' => self::MODERATOR, 'group_id' => 101],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => 100, 'permission' => 'ramon-chat.accessPrivateChannels'],
                ['group_id' => 101, 'permission' => 'ramon-chat.moderate'],
                ['group_id' => 102, 'permission' => 'tag2.viewForum'],
            ],
            'tags' => [
                ['id' => 1, 'name' => 'Lounge', 'slug' => 'lounge', 'position' => 0, 'is_restricted' => 0],
                ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => 1],
            ],
            'chat_channels' => [
                $this->category(self::CH_PUBLIC, 'open-chat', tagId: null, private: false),
                $this->category(self::CH_PRIVATE, 'private-chat', tagId: null, private: true),
                $this->category(self::CH_RESTRICTED, 'staff-chat', tagId: 2, private: false),
                $this->direct(self::CH_DIRECT),
            ],
            'chat_channel_user' => [
                $this->membership(self::CH_DIRECT, 5),
                $this->membership(self::CH_DIRECT, 6),
            ],
            // One message per channel, so the read probes below are asking about
            // content that exists. An empty channel would answer "[]" for the
            // right and the wrong reason alike — which is the failure mode a
            // visibility test is most likely to have.
            'chat_messages' => [
                $this->message(10, self::CH_PUBLIC),
                $this->message(20, self::CH_PRIVATE),
                $this->message(30, self::CH_RESTRICTED),
                $this->message(40, self::CH_DIRECT),
            ],
            // Same reasoning for every other surface that can carry a channel's
            // contents out: a thread, a pin and a moderation flag each exist in
            // all four channels.
            'chat_threads' => [
                $this->thread(11, self::CH_PUBLIC, 10),
                $this->thread(21, self::CH_PRIVATE, 20),
                $this->thread(31, self::CH_RESTRICTED, 30),
                $this->thread(41, self::CH_DIRECT, 40),
            ],
            'chat_message_flags' => [
                $this->flag(12, 10),
                $this->flag(22, 20),
                $this->flag(32, 30),
                $this->flag(42, 40),
            ],
        ]);

        // Pinning all four keeps the pinned filter asking the same question the
        // plain listing does. `pinned_at` alone decides it — there is no boolean
        // column, and `pinned_by_id` is only attribution.
        $this->database()->table('chat_messages')->update([
            'pinned_at'    => Carbon::now()->toDateTimeString(),
            'pinned_by_id' => 6,
        ]);
    }

    // ── Fixture builders ────────────────────────────────────────────────────

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
    private function category(int $id, string $slug, ?int $tagId, bool $private): array
    {
        return [
            'id'         => $id,
            'type'       => 'category',
            'name'       => $slug,
            'slug'       => $slug,
            'status'     => 'open',
            'tag_id'     => $tagId,
            'is_private' => $private ? 1 : 0,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function direct(int $id): array
    {
        return [
            'id'         => $id,
            'type'       => 'direct',
            'name'       => null,
            'slug'       => null,
            'status'     => 'open',
            'tag_id'     => null,
            'is_private' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function message(int $id, int $channelId): array
    {
        return [
            'id'         => $id,
            'channel_id' => $channelId,
            'user_id'    => 5,
            'type'       => 'text',
            // Valid TextFormatter XML: `content` is serialised through the same
            // unparse/render pipeline a real message goes through, and plain text
            // makes the renderer throw rather than the endpoint answer.
            'content'    => '<t><p>secret of channel '.$channelId.'</p></t>',
            'number'     => 1,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function thread(int $id, int $channelId, int $rootMessageId): array
    {
        return [
            'id'                  => $id,
            'channel_id'          => $channelId,
            'original_message_id' => $rootMessageId,
            'title'               => 'thread of channel '.$channelId,
            'creator_id'          => 5,
            'status'              => 'open',
            'created_at'          => Carbon::now()->toDateTimeString(),
            'updated_at'          => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function flag(int $id, int $messageId): array
    {
        return [
            'id'         => $id,
            'message_id' => $messageId,
            'user_id'    => 6,
            'reason'     => 'spam',
            'detail'     => 'flag detail of message '.$messageId,
            'created_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
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

    // ── The four doors ──────────────────────────────────────────────────────

    /** @return int[] */
    private function listed(int $as): array
    {
        $response = $this->send($this->request('GET', '/api/chat-channels', ['authenticatedAs' => $as]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);
        $ids = array_map(static fn (array $row) => (int) $row['id'], $body['data'] ?? []);

        sort($ids);

        return $ids;
    }

    private function showStatus(int $as, int $channel): int
    {
        return $this->send(
            $this->request('GET', '/api/chat-channels/'.$channel, ['authenticatedAs' => $as])
        )->getStatusCode();
    }

    private function joinStatus(int $as, int $channel): int
    {
        return $this->send(
            $this->request('POST', '/api/chat-channels/'.$channel.'/join', ['authenticatedAs' => $as])
        )->getStatusCode();
    }

    private function postStatus(int $as, int $channel): int
    {
        return $this->send(
            $this->request('POST', '/api/chat-messages', [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => [
                    'channelId' => $channel,
                    'content'   => 'matrix probe',
                ]]],
            ])
        )->getStatusCode();
    }

    /**
     * @return array<int, string> actor id => label, for assertion messages
     */
    private static function actors(): array
    {
        return [
            self::PLAIN     => 'plain member',
            self::ACCESS    => 'member with accessPrivateChannels',
            self::MODERATOR => 'chat moderator',
            self::ADMIN     => 'administrator',
        ];
    }

    // ── Direction 1: what the listing shows ─────────────────────────────────

    public function test_listing_is_scoped_per_actor(): void
    {
        // A direct channel appears for nobody here: not for the moderator, not
        // for the administrator. Membership is the only key, which is what makes
        // a DM a DM.
        $this->assertSame([self::CH_PUBLIC], $this->listed(self::PLAIN), 'plain member');
        $this->assertSame([self::CH_PUBLIC, self::CH_PRIVATE], $this->listed(self::ACCESS), 'accessPrivateChannels');
        $this->assertSame([self::CH_PUBLIC, self::CH_PRIVATE], $this->listed(self::MODERATOR), 'moderator');
        $this->assertSame(
            [self::CH_PUBLIC, self::CH_PRIVATE, self::CH_RESTRICTED],
            $this->listed(self::ADMIN),
            'administrator'
        );
    }

    // ── Direction 2: fetching one directly ──────────────────────────────────

    public function test_show_agrees_with_the_listing(): void
    {
        $expected = [
            self::PLAIN     => [self::CH_PUBLIC => 200, self::CH_PRIVATE => 404, self::CH_RESTRICTED => 404, self::CH_DIRECT => 404],
            self::ACCESS    => [self::CH_PUBLIC => 200, self::CH_PRIVATE => 200, self::CH_RESTRICTED => 404, self::CH_DIRECT => 404],
            self::MODERATOR => [self::CH_PUBLIC => 200, self::CH_PRIVATE => 200, self::CH_RESTRICTED => 404, self::CH_DIRECT => 404],
            self::ADMIN     => [self::CH_PUBLIC => 200, self::CH_PRIVATE => 200, self::CH_RESTRICTED => 200, self::CH_DIRECT => 404],
        ];

        foreach ($expected as $actor => $channels) {
            foreach ($channels as $channel => $status) {
                $this->assertSame(
                    $status,
                    $this->showStatus($actor, $channel),
                    sprintf('GET channel %d as %s', $channel, self::actors()[$actor])
                );
            }
        }
    }

    // ── Direction 3: letting yourself in ────────────────────────────────────

    public function test_join_matches_what_can_be_seen(): void
    {
        // 204 grants; 404 is the refusal for a channel the actor cannot see.
        //
        // 404 rather than 403, and deliberately: the join route is model-scoped, so
        // the channel is resolved through the same visibility scope the listing
        // uses and is simply not there. A 403 would confirm that a private channel
        // by that id exists, which is the one thing hiding it was for.
        //
        // Note what the grid has none of: a cell that is visible and refused.
        // Seeing a channel and being able to enter it are now the same answer, so
        // there is no state where a member watches a room they cannot join. The
        // structural refusals are asserted separately below.
        $expected = [
            self::PLAIN     => [self::CH_PUBLIC => 204, self::CH_PRIVATE => 404, self::CH_RESTRICTED => 404, self::CH_DIRECT => 404],
            self::ACCESS    => [self::CH_PUBLIC => 204, self::CH_PRIVATE => 204, self::CH_RESTRICTED => 404, self::CH_DIRECT => 404],
            self::MODERATOR => [self::CH_PUBLIC => 204, self::CH_PRIVATE => 204, self::CH_RESTRICTED => 404, self::CH_DIRECT => 404],
            self::ADMIN     => [self::CH_PUBLIC => 204, self::CH_PRIVATE => 204, self::CH_RESTRICTED => 204, self::CH_DIRECT => 404],
        ];

        foreach ($expected as $actor => $channels) {
            foreach ($channels as $channel => $status) {
                $this->assertSame(
                    $status,
                    $this->joinStatus($actor, $channel),
                    sprintf('JOIN channel %d as %s', $channel, self::actors()[$actor])
                );
            }
        }
    }

    public function test_a_direct_channel_cannot_be_joined_even_by_a_participant(): void
    {
        // The one refusal that is not about visibility. User 5 is in the direct
        // channel and can see it, so the model resolves and the policy is actually
        // consulted — and answers no, because a two-person conversation is entered
        // by invitation, never by self-service. This is the cell the grid above
        // cannot contain: visible, and still refused.
        $this->assertSame(403, $this->joinStatus(5, self::CH_DIRECT));
    }

    // ── Direction 4: speaking, once in ──────────────────────────────────────

    public function test_posting_requires_the_join_and_follows_it(): void
    {
        // Each cell is asked twice: before joining and after. Writing needs
        // membership even where reading does not, so the first answer is never a
        // success — and wherever the join is refused, the second answer has to
        // stay refused, or a channel nobody may enter is one anybody may talk in.
        //
        // Two refusal codes, because the channel id arrives in the body rather
        // than the path: an invisible channel fails MessageResource::findChannel
        // as a 422 on `channelId`, and a visible one the actor has not joined
        // fails the policy as a 403. Neither distinguishes "no such channel" from
        // "not for you" in its message.
        $before = [
            self::PLAIN     => [self::CH_PUBLIC => 403, self::CH_PRIVATE => 422, self::CH_RESTRICTED => 422, self::CH_DIRECT => 422],
            self::ACCESS    => [self::CH_PUBLIC => 403, self::CH_PRIVATE => 403, self::CH_RESTRICTED => 422, self::CH_DIRECT => 422],
            self::MODERATOR => [self::CH_PUBLIC => 403, self::CH_PRIVATE => 403, self::CH_RESTRICTED => 422, self::CH_DIRECT => 422],
            self::ADMIN     => [self::CH_PUBLIC => 403, self::CH_PRIVATE => 403, self::CH_RESTRICTED => 403, self::CH_DIRECT => 422],
        ];

        foreach ($before as $actor => $channels) {
            foreach ($channels as $channel => $status) {
                $label = self::actors()[$actor];

                $this->assertSame(
                    $status,
                    $this->postStatus($actor, $channel),
                    sprintf('POST before joining channel %d as %s', $channel, $label)
                );

                $joined = $this->joinStatus($actor, $channel) === 204;

                $this->assertSame(
                    $joined ? 201 : $status,
                    $this->postStatus($actor, $channel),
                    sprintf('POST after %s channel %d as %s', $joined ? 'joining' : 'failing to join', $channel, $label)
                );
            }
        }
    }

    // ── Direction 5: reading what is inside ─────────────────────────────────

    /**
     * The direction that actually carries content, and the one a permission gate
     * on the channel alone would miss: a client does not have to go through
     * `/chat-channels` to reach a message. It can filter the message index by
     * channel id, or ask for a message by its own id, and both have to answer the
     * same way the channel would.
     */
    public function test_messages_are_unreachable_wherever_the_channel_is(): void
    {
        // Message ids are the channel id × 10 — see the fixture.
        $expected = [
            self::PLAIN     => [self::CH_PUBLIC],
            self::ACCESS    => [self::CH_PUBLIC, self::CH_PRIVATE],
            self::MODERATOR => [self::CH_PUBLIC, self::CH_PRIVATE],
            self::ADMIN     => [self::CH_PUBLIC, self::CH_PRIVATE, self::CH_RESTRICTED],
        ];

        foreach ($expected as $actor => $readable) {
            $label = self::actors()[$actor];

            foreach ([self::CH_PUBLIC, self::CH_PRIVATE, self::CH_RESTRICTED, self::CH_DIRECT] as $channel) {
                $allowed = in_array($channel, $readable, true);

                // Filtering the index by a channel the actor cannot see must come
                // back empty rather than with its contents.
                $this->assertSame(
                    $allowed ? ['secret of channel '.$channel] : [],
                    $this->messageContentsIn($actor, $channel),
                    sprintf('LIST messages of channel %d as %s', $channel, $label)
                );

                // And the message cannot be fetched around the filter by its id.
                $this->assertSame(
                    $allowed ? 200 : 404,
                    $this->showMessageStatus($actor, $channel * 10),
                    sprintf('GET message %d as %s', $channel * 10, $label)
                );
            }
        }
    }

    public function test_search_does_not_reach_across_the_scope(): void
    {
        // Full-text search is its own path into the same rows, and the word being
        // searched for appears in every channel's message.
        $this->assertSame(
            ['secret of channel '.self::CH_PUBLIC],
            $this->searchContents(self::PLAIN, 'secret'),
            'a plain member finds only what they could already read'
        );

        $this->assertSame(
            ['secret of channel '.self::CH_PUBLIC, 'secret of channel '.self::CH_PRIVATE],
            $this->searchContents(self::ACCESS, 'secret'),
            'the permission widens search exactly as far as it widens the listing'
        );
    }

    /** @return string[] */
    private function messageContentsIn(int $as, int $channel): array
    {
        // `withQueryParams`, not a query string on the path. The harness builds the
        // request as `new ServerRequest([], [], $path, $method)` and never parses
        // the URI's query — Diactoros does not do it implicitly — so a filter
        // written into the path is silently dropped and the endpoint answers
        // unfiltered. Which looks exactly like a scope leak in the assertion.
        $response = $this->send(
            $this->request('GET', '/api/chat-messages', ['authenticatedAs' => $as])
                ->withQueryParams(['filter' => ['channel' => (string) $channel]])
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->contentsOf($response);
    }

    /** @return string[] */
    private function searchContents(int $as, string $query): array
    {
        $response = $this->send(
            $this->request('GET', '/api/chat-messages', ['authenticatedAs' => $as])
                ->withQueryParams(['filter' => ['q' => $query]])
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $contents = $this->contentsOf($response);
        sort($contents);

        return $contents;
    }

    /** @return string[] */
    private function contentsOf(\Psr\Http\Message\ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return array_values(array_filter(array_map(
            static fn (array $row) => $row['attributes']['content'] ?? null,
            $body['data'] ?? []
        )));
    }

    private function showMessageStatus(int $as, int $message): int
    {
        return $this->send(
            $this->request('GET', '/api/chat-messages/'.$message, ['authenticatedAs' => $as])
        )->getStatusCode();
    }

    // ── Direction 6: the other four ways contents leave a channel ───────────

    /**
     * Threads carry a channel's titles and its reply structure, and they have
     * their own resource, their own filter and their own visibility scope. A
     * channel gate that only covered messages would let the shape of a private
     * conversation out through here.
     */
    public function test_threads_are_unreachable_wherever_the_channel_is(): void
    {
        $expected = [
            self::PLAIN     => [self::CH_PUBLIC],
            self::ACCESS    => [self::CH_PUBLIC, self::CH_PRIVATE],
            self::MODERATOR => [self::CH_PUBLIC, self::CH_PRIVATE],
            self::ADMIN     => [self::CH_PUBLIC, self::CH_PRIVATE, self::CH_RESTRICTED],
        ];

        foreach ($expected as $actor => $readable) {
            $label = self::actors()[$actor];

            // The unfiltered index is the broader question: everything this actor
            // can reach at all, with no channel named.
            $this->assertSame(
                array_map(static fn (int $c) => 'thread of channel '.$c, $readable),
                $this->threadTitles($actor, null),
                sprintf('LIST every thread as %s', $label)
            );

            foreach ([self::CH_PUBLIC, self::CH_PRIVATE, self::CH_RESTRICTED, self::CH_DIRECT] as $channel) {
                $allowed = in_array($channel, $readable, true);

                $this->assertSame(
                    $allowed ? ['thread of channel '.$channel] : [],
                    $this->threadTitles($actor, $channel),
                    sprintf('LIST threads of channel %d as %s', $channel, $label)
                );

                // Thread ids are channel × 10 + 1 — see the fixture.
                $this->assertSame(
                    $allowed ? 200 : 404,
                    $this->send(
                        $this->request('GET', '/api/chat-threads/'.($channel * 10 + 1), ['authenticatedAs' => $actor])
                    )->getStatusCode(),
                    sprintf('GET thread %d as %s', $channel * 10 + 1, $label)
                );
            }
        }
    }

    /**
     * The pinned panel is a second listing of the same rows under a different
     * filter, so it is a second chance to forget the scope.
     */
    public function test_the_pinned_filter_does_not_widen_the_scope(): void
    {
        $expected = [
            self::PLAIN     => [self::CH_PUBLIC],
            self::ACCESS    => [self::CH_PUBLIC, self::CH_PRIVATE],
            self::MODERATOR => [self::CH_PUBLIC, self::CH_PRIVATE],
            self::ADMIN     => [self::CH_PUBLIC, self::CH_PRIVATE, self::CH_RESTRICTED],
        ];

        foreach ($expected as $actor => $readable) {
            $response = $this->send(
                $this->request('GET', '/api/chat-messages', ['authenticatedAs' => $actor])
                    ->withQueryParams(['filter' => ['pinned' => '1']])
            );

            $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

            $contents = $this->contentsOf($response);
            sort($contents);

            $this->assertSame(
                array_map(static fn (int $c) => 'secret of channel '.$c, $readable),
                $contents,
                sprintf('LIST pinned as %s', self::actors()[$actor])
            );
        }
    }

    /**
     * The moderation queue is the one surface gated by a permission of its own,
     * and the interesting question is whether that permission also widens what it
     * shows. It must not: a flag exposes the message it is about, so the queue has
     * to stay inside the channels the moderator could already read.
     */
    public function test_the_flag_queue_is_moderator_only_and_still_channel_scoped(): void
    {
        // Refused at the endpoint rather than scoped to an empty list: the Index
        // is gated by `can('ramon-chat.moderate')` before the query is built, so a
        // member without it is told no instead of being handed nothing. Holding
        // `accessPrivateChannels` changes neither answer — reading a private
        // channel is not moderating it.
        foreach ([self::PLAIN, self::ACCESS] as $actor) {
            $this->assertSame(
                403,
                $this->send($this->request('GET', '/api/chat-message-flags', ['authenticatedAs' => $actor]))
                    ->getStatusCode(),
                sprintf('the queue refuses %s', self::actors()[$actor])
            );
        }

        // A moderator sees the flags of the channels they can read — public and
        // private — and not the one on the tag-restricted channel, which their
        // chat permission does not open.
        $this->assertSame(
            ['flag detail of message 10', 'flag detail of message 20'],
            $this->flagDetails(self::MODERATOR)
        );

        $this->assertSame(
            ['flag detail of message 10', 'flag detail of message 20', 'flag detail of message 30'],
            $this->flagDetails(self::ADMIN)
        );
    }

    /**
     * The member list. Not content, but the answer to "who is in that room", which
     * for a private channel is exactly what being private withholds.
     */
    public function test_the_participant_list_follows_the_channel(): void
    {
        // Asked about the direct channel, whose participants are the only ones
        // this fixture has. Everyone in the matrix is outside it.
        foreach (self::actors() as $actor => $label) {
            $response = $this->send(
                $this->request('GET', '/api/chat-channels/'.self::CH_DIRECT, ['authenticatedAs' => $actor])
                    ->withQueryParams(['include' => 'participants'])
            );

            $this->assertSame(404, $response->getStatusCode(), sprintf('participants of a DM as %s', $label));
        }

        // And from inside it, the same request works — otherwise the assertion
        // above would pass on a broken endpoint rather than on a closed door.
        $response = $this->send(
            $this->request('GET', '/api/chat-channels/'.self::CH_DIRECT, ['authenticatedAs' => 5])
                ->withQueryParams(['include' => 'participants'])
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);
        $usernames = array_map(
            static fn (array $row) => $row['attributes']['username'] ?? null,
            array_values(array_filter($body['included'] ?? [], static fn (array $row) => $row['type'] === 'users'))
        );

        sort($usernames);

        $this->assertSame(['dmalice', 'dmbob'], $usernames);
    }

    /** @return string[] */
    private function threadTitles(int $as, ?int $channel): array
    {
        $request = $this->request('GET', '/api/chat-threads', ['authenticatedAs' => $as]);

        if ($channel !== null) {
            $request = $request->withQueryParams(['filter' => ['channel' => (string) $channel]]);
        }

        $response = $this->send($request);

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);
        $titles = array_values(array_filter(array_map(
            static fn (array $row) => $row['attributes']['title'] ?? null,
            $body['data'] ?? []
        )));

        sort($titles);

        return $titles;
    }

    /** @return string[] */
    private function flagDetails(int $as): array
    {
        $response = $this->send($this->request('GET', '/api/chat-message-flags', ['authenticatedAs' => $as]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);
        $details = array_values(array_filter(array_map(
            static fn (array $row) => $row['attributes']['detail'] ?? null,
            $body['data'] ?? []
        )));

        sort($details);

        return $details;
    }

    // ── The side effects the grid produces ──────────────────────────────────

    public function test_a_join_is_announced_in_the_channel(): void
    {
        $this->assertSame(204, $this->joinStatus(self::PLAIN, self::CH_PUBLIC));

        $this->assertSame(
            ['user_joined'],
            $this->systemKeysIn(self::CH_PUBLIC),
            'a deliberate join announces itself, as a departure does'
        );
    }

    public function test_a_hidden_join_and_its_departure_are_both_silent(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_PUBLIC.'/join', [
                'authenticatedAs' => self::MODERATOR,
                'json'            => ['data' => ['attributes' => ['hidden' => true]]],
            ])
        );

        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([], $this->systemKeysIn(self::CH_PUBLIC), 'a hidden join says nothing');

        $leave = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_PUBLIC.'/leave', ['authenticatedAs' => self::MODERATOR])
        );

        $this->assertSame(204, $leave->getStatusCode(), (string) $leave->getBody());

        // The regression this guards: announcing the exit of someone whose arrival
        // was never announced tells the room, after the fact, that they had been
        // in it — which is the one thing a hidden join exists to prevent.
        $this->assertSame([], $this->systemKeysIn(self::CH_PUBLIC), 'and neither does its ending');
    }

    public function test_a_visible_join_and_departure_are_both_announced(): void
    {
        $this->assertSame(204, $this->joinStatus(self::PLAIN, self::CH_PUBLIC));

        $leave = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_PUBLIC.'/leave', ['authenticatedAs' => self::PLAIN])
        );

        $this->assertSame(204, $leave->getStatusCode(), (string) $leave->getBody());

        $this->assertSame(
            ['user_joined', 'user_left'],
            $this->systemKeysIn(self::CH_PUBLIC),
            'both halves of the same fact are narrated'
        );
    }

    public function test_being_added_by_someone_else_is_announced_as_an_addition(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_PUBLIC.'/members', [
                'authenticatedAs' => self::MODERATOR,
                'json'            => ['data' => ['attributes' => ['userIds' => [self::PLAIN]]]],
            ])
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // Not `user_joined`: the same membership row arrives by two different
        // routes, and narrating both as an arrival credits the added member with
        // a decision that was someone else's.
        $this->assertSame(['user_added'], $this->systemKeysIn(self::CH_PUBLIC));

        // Both names, because the sentence needs both. A row that says only who
        // was added leaves the room unable to tell an invitation from a join.
        $this->assertSame(
            ['actor' => 'moderator', 'username' => 'normal'],
            $this->systemDataIn(self::CH_PUBLIC)
        );
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

    /**
     * The interpolation data of the single system row in a channel.
     *
     * Sorted by key so the assertion states what has to be there rather than the
     * order it happens to be written in.
     *
     * @return array<string, string>
     */
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
}
