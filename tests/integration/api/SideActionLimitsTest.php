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
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Cache\Repository;
use Ramon\Chat\Message;
use Ramon\Chat\MessageReaction;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * The limits on everything a member can do to a message other than send it.
 *
 * Each of these paths skipped a rule the send path enforces: an edit was not
 * held to the length cap and stayed open to someone who had left the channel;
 * reactions accepted any shortcode-shaped string without bound or throttle; a
 * bookmark name past its column was a 500; a draft had no length at all; and the
 * webhook lookup treated `%` and `_` in the URL as wildcards.
 */
class SideActionLimitsTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const AUTHOR = 3;
    private const LEAVER = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                ['id' => self::AUTHOR, 'username' => 'author', 'email' => 'author@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1],
                ['id' => self::LEAVER, 'username' => 'leaver', 'email' => 'leaver@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'Geral', 'slug' => 'geral', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => self::AUTHOR, 'created_at' => $now],
                ['channel_id' => 1, 'user_id' => self::LEAVER, 'created_at' => $now, 'left_at' => $now],
            ],
            'chat_messages' => [
                ['id' => 1, 'channel_id' => 1, 'user_id' => self::AUTHOR, 'number' => 1, 'type' => 'text', 'content' => '<t>hi</t>', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'channel_id' => 1, 'user_id' => self::LEAVER, 'number' => 2, 'type' => 'text', 'content' => '<t>bye</t>', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_webhooks' => [
                ['id' => 1, 'name' => 'ci', 'channel_id' => 1, 'key' => 'abcdefghABCDEFGH12345678abcdefghABCDEFGH12345678', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    /**
     * Throttle counters live in the cache, which outlives a test; every test
     * starts from an empty one.
     */
    private function boot(): void
    {
        $this->app()->getContainer()->make(Repository::class)->clear();
    }

    private function post(string $path, array $attributes, ?int $as = self::AUTHOR, string $method = 'POST'): int
    {
        return $this->send(
            $this->request($method, $path, [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => $attributes]],
            ])
        )->getStatusCode();
    }

    public function test_an_edit_is_held_to_the_length_cap(): void
    {
        $this->setting('ramon-chat.max_message_length', 10);
        $this->boot();

        $this->assertSame(422, $this->post('/api/chat-messages/1', ['content' => str_repeat('a', 11)], method: 'PATCH'));
        $this->assertSame(200, $this->post('/api/chat-messages/1', ['content' => 'short'], method: 'PATCH'));
    }

    public function test_someone_who_left_cannot_edit_what_they_said(): void
    {
        $this->boot();

        $this->assertSame(403, $this->post('/api/chat-messages/2', ['content' => 'rewritten'], self::LEAVER, 'PATCH'));
        $this->assertStringContainsString('bye', (string) Message::query()->findOrFail(2)->getAttributes()['content']);
    }

    public function test_a_reaction_must_look_like_a_shortcode(): void
    {
        $this->boot();

        $this->assertSame(422, $this->post('/api/chat-messages/1/react', ['emoji' => '<b>x</b>']));
        $this->assertSame(422, $this->post('/api/chat-messages/1/react', ['emoji' => str_repeat('a', 61)]));
        $this->assertSame(200, $this->post('/api/chat-messages/1/react', ['emoji' => ':heart:']));
    }

    public function test_one_person_cannot_pile_reactions_onto_a_message(): void
    {
        $this->boot();

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(200, $this->post('/api/chat-messages/1/react', ['emoji' => 'e'.$i]));
        }

        $this->assertSame(422, $this->post('/api/chat-messages/1/react', ['emoji' => 'one_too_many']));
        $this->assertSame(10, MessageReaction::query()->where('message_id', 1)->count());

        // Taking one back is never refused.
        $this->assertSame(200, $this->post('/api/chat-messages/1/react', ['emoji' => 'e0']));
    }

    public function test_reacting_is_throttled(): void
    {
        $this->boot();

        $statuses = [];

        // More than two windows' worth, so a window boundary falling inside the
        // loop cannot hide the limit.
        for ($i = 0; $i < 40; $i++) {
            $statuses[] = $this->post('/api/chat-messages/1/react', ['emoji' => 'heart']);
        }

        $this->assertContains(429, $statuses);
    }

    public function test_a_long_bookmark_name_is_a_validation_error(): void
    {
        $this->boot();

        $this->assertSame(422, $this->post('/api/chat-messages/1/bookmark', ['name' => str_repeat('n', 201)]));
        $this->assertSame(200, $this->post('/api/chat-messages/1/bookmark', ['name' => 'fine']));
    }

    public function test_a_draft_has_a_length_cap(): void
    {
        $this->setting('ramon-chat.max_message_length', 3000);
        $this->boot();

        $this->assertSame(422, $this->post('/api/chat/drafts', ['channelId' => 1, 'content' => str_repeat('d', 20001)]));
        $this->assertSame(200, $this->post('/api/chat/drafts', ['channelId' => 1, 'content' => 'a draft']));
    }

    public function test_a_wildcard_is_not_a_webhook_key(): void
    {
        $this->boot();

        // Would match the stored key under an unescaped LIKE on its prefix.
        foreach (['abcdefg%xxxxxxxxxxxxx', 'abcdefg_xxxxxxxxxxxxx', '%%%%%%%%%%%%%%%%'] as $key) {
            $this->assertSame(403, $this->post('/api/chat/hooks/'.rawurlencode($key), ['text' => 'hi'], null));
        }

        $this->assertSame(0, Message::query()->whereNotNull('webhook_id')->count());
    }
}
