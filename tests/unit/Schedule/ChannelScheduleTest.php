<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Schedule;

use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramon\Chat\Channel;
use Ramon\Chat\Schedule\CalendarEvents;
use Ramon\Chat\Schedule\ChannelSchedule;
use Ramon\Chat\Schedule\NoCalendar;
use Ramon\Chat\Schedule\OpeningHours;

/**
 * Se o canal está aberto num instante e quando isso muda, em cada modo.
 */
class ChannelScheduleTest extends TestCase
{
    /** @param array<string, mixed>|null $schedule */
    private function channel(?array $schedule): Channel
    {
        $channel = new Channel();
        $channel->setAttribute('schedule', $schedule === null ? null : json_encode($schedule));

        return $channel;
    }

    /** @return array{open: bool, nextChange: Carbon|null, unavailable: bool} */
    private function at(?array $schedule, string $now, ?CalendarEvents $calendar = null): array
    {
        return (new ChannelSchedule($calendar ?? new NoCalendar()))->state($this->channel($schedule), Carbon::parse($now));
    }

    /** @param list<array{0: string, 1: string}> $occurrences */
    private function calendar(array $occurrences, ?string $title = 'Live'): CalendarEvents
    {
        return new class($occurrences, $title) extends NoCalendar {
            /** @param list<array{0: string, 1: string}> $occurrences */
            public function __construct(private array $occurrences, private ?string $name)
            {
            }

            public function available(): bool
            {
                return true;
            }

            public function title(int $eventId): ?string
            {
                return $this->name;
            }

            public function intervals(int $eventId, Carbon $from, Carbon $to): ?array
            {
                if ($this->name === null) {
                    return null;
                }

                return array_map(fn (array $o) => [Carbon::parse($o[0])->utc(), Carbon::parse($o[1])->utc()], $this->occurrences);
            }
        };
    }

    public function test_no_schedule_is_always_open(): void
    {
        $state = $this->at(null, '2026-11-11T03:00:00Z');

        $this->assertTrue($state['open']);
        $this->assertNull($state['nextChange']);
    }

    public function test_a_single_window_opens_and_closes_on_time(): void
    {
        $window = ['mode' => 'window', 'start' => '2026-11-15T22:00:00Z', 'end' => '2026-11-16T01:00:00Z', 'timezone' => 'America/Sao_Paulo'];

        $before = $this->at($window, '2026-11-15T21:59:00Z');
        $this->assertFalse($before['open']);
        $this->assertSame('2026-11-15T22:00:00Z', $before['nextChange']?->toIso8601ZuluString());

        $during = $this->at($window, '2026-11-15T23:00:00Z');
        $this->assertTrue($during['open']);
        $this->assertSame('2026-11-16T01:00:00Z', $during['nextChange']?->toIso8601ZuluString());

        $after = $this->at($window, '2026-11-16T01:00:00Z');
        $this->assertFalse($after['open'], 'the end is exclusive');
        $this->assertNull($after['nextChange'], 'nothing else is scheduled');
    }

