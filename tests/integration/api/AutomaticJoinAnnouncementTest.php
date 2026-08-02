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
 * The join that is a side effect rather than an arrival.
 *
 * `auto_join_on_reply` puts whoever replies in a category into the channel bound
 * to it. Announcing those is what made category joins go unannounced entirely for
 * a release: on a busy forum every first reply would become a system row and bury
 * the conversation. The fix was to tell the two kinds of join apart, so this
 * asserts both halves — the membership is still created, and nothing is narrated.
 *
 * Its own class because the fixture is a different shape: a tag, a discussion and
 * a reply, none of which the access matrix needs.
 *
 * Separate process for the reason TagBoundChannelTest documents — the visibility
 * scoper registry is static and survives between boots.
 */
#[RunTestsInSeparateProcesses]
class AutomaticJoinAnnouncementTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt for "too-obscure", the same hash `normalUser()` carries. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const REPLIER = 2;
    private const AUTHOR = 3;
    private const CHANNEL = 1;
    private const DISCUSSION = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                [
                    'id'                 => self::AUTHOR,
                    'username'           => 'author',
                    'password'           => self::PASSWORD_HASH,
                    'email'              => 'author@machine.local',
                    'is_email_confirmed' => 1,
                ],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'discussion.reply'],
            ],
            'tags' => [
                ['id' => 1, 'name' => 'Lounge', 'slug' => 'lounge', 'position' => 0, 'is_restricted' => 0],
            ],
            // The discussion is fixture rows, not an API call.
            //
            // Creating it through `POST /api/discussions` drags in flarum/tags'
            // `validateTagCount()`, which builds a `size:$min` rule out of the
            // `flarum-tags.min_*_tags` settings. On a database that has never had
            // those written they are null, the rule becomes `size:`, and Brick\Math
            // rejects the empty number with a 500 — a failure about tag settings,
            // in a test about chat announcements. It reproduces only on a fresh
            // database, which is why it passed locally and failed in CI.
            //
            // Only the reply below needs to be real: it is what raises `Posted`,
            // and `Posted` is what JoinChannelsOnReply listens to.
            'discussions' => [
                [
                    'id'            => self::DISCUSSION,
                    'title'         => 'A thread in the lounge',
                    'slug'          => 'a-thread-in-the-lounge',
                    'user_id'       => self::AUTHOR,
                    'first_post_id' => 1,
                    'comment_count' => 1,
                    'created_at'    => Carbon::now()->toDateTimeString(),
                    'is_private'    => 0,
                ],
            ],
            'discussion_tag' => [
                ['discussion_id' => self::DISCUSSION, 'tag_id' => 1],
            ],
            'posts' => [
                [
                    'id'            => 1,
                    'discussion_id' => self::DISCUSSION,
                    'number'        => 1,
                    'user_id'       => self::AUTHOR,
                    'type'          => 'comment',
                    'content'       => '<t><p>opening post</p></t>',
                    'created_at'    => Carbon::now()->toDateTimeString(),
                    'is_private'    => 0,
                ],
            ],
            'chat_channels' => [
                [
                    'id'                 => self::CHANNEL,
                    'type'               => 'category',
                    'name'               => 'lounge-chat',
                    'slug'               => 'lounge-chat',
                    'status'             => 'open',
                    'tag_id'             => 1,
                    'is_private'         => 0,
                    'auto_join_on_reply' => 1,
                    'created_at'         => Carbon::now()->toDateTimeString(),
                    'updated_at'         => Carbon::now()->toDateTimeString(),
                ],
            ],
        ]);
    }

    /** @return string[] */
    private function systemKeys(): array
    {
        return $this->database()->table('chat_messages')
            ->where('channel_id', self::CHANNEL)
            ->where('type', 'system')
            ->orderBy('id')
            ->pluck('system_key')
            ->all();
    }

    private function isMember(int $userId): bool
    {
        return $this->database()->table('chat_channel_user')
            ->where('channel_id', self::CHANNEL)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();
    }

    public function test_replying_joins_the_bound_channel_without_announcing_it(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/posts', [
                'authenticatedAs' => self::REPLIER,
                'json'            => ['data' => [
                    'attributes'    => ['content' => 'replying here'],
                    'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) self::DISCUSSION]]],
                ]],
            ])
        );

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $this->assertTrue($this->isMember(self::REPLIER), 'the reply still puts them in the channel');
        $this->assertSame([], $this->systemKeys(), 'and nothing is narrated about it');
    }

    public function test_a_deliberate_join_in_the_same_channel_is_still_announced(): void
    {
        // The control. Same channel, same actor, same membership table — the only
        // difference is that this time the user asked. If both were silent the test
        // above would pass for the wrong reason.
        $response = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/join', ['authenticatedAs' => self::REPLIER])
        );

        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        $this->assertTrue($this->isMember(self::REPLIER));
        $this->assertSame(['user_joined'], $this->systemKeys());
    }
}
