<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Schedule;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Lê os eventos do ernestdefoe/calendar.
 *
 * Pela tabela, não pelas classes dele: o chat não depende do código da outra
 * extensão. A recorrência é a única exceção, porque refazer o expansor de RRULE
 * aqui divergiria do que o calendário mostra; sem a classe, só a primeira
 * ocorrência conta.
 *
 * Mesmas regras do calendário: datas em UTC, evento sem fim dura uma hora (como
 * no .ics dele) e evento de dia inteiro guarda a data à meia-noite UTC, contada
 * como o dia inteiro no fuso do evento.
 */
class ErnestDefoeCalendar implements CalendarEvents
{
    public const EXTENSION = 'ernestdefoe-calendar';

    protected const EXPANDER = 'ErnestDefoe\\Calendar\\Recurrence\\RecurrenceExpander';

    /** Validade do memo: a classe pode viver entre requests e o evento ser editado. */
    protected const MEMO_SECONDS = 30;

    /** @var array<int, array{at: int, row: object|null}> */
    protected array $rows = [];

    public function __construct(
        protected ExtensionManager $extensions,
        protected ConnectionInterface $db
    ) {
    }

    public function available(): bool
    {
        return $this->extensions->isEnabled(self::EXTENSION);
    }

    public function title(int $eventId): ?string
    {
        $row = $this->row($eventId);

        return $row === null ? null : (string) $row->title;
    }

    public function intervals(int $eventId, Carbon $from, Carbon $to): ?array
    {
        $row = $this->row($eventId);

        if ($row === null) {
            return null;
        }

        [$start, $end] = $this->span($row);
        $length = $start->diffInSeconds($end);

        $starts = [$start];

        if (! empty($row->rrule) && class_exists(self::EXPANDER)) {
            try {
                // Recua a duração: uma ocorrência que começou antes de $from ainda pode
                // estar acontecendo.
                $starts = call_user_func([self::EXPANDER, 'occurrences'], (string) $row->rrule, $start, $from->copy()->subSeconds((int) $length), $to);
            } catch (Throwable) {
                $starts = [$start];
            }
        }

        $intervals = [];

        foreach ($starts as $occurrence) {
            $begin = Carbon::instance($occurrence)->utc();
            $finish = $begin->copy()->addSeconds((int) $length);

            if ($finish > $from && $begin < $to) {
                $intervals[] = [$begin, $finish];
            }
        }

        return $intervals;
    }

    public function upcoming(Carbon $from, string $query, int $limit): array
    {
        if (! $this->available()) {
            return [];
        }

        $rows = $this->db->table('calendar_events')
            ->where('is_published', true)
            // Os que ainda acontecem: futuros, em andamento ou recorrentes. Sem fim, um
            // evento dura uma hora e um de dia inteiro, o dia: um dia de folga cobre os dois.
            ->where(function ($q) use ($from) {
                $q->where('start_at', '>=', $from->copy()->subDay())
                    ->orWhere('end_at', '>=', $from)
                    ->orWhereNotNull('rrule');
            })
            ->when($query !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->orderBy('start_at')
            ->limit($limit)
            ->get(['id', 'title', 'start_at', 'end_at', 'all_day', 'rrule']);

        $events = [];

        foreach ($rows as $row) {
            $events[] = [
                'id'        => (int) $row->id,
                'title'     => (string) $row->title,
                'startAt'   => Carbon::parse($row->start_at, 'UTC')->toIso8601ZuluString(),
                'endAt'     => $row->end_at ? Carbon::parse($row->end_at, 'UTC')->toIso8601ZuluString() : null,
                'allDay'    => (bool) $row->all_day,
                'recurring' => ! empty($row->rrule),
            ];
        }

        return $events;
    }

    /** O evento publicado; uma página com vários canais lê cada um uma vez. */
    protected function row(int $eventId): ?object
    {
        $memo = $this->rows[$eventId] ?? null;

        if ($memo === null || time() - $memo['at'] >= self::MEMO_SECONDS) {
            $memo = $this->rows[$eventId] = [
                'at'  => time(),
                'row' => $this->available()
                    ? $this->db->table('calendar_events')->where('id', $eventId)->where('is_published', true)->first()
                    : null,
            ];
        }

        return $memo['row'];
    }

    /**
     * Início e fim da primeira ocorrência, em UTC.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function span(object $row): array
    {
        $start = Carbon::parse($row->start_at, 'UTC');
        $end = $row->end_at ? Carbon::parse($row->end_at, 'UTC') : null;

        if ($row->all_day) {
            $zone = $this->zone((string) ($row->timezone ?? 'UTC'));
            $first = Carbon::parse($start->toDateString(), $zone)->startOfDay();
            $last = Carbon::parse(($end ?? $start)->toDateString(), $zone)->startOfDay()->addDay();

            return [$first->utc(), $last->utc()];
        }

        return [$start, ($end !== null && $end > $start) ? $end : $start->copy()->addHour()];
    }

    protected function zone(string $name): string
    {
        return in_array($name, timezone_identifiers_list(), true) ? $name : 'UTC';
    }
}