    public function test_weekly_hours_follow_the_local_clock(): void
    {
        // Segunda a sexta, das 9 às 18 em São Paulo (UTC-3).
        $weekly = ['mode' => 'weekly', 'days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '18:00', 'timezone' => 'America/Sao_Paulo'];

        // Quarta, 11/11/2026, 10:00 local.
        $open = $this->at($weekly, '2026-11-11T13:00:00Z');
        $this->assertTrue($open['open']);
        $this->assertSame('2026-11-11T21:00:00Z', $open['nextChange']?->toIso8601ZuluString());

        // Sexta 19:00 local: fecha até segunda 9:00.
        $weekend = $this->at($weekly, '2026-11-13T22:00:00Z');
        $this->assertFalse($weekend['open']);
        $this->assertSame('2026-11-16T12:00:00Z', $weekend['nextChange']?->toIso8601ZuluString());
    }

    public function test_weekly_hours_can_cross_midnight(): void
    {
        // Sábado das 22h às 2h de domingo, em UTC.
        $weekly = ['mode' => 'weekly', 'days' => [6], 'from' => '22:00', 'to' => '02:00', 'timezone' => 'UTC'];

        $this->assertTrue($this->at($weekly, '2026-11-15T01:30:00Z')['open'], 'Sunday 01:30 belongs to Saturday night');
        $this->assertFalse($this->at($weekly, '2026-11-15T02:00:00Z')['open']);
        $this->assertFalse($this->at($weekly, '2026-11-14T21:59:00Z')['open']);
    }

    public function test_weekly_hours_keep_the_local_time_across_daylight_saving(): void
    {
        // Nova York sai do horário de verão em 01/11/2026: 9:00 local passa de 13:00
        // para 14:00 UTC.
        $weekly = ['mode' => 'weekly', 'days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '09:00', 'to' => '10:00', 'timezone' => 'America/New_York'];

        $this->assertTrue($this->at($weekly, '2026-10-30T13:30:00Z')['open']);
        $this->assertFalse($this->at($weekly, '2026-11-02T13:30:00Z')['open']);
        $this->assertTrue($this->at($weekly, '2026-11-02T14:30:00Z')['open']);
    }

    public function test_an_event_opens_during_each_occurrence(): void
    {
        $calendar = $this->calendar([
            ['2026-11-12T20:00:00Z', '2026-11-12T22:00:00Z'],
            ['2026-11-19T20:00:00Z', '2026-11-19T22:00:00Z'],
        ]);
        $event = ['mode' => 'event', 'eventId' => 7];

        $this->assertTrue($this->at($event, '2026-11-12T21:00:00Z', $calendar)['open']);

        $between = $this->at($event, '2026-11-13T12:00:00Z', $calendar);
        $this->assertFalse($between['open']);
        $this->assertSame('2026-11-19T20:00:00Z', $between['nextChange']?->toIso8601ZuluString());
    }

    public function test_touching_periods_do_not_close_in_between(): void
    {
        $calendar = $this->calendar([
            ['2026-11-12T20:00:00Z', '2026-11-12T22:00:00Z'],
            ['2026-11-12T18:00:00Z', '2026-11-12T20:00:00Z'],
        ]);

        $state = $this->at(['mode' => 'event', 'eventId' => 7], '2026-11-12T19:00:00Z', $calendar);

        $this->assertTrue($state['open']);
        $this->assertSame('2026-11-12T22:00:00Z', $state['nextChange']?->toIso8601ZuluString());
    }

    public function test_a_vanished_event_keeps_the_channel_closed(): void
    {
        $state = $this->at(['mode' => 'event', 'eventId' => 7], '2026-11-12T19:00:00Z', $this->calendar([], null));

        $this->assertFalse($state['open']);
        $this->assertTrue($state['unavailable']);
    }

    public function test_input_is_normalized_and_checked(): void
    {
        $calendar = new NoCalendar();

        $this->assertNull(OpeningHours::normalize(null, $calendar));
        $this->assertNull(OpeningHours::normalize(['mode' => 'always'], $calendar));

        $this->assertSame(
            ['mode' => 'window', 'start' => '2026-11-15T22:00:00Z', 'end' => '2026-11-16T01:00:00Z', 'timezone' => 'America/Sao_Paulo'],
            OpeningHours::normalize(['mode' => 'window', 'start' => '2026-11-15T19:00-03:00', 'end' => '2026-11-15T22:00:00-03:00', 'timezone' => 'America/Sao_Paulo'], $calendar)
        );

        $this->assertSame(
            ['mode' => 'weekly', 'days' => [1, 5], 'from' => '09:00', 'to' => '18:00', 'timezone' => 'UTC'],
            OpeningHours::normalize(['mode' => 'weekly', 'days' => [5, 1, 5], 'from' => '09:00', 'to' => '18:00', 'timezone' => 'UTC'], $calendar)
        );
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function invalidInput(): array
    {
        return [
            'end before start'   => [['mode' => 'window', 'start' => '2026-11-15T22:00:00Z', 'end' => '2026-11-15T21:00:00Z', 'timezone' => 'UTC'], 'schedule_window_order'],
            'time without zone'  => [['mode' => 'window', 'start' => '2026-11-15T22:00', 'end' => '2026-11-15T23:00', 'timezone' => 'UTC'], 'schedule_invalid'],
            'unknown timezone'   => [['mode' => 'weekly', 'days' => [1], 'from' => '09:00', 'to' => '10:00', 'timezone' => 'Mars/Olympus'], 'schedule_timezone'],
            'no days'            => [['mode' => 'weekly', 'days' => [], 'from' => '09:00', 'to' => '10:00', 'timezone' => 'UTC'], 'schedule_weekly_days'],
            'day out of range'   => [['mode' => 'weekly', 'days' => [8], 'from' => '09:00', 'to' => '10:00', 'timezone' => 'UTC'], 'schedule_weekly_days'],
            'bad clock'          => [['mode' => 'weekly', 'days' => [1], 'from' => '9h', 'to' => '10:00', 'timezone' => 'UTC'], 'schedule_weekly_hours'],
            'empty hours'        => [['mode' => 'weekly', 'days' => [1], 'from' => '09:00', 'to' => '09:00', 'timezone' => 'UTC'], 'schedule_weekly_hours'],
            'no calendar'        => [['mode' => 'event', 'eventId' => 3], 'schedule_event_missing'],
            'unknown mode'       => [['mode' => 'moon'], 'schedule_invalid'],
            'not an object'      => ['always', 'schedule_invalid'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_names_the_problem(mixed $input, string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($key);

        OpeningHours::normalize($input, new NoCalendar());
    }
}
