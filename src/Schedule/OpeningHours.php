<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Schedule;

use Carbon\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Quando um canal aceita mensagens. Três modos, guardados em JSON no canal:
 *
 * - `window`: uma janela só, `start` e `end` em UTC.
 * - `weekly`: dias da semana (1 = segunda … 7 = domingo) e horário `from`–`to`
 *   no fuso escolhido. `to` antes de `from` atravessa a meia-noite.
 * - `event`: as ocorrências de um evento do calendário.
 *
 * O fuso fica guardado para o horário semanal valer no relógio local (inclusive
 * no horário de verão) e para o formulário mostrar a janela como foi escrita.
 */
class OpeningHours
{
    public const MODE_WINDOW = 'window';
    public const MODE_WEEKLY = 'weekly';
    public const MODE_EVENT = 'event';

    /**
     * Valida o que veio do formulário e devolve a forma guardada; null desliga o
     * horário. Lança InvalidArgumentException com a chave de tradução do erro.
     *
     * @return array<string, mixed>|null
     */
    public static function normalize(mixed $input, CalendarEvents $calendar): ?array
    {
        if ($input === null || $input === [] || $input === '') {
            return null;
        }

        if (! is_array($input)) {
            throw new InvalidArgumentException('schedule_invalid');
        }

        $mode = $input['mode'] ?? null;

        if ($mode === null || $mode === 'always') {
            return null;
        }

        if ($mode === self::MODE_WINDOW) {
            $zone = self::zone($input['timezone'] ?? null);
            $start = self::instant($input['start'] ?? null);
            $end = self::instant($input['end'] ?? null);

            if ($end <= $start) {
                throw new InvalidArgumentException('schedule_window_order');
            }

            return [
                'mode'     => self::MODE_WINDOW,
                'start'    => $start->toIso8601ZuluString(),
                'end'      => $end->toIso8601ZuluString(),
                'timezone' => $zone,
            ];
        }

        if ($mode === self::MODE_WEEKLY) {
            $days = array_values(array_unique(array_map('intval', (array) ($input['days'] ?? []))));
            sort($days);

            if ($days === [] || min($days) < 1 || max($days) > 7) {
                throw new InvalidArgumentException('schedule_weekly_days');
            }

            $from = self::clock($input['from'] ?? null);
            $to = self::clock($input['to'] ?? null);

            if ($from === $to) {
                throw new InvalidArgumentException('schedule_weekly_hours');
            }

            return [
                'mode'     => self::MODE_WEEKLY,
                'days'     => $days,
                'from'     => $from,
                'to'       => $to,
                'timezone' => self::zone($input['timezone'] ?? null),
            ];
        }

        if ($mode === self::MODE_EVENT) {
            $eventId = (int) ($input['eventId'] ?? 0);

            if ($eventId <= 0 || $calendar->title($eventId) === null) {
                throw new InvalidArgumentException('schedule_event_missing');
            }

            return ['mode' => self::MODE_EVENT, 'eventId' => $eventId];
        }

        throw new InvalidArgumentException('schedule_invalid');
    }

    /**
     * Períodos de abertura que tocam [$from, $to], ordenados, como pares
     * [início, fim) em UTC. Null quando não dá para saber, como um evento que
     * sumiu: o canal conta como fechado.
     *
     * @param  array<string, mixed>  $schedule
     * @return list<array{0: Carbon, 1: Carbon}>|null
     */
    public static function intervals(array $schedule, Carbon $from, Carbon $to, CalendarEvents $calendar): ?array
    {
        switch ($schedule['mode'] ?? null) {
            case self::MODE_WINDOW:
                $start = Carbon::parse((string) $schedule['start'])->utc();
                $end = Carbon::parse((string) $schedule['end'])->utc();

                return ($end > $from && $start < $to) ? [[$start, $end]] : [];

            case self::MODE_WEEKLY:
                return self::weekly($schedule, $from, $to);

            case self::MODE_EVENT:
                $intervals = $calendar->intervals((int) $schedule['eventId'], $from, $to);

                if ($intervals === null) {
                    return null;
                }

                usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);

                return $intervals;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    protected static function weekly(array $schedule, Carbon $from, Carbon $to): array
    {
        $zone = (string) $schedule['timezone'];
        $days = array_map('intval', (array) $schedule['days']);
        $intervals = [];

        // Um dia antes de $from: a janela de ontem pode atravessar a meia-noite.
        $day = $from->copy()->setTimezone($zone)->startOfDay()->subDay();
        $last = $to->copy()->setTimezone($zone)->startOfDay();

        while ($day <= $last) {
            if (in_array($day->isoWeekday(), $days, true)) {
                $start = $day->copy()->setTimeFromTimeString((string) $schedule['from']);
                $end = $day->copy()->setTimeFromTimeString((string) $schedule['to']);

                if ($end <= $start) {
                    $end = $day->copy()->addDay()->setTimeFromTimeString((string) $schedule['to']);
                }

                $start->utc();
                $end->utc();

                if ($end > $from && $start < $to) {
                    $intervals[] = [$start, $end];
                }
            }

            $day->addDay();
        }

        return $intervals;
    }

    protected static function zone(mixed $value): string
    {
        if (is_string($value) && in_array($value, timezone_identifiers_list(), true)) {
            return $value;
        }

        throw new InvalidArgumentException('schedule_timezone');
    }

    /** Data e hora com fuso explícito (o formulário manda ISO 8601). */
    protected static function instant(mixed $value): Carbon
    {
        if (! is_string($value) || ! preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw new InvalidArgumentException('schedule_invalid');
        }

        try {
            return Carbon::parse($value)->utc()->startOfMinute();
        } catch (Throwable) {
            throw new InvalidArgumentException('schedule_invalid');
        }
    }

    protected static function clock(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw new InvalidArgumentException('schedule_weekly_hours');
        }

        return $value;
    }
}
