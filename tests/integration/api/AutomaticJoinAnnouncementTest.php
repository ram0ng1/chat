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
                ['group_id' => Group::MEMBER_ID, 'permission' => 'startDiscussion'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'discussion.reply'],
            ],
            'tags' => [
                ['id' => 1, 'name' => 'Lounge', 'slug' => 'lounge', 'position' => 0, 'is_restricted' => 0],
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

    /**
     * Built through the API rather than as fixture rows: the listener runs off the
     * `Posted` event, and only the real create path raises it the way a forum does.
     */
    private function startDiscussion(): int
    {
        $response = $this->send(
            $this->request('POST', '/api/discussions', [
                'authenticatedAs' => self::AUTHOR,
                'json'            => ['data' => [
                    'attributes'    => ['title' => 'A thread in the lounge', 'content' => 'opening post'],
                    'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '1']]]],
                ]],
            ])
        );

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
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
        $discussion = $this->startDiscussion();

        $response = $this->send(
            $this->request('POST', '/api/posts', [
                'authenticatedAs' => self::REPLIER,
                'json'            => ['data' => [
                    'attributes'    => ['content' => 'replying here'],
                    'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussion]]],
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
