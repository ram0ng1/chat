<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Realtime;

use Flarum\Queue\RoutingQueue;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\QueueRoutes;
use Illuminate\Queue\SyncQueue;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pusher\Pusher;
use Ramon\Chat\Channel;
use Ramon\Chat\Realtime\ChatBroadcaster;
use Ramon\Chat\Realtime\Job\SendChatEventJob;
use Ramon\Chat\Realtime\QueueKind;

/**
 * Whether a realtime push may leave the request.
 *
 * Pinned here: `sync` and `database` queues (wrapped in core's RoutingQueue or
 * not) always deliver inline, whatever `ramon-chat.queue_realtime` says and
 * however large the audience, because under `database` the worker runs once a
 * minute. Only another driver (a continuous worker) receives the job.
 */
class QueueKindTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_reads_the_driver_behind_the_routing_wrapper(): void
    {
        $sync = new SyncQueue();
        $database = Mockery::mock(DatabaseQueue::class);
        $redis = Mockery::mock(Queue::class);

        $this->assertSame(QueueKind::SYNC, QueueKind::of($sync));
        $this->assertSame(QueueKind::DATABASE, QueueKind::of($database));
        $this->assertSame(QueueKind::DATABASE, QueueKind::of(new RoutingQueue($database, new QueueRoutes())));
        $this->assertSame(QueueKind::OTHER, QueueKind::of($redis));
        $this->assertSame(QueueKind::OTHER, QueueKind::of(new RoutingQueue($redis, new QueueRoutes())));

        $this->assertFalse(QueueKind::defers($sync));
        $this->assertFalse(QueueKind::defers(new RoutingQueue($database, new QueueRoutes())));
        $this->assertTrue(QueueKind::defers(new RoutingQueue($redis, new QueueRoutes())));
    }

    /**
     * @return array<string, array{string, bool, bool, bool}>
     */
    public static function decisions(): array
    {
        return [
            'sync, switch on' => ['sync', true, false, false],
            'sync, large audience' => ['sync', false, true, false],
            'database, switch on' => ['database', true, false, false],
            'database, large audience' => ['database', false, true, false],
            'worker, switch off' => ['other', false, false, false],
            'worker, switch on' => ['other', true, false, true],
            'worker, large audience' => ['other', false, true, true],
        ];
    }

    #[DataProvider('decisions')]
    public function test_the_broadcaster_queues_only_on_a_continuous_worker(
        string $kind,
        bool $switch,
        bool $large,
        bool $expectQueued
    ): void {
        if (! class_exists(Pusher::class)) {
            $this->markTestSkipped('pusher/pusher-php-server is not installed.');
        }

        $driver = match ($kind) {
            'sync' => Mockery::mock(SyncQueue::class),
            'database' => Mockery::mock(DatabaseQueue::class),
            default => Mockery::mock(Queue::class),
        };

        $driver->shouldReceive('push')
            ->times($expectQueued ? 1 : 0)
            ->withArgs(fn ($job) => $job instanceof SendChatEventJob);

        $queue = $kind === 'sync' ? $driver : new RoutingQueue($driver, new QueueRoutes());

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('bound')->with(Pusher::class)->andReturn(true);

        $bus = Mockery::mock(Bus::class);
        $bus->shouldReceive('dispatchNow')
            ->times($expectQueued ? 0 : 1)
            ->with(Mockery::type(SendChatEventJob::class));

        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->with('ramon-chat.queue_realtime')->andReturn($switch ? '1' : '0');

        $broadcaster = new ChatBroadcaster($container, $bus, $queue, $settings, new NullLogger());

        $channel = new Channel();
        $channel->id = 7;

        $broadcaster->toChannelMembers($channel, 'ramonChat.test', ['ok' => true], null, $large);
    }
}
