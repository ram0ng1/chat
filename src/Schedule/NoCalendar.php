<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Schedule;

use Carbon\Carbon;

/** Sem extensão de calendário: nenhum evento existe. */
class NoCalendar implements CalendarEvents
{
    public function available(): bool
    {
        return false;
    }

    public function title(int $eventId): ?string
    {
        return null;
    }

    public function intervals(int $eventId, Carbon $from, Carbon $to): ?array
    {
        return null;
    }

    public function upcoming(Carbon $from, string $query, int $limit): array
    {
        return [];
    }
}
