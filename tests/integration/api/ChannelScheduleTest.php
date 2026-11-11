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
use Psr\Http\Message\ResponseInterface;
use Ramon\Chat\Schedule\CalendarEvents;
use Ramon\Chat\Schedule\ErnestDefoeCalendar;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Horário de funcionamento do canal.
 *
 * Fora do horário os membros só leem; admins, moderadores do chat e quem cuida
 * da sala continuam escrevendo. O horário pode ser uma janela, dias da semana ou
 * as ocorrências de um evento do ernestdefoe/calendar, lido pela tabela dele.
 */
class ChannelScheduleTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const MEMBER = 3;
    private const MOD = 4;
    private const CHANNEL = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();
        $user = fn (int $id, string $name) => ['id' => $id, 'username' => $name, 'email' => $name.'@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1];
        $member = fn (int $id) => ['channel_id' => self::CHANNEL, 'user_id' => $id, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now];

        $this->prepareDatabase([
            'users' => [$user(self::MEMBER, 'member'), $user(self::MOD, 'mod')],
            'group_user' => [['user_id' => self::MOD, 'group_id' => Group::MODERATOR_ID]],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MODERATOR_ID, 'permission' => 'ramon-chat.moderate'],
            ],
            'chat_channels' => [
                ['id' => self::CHANNEL, 'type' => 'category', 'name' => 'Live', 'slug' => 'live', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [$member(1), $member(self::MEMBER), $member(self::MOD)],
        ]);
    }

    /** @param array<string, mixed>|null $schedule */
    private function scheduleIs(?array $schedule): void
    {
        $this->database()->table('chat_channels')->where('id', self::CHANNEL)->update(['schedule' => $schedule === null ? null : json_encode($schedule)]);
    }

    /** Janela relativa a agora, em horas. */
    private function window(int $fromHours, int $toHours): array
    {
        return [
            'mode'     => 'window',
            'start'    => Carbon::now()->addHours($fromHours)->utc()->toIso8601ZuluString(),
            'end'      => Carbon::now()->addHours($toHours)->utc()->toIso8601ZuluString(),
            'timezone' => 'UTC',
        ];
    }

    private function say(int $as): ResponseInterface
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        return $this->send($this->request('POST', '/api/chat-messages', [
            'authenticatedAs' => $as,
            'json'            => ['data' => ['type' => 'chat-messages', 'attributes' => ['channelId' => self::CHANNEL, 'content' => 'oi']]],
        ]));
    }

    /** @return array<string, mixed> */
    private function attributes(int $as): array
    {
        $response = $this->send($this->request('GET', '/api/chat-channels/'.self::CHANNEL, ['authenticatedAs' => $as]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data']['attributes'];
    }

    private function edit(mixed $schedule): ResponseInterface
    {
        return $this->send($this->request('PATCH', '/api/chat-channels/'.self::CHANNEL, [
            'authenticatedAs' => 1,
            'json'            => ['data' => ['attributes' => ['schedule' => $schedule]]],
        ]));
    }

    public function test_members_only_read_outside_the_hours(): void
    {
        $this->scheduleIs($this->window(2, 4));

        $this->assertSame(403, $this->say(self::MEMBER)->getStatusCode());

        $attributes = $this->attributes(self::MEMBER);
        $this->assertFalse($attributes['scheduleOpen']);
        $this->assertFalse($attributes['canPostMessage']);
        $this->assertNotNull($attributes['scheduleNextChange'], 'the composer can say when it opens');
    }

    public function test_members_write_inside_the_hours(): void
    {
        $this->scheduleIs($this->window(-1, 1));

        $this->assertSame(201, $this->say(self::MEMBER)->getStatusCode());
        $this->assertTrue($this->attributes(self::MEMBER)['scheduleOpen']);
    }

    public function test_admins_and_moderators_are_not_affected(): void
    {
        $this->scheduleIs($this->window(2, 4));

        $this->assertSame(201, $this->say(1)->getStatusCode());
        $this->assertSame(201, $this->say(self::MOD)->getStatusCode());
    }

    public function test_the_hours_are_saved_from_the_channel_form(): void
    {
        $response = $this->edit(['mode' => 'weekly', 'days' => [5, 1], 'from' => '09:00', 'to' => '18:00', 'timezone' => 'America/Sao_Paulo']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(
            ['mode' => 'weekly', 'days' => [1, 5], 'from' => '09:00', 'to' => '18:00', 'timezone' => 'America/Sao_Paulo'],
            json_decode((string) $this->database()->table('chat_channels')->where('id', self::CHANNEL)->value('schedule'), true)
        );

        // Desligar volta ao sempre aberto.
        $this->assertSame(200, $this->edit(null)->getStatusCode());
        $this->assertNull($this->database()->table('chat_channels')->where('id', self::CHANNEL)->value('schedule'));
    }

    public function test_invalid_hours_are_refused_with_a_reason(): void
    {
        $response = $this->edit(['mode' => 'weekly', 'days' => [], 'from' => '09:00', 'to' => '18:00', 'timezone' => 'UTC']);

        $this->assertSame(422, $response->getStatusCode());

        $error = json_decode((string) $response->getBody(), true)['errors'][0];
        $this->assertSame('/data/attributes/schedule', $error['source']['pointer']);
        $this->assertSame($this->trans('ramon-chat.api.schedule_weekly_days'), $error['detail']);
    }

    public function test_members_cannot_change_the_hours(): void
    {
        $response = $this->send($this->request('PATCH', '/api/chat-channels/'.self::CHANNEL, [
            'authenticatedAs' => self::MEMBER,
            'json'            => ['data' => ['attributes' => ['schedule' => null]]],
        ]));

        $this->assertContains($response->getStatusCode(), [403, 400]);
    }

    public function test_a_calendar_event_opens_the_channel_during_its_occurrences(): void
    {
        $this->withCalendar([
            // Agora, sem fim: dura uma hora, como no .ics do calendário.
            ['id' => 10, 'title' => 'Live de sexta', 'slug' => 'live', 'start_at' => Carbon::now()->subMinutes(30)->utc()->toDateTimeString(), 'end_at' => null, 'all_day' => 0, 'timezone' => 'UTC', 'is_published' => 1],
            // Rascunho: não pode ser escolhido.
            ['id' => 11, 'title' => 'Rascunho', 'slug' => 'draft', 'start_at' => Carbon::now()->addDay()->utc()->toDateTimeString(), 'end_at' => null, 'all_day' => 0, 'timezone' => 'UTC', 'is_published' => 0],
        ]);

        $this->assertSame(200, $this->edit(['mode' => 'event', 'eventId' => 10])->getStatusCode());
        $this->assertSame(422, $this->edit(['mode' => 'event', 'eventId' => 11])->getStatusCode());

        $attributes = $this->attributes(self::MEMBER);
        $this->assertTrue($attributes['scheduleOpen']);
        $this->assertSame('Live de sexta', $attributes['scheduleEventTitle']);
        $this->assertSame(201, $this->say(self::MEMBER)->getStatusCode());

        // O seletor do formulário lista só o publicado.
        $listing = $this->send($this->request('GET', '/api/chat/calendar-events', ['authenticatedAs' => 1]));
        $this->assertSame([10], array_column(json_decode((string) $listing->getBody(), true)['data'], 'id'));
    }

    public function test_an_unpublished_event_closes_the_channel(): void
    {
        $this->withCalendar([
            ['id' => 10, 'title' => 'Live', 'slug' => 'live', 'start_at' => Carbon::now()->subMinutes(30)->utc()->toDateTimeString(), 'end_at' => null, 'all_day' => 0, 'timezone' => 'UTC', 'is_published' => 0],
        ]);
        $this->scheduleIs(['mode' => 'event', 'eventId' => 10]);

        $attributes = $this->attributes(self::MEMBER);
        $this->assertFalse($attributes['scheduleOpen']);
        $this->assertTrue($attributes['scheduleUnavailable']);
    }

    public function test_an_all_day_event_covers_the_whole_day_in_its_time_zone(): void
    {
        $today = Carbon::now('America/Sao_Paulo')->toDateString();

        $this->withCalendar([
            ['id' => 10, 'title' => 'Dia todo', 'slug' => 'dia', 'start_at' => $today.' 00:00:00', 'end_at' => null, 'all_day' => 1, 'timezone' => 'America/Sao_Paulo', 'is_published' => 1],
        ]);
        $this->scheduleIs(['mode' => 'event', 'eventId' => 10]);

        $attributes = $this->attributes(self::MEMBER);
        $this->assertTrue($attributes['scheduleOpen']);
        $this->assertSame(
            Carbon::parse($today, 'America/Sao_Paulo')->addDay()->utc()->toIso8601String(),
            Carbon::parse($attributes['scheduleNextChange'])->utc()->toIso8601String()
        );
    }

    /**
     * Eventos na tabela do ernestdefoe/calendar e o leitor ligado como se a
     * extensão estivesse ativa.
     *
     * @param list<array<string, mixed>> $events
     */
    private function withCalendar(array $events): void
    {
        $container = $this->app()->getContainer();

        // A tabela vem do tests/integration/setup.php; criá-la aqui quebraria a
        // transação do teste no MySQL e no MariaDB.
        $this->assertTrue(
            $this->database()->getSchemaBuilder()->hasTable('calendar_events'),
            'calendar_events is missing: run composer test:setup again'
        );

        $this->database()->table('calendar_events')->insert($events);

        $calendar = new class($container->make(\Flarum\Extension\ExtensionManager::class), $this->database()) extends ErnestDefoeCalendar {
            public function available(): bool
            {
                return true;
            }
        };

        $container->instance(CalendarEvents::class, $calendar);
        $container->forgetInstance(\Ramon\Chat\Schedule\ChannelSchedule::class);
        $container->forgetInstance(\Ramon\Chat\Api\Resource\ChannelResource::class);
    }

    private function trans(string $key): string
    {
        return $this->app()->getContainer()->make(\Flarum\Locale\Translator::class)->trans($key);
    }
}
