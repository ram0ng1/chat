<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Access;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Mockery;
use Ramon\Chat\Tests\unit\QueryTestCase;
use Ramon\Chat\Access\MessagePolicy;
use Ramon\Chat\Channel;
use Ramon\Chat\Message;

/**
 * The two rules added for pinning and threading.
 *
 * Both return a definite `false` rather than abstaining, which matters: Flarum's Gate
 * applies its isAdmin() fallback only when *no* policy decides, so a policy that
 * abstains hands the decision back to a fallback that tests the ability name rather
 * than the permission name. These assertions pin that behaviour down.
 */
class MessagePolicyTest extends QueryTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    protected function policy(): MessagePolicy
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn(0);

        return new MessagePolicy($settings);
    }

    protected function channel(bool $threading = true): Channel
    {
        $channel = new Channel();
        $channel->setRawAttributes([
            'id'                => 1,
            'type'              => 'category',
            'status'            => 'open',
            'threading_enabled' => $threading,
        ], true);

        return $channel;
    }

    protected function message(array $attributes = [], bool $threading = true): Message
    {
        $message = new Message();
        $message->setRawAttributes(array_merge([
            'id'         => 10,
            'channel_id' => 1,
            'type'       => Message::TYPE_TEXT,
            'content'    => 'hello',
        ], $attributes), true);

        $message->setRelation('channel', $this->channel($threading));

        return $message;
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    protected function actor(array $permissions = [], bool $canPost = true): User
    {
        $user = Mockery::mock(User::class)->makePartial();

        $user->shouldReceive('hasPermission')->andReturnUsing(
            fn (string $permission) => $permissions[$permission] ?? false
        );

        $user->shouldReceive('can')->andReturnUsing(
            fn (string $ability) => $ability === 'postMessage' ? $canPost : ($permissions[$ability] ?? false)
        );

        return $user;
    }

    // ── createThread ───────────────────────────────────────────────────────────

    public function test_thread_can_be_started_with_the_permission(): void
    {
        $this->assertTrue(
            $this->policy()->createThread(
                $this->actor(['ramon-chat.createThread' => true]),
                $this->message()
            )
        );
    }

    public function test_thread_cannot_be_started_without_the_permission(): void
    {
        $this->assertFalse(
            $this->policy()->createThread($this->actor(), $this->message())
        );
    }

    /**
     * The requirement: someone without the permission may still reply — that is a
     * plain postMessage — and is only blocked from *starting* a thread.
     */
    public function test_lacking_the_permission_does_not_affect_replying(): void
    {
        $actor = $this->actor();

        $this->assertFalse($this->policy()->createThread($actor, $this->message()));
        $this->assertTrue($this->policy()->reply($actor, $this->message()));
    }

    public function test_thread_cannot_be_started_where_threading_is_off(): void
    {
        $this->assertFalse(
            $this->policy()->createThread(
                $this->actor(['ramon-chat.createThread' => true]),
                $this->message(threading: false)
            )
        );
    }

    /** Threads are one level deep. */
    public function test_a_message_already_in_a_thread_cannot_be_branched(): void
    {
        $this->assertFalse(
            $this->policy()->createThread(
                $this->actor(['ramon-chat.createThread' => true]),
                $this->message(['thread_id' => 3])
            )
        );
    }

    public function test_a_deleted_message_cannot_be_branched(): void
    {
        $this->assertFalse(
            $this->policy()->createThread(
                $this->actor(['ramon-chat.createThread' => true]),
                $this->message(['deleted_at' => '2026-01-01 00:00:00'])
            )
        );
    }

    // ── pin ────────────────────────────────────────────────────────────────────

    public function test_pinning_requires_its_own_permission(): void
    {
        $this->assertTrue(
            $this->policy()->pin($this->actor(['ramon-chat.pinMessage' => true]), $this->message())
        );

        $this->assertFalse(
            $this->policy()->pin($this->actor(), $this->message())
        );
    }

    /**
     * Pinning is deliberately not folded into `moderate`: moderation removes and
     * moves other people's messages, a pin decides what the channel sees first.
     */
    public function test_moderate_alone_does_not_grant_pinning(): void
    {
        $this->assertFalse(
            $this->policy()->pin($this->actor(['ramon-chat.moderate' => true]), $this->message())
        );
    }

    public function test_system_and_deleted_messages_cannot_be_pinned(): void
    {
        $actor = $this->actor(['ramon-chat.pinMessage' => true]);

        $this->assertFalse($this->policy()->pin($actor, $this->message(['type' => Message::TYPE_SYSTEM])));
        $this->assertFalse($this->policy()->pin($actor, $this->message(['deleted_at' => '2026-01-01 00:00:00'])));
    }

    /**
     * A thread reply is pinnable. It is absent from the channel window, but the
     * pinned list requests `includeThreadReplies`, so the pin stays reachable — and
     * the answer worth pinning is often the one inside the thread.
     */
    public function test_a_message_in_a_thread_can_be_pinned(): void
    {
        $this->assertTrue(
            $this->policy()->pin(
                $this->actor(['ramon-chat.pinMessage' => true]),
                $this->message(['thread_id' => 3])
            )
        );
    }
}
