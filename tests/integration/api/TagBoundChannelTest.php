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
 * Category channels bound to a tag, with flarum/tags actually enabled.
 *
 * This is the branch of ScopeChannelVisibility that no test ever reached: the
 * suite ran with ramon-chat alone, so the `tags` table did not exist and the
 * tag-bound branch was skipped entirely. That is what let a `class_exists()`
 * probe stand in for "is flarum/tags enabled" — true whenever the package sits
 * in vendor/, which said nothing about whether its migrations had run.
 *
 * Both directions are asserted here: that inherited tag permissions actually
 * restrict a channel, and that an archive lands in the bound category.
 *
 * Runs in its own process because `ScopeVisibilityTrait::$visibilityScopers` is a
 * static registry that is appended to on every boot and never cleared. A sibling
 * test class that enables ramon-chat alone leaves its scoper behind, and that
 * scoper — built when tags was unavailable — omits the tag-bound branch, ANDing
 * every tag-bound channel back out of the results. The leak is the harness's, not
 * the forum's: a real request boots one app.
 */
#[RunTestsInSeparateProcesses]
class TagBoundChannelTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt for "too-obscure", the same hash `normalUser()` carries. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    protected function setUp(): void
    {
        parent::setUp();

        // Order matters: tags has to be enabled before the chat reads from it.
        $this->extension('flarum-tags', 'ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'insider', 'password' => self::PASSWORD_HASH, 'email' => 'insider@machine.local', 'is_email_confirmed' => 1],
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Insider', 'name_plural' => 'Insiders'],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 100],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                // The restricted tag opens only to group 100, which is what the
                // bound channel is expected to inherit.
                ['group_id' => 100, 'permission' => 'tag2.viewForum'],
            ],
            'tags' => [
                ['id' => 1, 'name' => 'Lounge', 'slug' => 'lounge', 'position' => 0, 'is_restricted' => 0],
                ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => 1],
            ],
            'chat_channels' => [
                $this->channel(1, 'lounge-chat', 1),
                $this->channel(2, 'staff-chat', 2),
                $this->channel(3, 'open-chat', null),
            ],
        ]);
    }

    private function channel(int $id, string $slug, ?int $tagId): array
    {
        return [
            'id'         => $id,
            'type'       => 'category',
            'name'       => $slug,
            'slug'       => $slug,
            'status'     => 'open',
            'tag_id'     => $tagId,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return int[] */
    private function visibleChannelIds(int $as): array
    {
        $response = $this->send($this->request('GET', '/api/chat-channels', ['authenticatedAs' => $as]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);
        $ids = array_map(static fn (array $row) => (int) $row['id'], $body['data'] ?? []);

        sort($ids);

        return $ids;
    }

    public function test_listing_channels_does_not_error_when_tags_is_enabled(): void
    {
        // The regression this guards: the tag-bound branch builds a subquery
        // against `tags`, and reaching it at all used to depend on a probe that
        // could be true with no such table.
        $this->assertNotEmpty($this->visibleChannelIds(1));
    }

    public function test_a_channel_on_a_restricted_tag_is_hidden(): void
    {
        $this->assertSame([1, 3], $this->visibleChannelIds(2), 'staff-chat inherits the restricted tag');
    }

    public function test_a_channel_on_a_restricted_tag_is_visible_to_the_permitted_group(): void
    {
        $this->assertSame([1, 2, 3], $this->visibleChannelIds(3));
    }

    public function test_an_admin_sees_every_channel(): void
    {
        $this->assertSame([1, 2, 3], $this->visibleChannelIds(1));
    }

    public function test_archiving_files_the_discussion_under_the_bound_tag(): void
    {
        // ChannelPolicy::archive refuses an open channel — archiving is the step
        // after closing, not an alternative to it.
        $this->database()->table('chat_channels')->where('id', 1)->update(['status' => 'closed']);

        $response = $this->send(
            $this->request('POST', '/api/chat-channels/1/archive', [
                'authenticatedAs' => 1,
                'json'            => ['data' => ['attributes' => ['title' => 'Lounge transcript']]],
            ])
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $discussionId = $this->database()->table('chat_channels')->where('id', 1)->value('archived_discussion_id');

        $this->assertNotNull($discussionId, 'the channel records the discussion it archived into');

        // The assertion that `method_exists($discussion, 'tags')` silently broke:
        // flarum/tags registers the relation through resolveRelationUsing(), so
        // the guard was false even with tags enabled and every archive landed
        // under no category at all.
        $this->assertSame(
            [1],
            $this->database()->table('discussion_tag')
                ->where('discussion_id', $discussionId)
                ->pluck('tag_id')
                ->map(static fn ($id) => (int) $id)
                ->all()
        );
    }
}
