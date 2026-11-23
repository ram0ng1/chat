<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Schedule;

use Carbon\Carbon;
use Ramon\Chat\Channel;

/**
 * Se o canal está no horário agora e quando isso muda.
 *
 * Calculado na hora, sem tarefa agendada: nenhum cron vira o status e nada
 * atrasa se o agendador do fórum não roda. O cliente recebe a próxima mudança e
 * busca o canal de novo quando ela chega.
 */
class ChannelSchedule
{
    /** Quanto olhar para trás: uma ocorrência longa que já começou ainda conta. */
    protected const LOOKBEHIND_DAYS = 31;

    /** Até onde procurar a próxima abertura. */
    protected const HORIZON_DAYS = 366;

    /**
     * Memo para uma página com vários canais. Com validade curta: o serviço pode
     * viver entre requests num processo persistente, e o horário muda sozinho.
     */
    protected const MEMO_SECONDS = 30;

    /** @var array<int, array{at: int, state: array{open: bool, nextChange: Carbon|null, unavailable: bool}}> */
    protected array $states = [];

    public function __construct(
        protected CalendarEvents $calendar
    ) {
    }

    /**
     * O horário guardado, decodificado; null é sempre aberto.
     *
     * @return array<string, mixed>|null
     */
    public static function of(Channel $channel): ?array
    {
        $raw = $channel->getAttribute('schedule');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function isOpen(Channel $channel): bool
    {
        return $this->state($channel)['open'];
    }

    /**
     * `open`: dentro do horário. `nextChange`: quando abre ou fecha a seguir (null
     * se nada mais está marcado). `unavailable`: o evento ligado sumiu, então o
     * canal fica fechado até alguém trocar o horário.
     *
     * `now` só é passado pelos testes.
     *
     * @return array{open: bool, nextChange: Carbon|null, unavailable: bool}
     */
    public function state(Channel $channel, ?Carbon $now = null): array
    {
        $key = (int) $channel->id;
        $memo = $this->states[$key] ?? null;

        if ($now === null && $memo !== null && $channel->exists && time() - $memo['at'] < self::MEMO_SECONDS
            && ($memo['state']['nextChange'] === null || $memo['state']['nextChange']->isFuture())) {
            return $memo['state'];
        }

        $state = $this->compute($channel, $now ?? Carbon::now());

        if ($now === null && $channel->exists) {
            $this->states[$key] = ['at' => time(), 'state' => $state];
        }

        return $state;
    }

    /** Esquece o memo depois de mudar o horário, para a resposta já ver o novo. */
    public function forget(Channel $channel): void
    {
        unset($this->states[(int) $channel->id]);
    }

    /** @return array{open: bool, nextChange: Carbon|null, unavailable: bool} */
    protected function compute(Channel $channel, Carbon $now): array
    {
        $schedule = self::of($channel);

        if ($schedule === null) {
            return ['open' => true, 'nextChange' => null, 'unavailable' => false];
        }

        $now = $now->copy()->utc();
        $intervals = OpeningHours::intervals(
            $schedule,
            $now->copy()->subDays(self::LOOKBEHIND_DAYS),
            $now->copy()->addDays(self::HORIZON_DAYS),
            $this->calendar
        );

        if ($intervals === null) {
            return ['open' => false, 'nextChange' => null, 'unavailable' => true];
        }

        foreach ($this->merge($intervals) as [$start, $end]) {
            if ($end <= $now) {
                continue;
            }

            // O primeiro período que ainda não acabou: ou estamos dentro dele, ou é a
            // próxima abertura.
            return $start <= $now
                ? ['open' => true, 'nextChange' => $end, 'unavailable' => false]
                : ['open' => false, 'nextChange' => $start, 'unavailable' => false];
        }

        return ['open' => false, 'nextChange' => null, 'unavailable' => false];
    }

    /**
     * Junta períodos que se tocam: um evento das 18 às 20 seguido de outro das 20
     * às 22 é um período só, sem fechar às 20.
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $intervals
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    protected function merge(array $intervals): array
    {
        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][1]) {
                if ($end > $merged[$last][1]) {
                    $merged[$last][1] = $end;
                }

                continue;
            }

            $merged[] = [$start, $end];
        }

        return $merged;
    }
}
