<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Schedule;

use Carbon\Carbon;

/**
 * Eventos de uma extensão de calendário, vistos só como períodos de tempo. O
 * canal ligado a um evento abre durante cada ocorrência dele.
 */
interface CalendarEvents
{
    /** Se há calendário instalado e ligado. */
    public function available(): bool;

    /**
     * Título do evento publicado, ou null se ele não existe, não está publicado ou
     * não há calendário.
     */
    public function title(int $eventId): ?string;

    /**
     * Ocorrências do evento que tocam [$from, $to], como pares [início, fim) em
     * UTC. Null pelos mesmos motivos de title().
     *
     * @return list<array{0: Carbon, 1: Carbon}>|null
     */
    public function intervals(int $eventId, Carbon $from, Carbon $to): ?array;

    /**
     * Eventos publicados que ainda acontecem a partir de $from, para o seletor do
     * formulário do canal.
     *
     * @return list<array{id: int, title: string, startAt: string, endAt: string|null, allDay: bool, recurring: bool}>
     */
    public function upcoming(Carbon $from, string $query, int $limit): array;
}
