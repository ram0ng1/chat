<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Schedule\CalendarEvents;

/**
 * Eventos do calendário para ligar um canal a um deles, no formulário do canal.
 *
 * Pelo chat, não pela API do calendário: assim o seletor não depende do formato
 * de resposta da outra extensão. Só eventos publicados, que o calendário já mostra
 * a qualquer visitante.
 */
class ListCalendarEventsController implements RequestHandlerInterface
{
    protected const LIMIT = 20;

    public function __construct(
        protected CalendarEvents $calendar
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('useChat');

        $query = trim(mb_substr((string) Arr::get($request->getQueryParams(), 'q', ''), 0, 100));

        return new JsonResponse([
            'data' => $this->calendar->upcoming(Carbon::now(), $query, self::LIMIT),
        ]);
    }
}
