<?php

/*
 * This file is part of ramon/chat.
 *
 * Copyright (c) Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Content;

use Flarum\Api\Client;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Puts everything the chat draws on first paint into the boot payload.
 *
 * Without it a visit to /chat is several round trips deep before anything but a
 * skeleton is on screen: the page mounts, asks for the channel list, and only
 * once that lands does ChannelView mount and ask for the first page of messages,
 * the pinned bar and the drafts. Each of those swaps a placeholder for content,
 * and the result is the staggered flash the chat opened with — the list, then
 * the conversation, then the pinned strip sliding in over it.
 *
 * The same requests are made here instead, server-side, in the process that is
 * already rendering the page, and the results are handed to the client as
 * `app.data.ramonChat`. ChatState reads them synchronously before the first
 * view(), so the chat paints complete. The async paths are untouched: this is an
 * optimisation, and anything missing from the payload — a failed call, a route
 * it does not cover — falls back to them.
 *
 * Registered on the forum frontend as a whole rather than per route, because
 * Extend\Frontend::route() already spends its third argument on
 * RequireChatAccess. The route guard below is what keeps these queries off the
 * rest of the forum — except where the drawer is open.
 *
 * The drawer is the case the route list cannot see. It stays open across the
 * forum, and a reload on any page brings it back — so that page's boot is where
 * the conversation is assembled, over the same round trips /chat used to pay.
 * ChatState mirrors "drawer open, on channel N" into a cookie (see
 * persistDrawerCookie), and when it is present this preloads for that channel
 * on every route. The cookie is absent whenever the drawer is closed, so a
 * reader who is not chatting costs nothing here.
 */
class PreloadChat
{
    /**
     * Set by the client while the drawer is open; carries the open channel's id,
     * or 0 for an open drawer with nothing selected.
     */
    private const DRAWER_COOKIE = 'ramon_chat_drawer';
    /**
     * The routes this runs for.
     *
     * An explicit list rather than a `chat.` prefix test: the prefix is not ours
     * to reserve, and a route another extension happens to name that way would
     * silently start paying for queries it has no use for.
     */
    private const ROUTES = [
        'chat.index',
        'chat.channel',
        'chat.thread',
        'chat.browse',
        'chat.browse.filter',
        'chat.threads',
        'chat.search',
        'chat.bookmarks',
        'chat.flags',
    ];

    /**
     * The routes that put a conversation on screen, and so the only ones worth
     * the message and pin queries.
     *
     * The section routes fill the main pane themselves — see ChatPage::mainPane —
     * so a stream fetched for them would be paid for and never rendered.
     */
    private const CHANNEL_ROUTES = [
        'chat.index',
        'chat.channel',
        'chat.thread',
    ];

    /** Mirrors the page[limit] that ChatState.loadChannels() asks for. */
    private const CHANNEL_LIMIT = 50;

    /** Mirrors PAGE_SIZE in ChatState. */
    private const MESSAGE_LIMIT = 50;

    public function __construct(
        protected Client $api
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $route = (string) $request->getAttribute('routeName');
        $onChatRoute = in_array($route, self::ROUTES, true);
        $drawerChannel = $this->drawerChannel($request);

        if (! $onChatRoute && $drawerChannel === null) {
            return;
        }

        $actor = RequestUtil::getActor($request);

        // RequireChatAccess has already thrown for anyone who fails this, on every
        // route in the list above. Checked again because this callback is attached
        // to the frontend rather than to the routes, so a future route added
        // without the guard must not start leaking channel lists.
        if (! $actor->exists || ! $actor->can('useChat')) {
            return;
        }

        $channels = $this->fetch($request, '/chat-channels', [
            'filter' => ['following' => true],
            'sort' => '-lastMessageAt',
            'page' => ['limit' => self::CHANNEL_LIMIT],
        ]);

        // The list is what everything else is keyed off. Without it there is
        // nothing the client could use, so leave the payload absent entirely and
        // let the normal path run.
        if ($channels === null) {
            return;
        }

        $payload = ['channels' => $channels];

        $drafts = $this->fetch($request, '/chat/drafts', []);

        if ($drafts !== null) {
            $payload['drafts'] = $drafts;
        }

        $channelId = null;

        if (in_array($route, self::CHANNEL_ROUTES, true)) {
            $channelId = $this->activeChannelId($request, $channels, $drawerChannel);
        } elseif (! $onChatRoute && $drawerChannel > 0) {
            $channelId = $drawerChannel;
        }

        if ($channelId !== null) {
            // Sent so the client knows which stream the messages below belong to.
            // It cannot infer it: on /chat with nothing selected the answer is
            // "whichever channel sorted first", which is a decision made here.
            $payload['channelId'] = $channelId;

            $messages = $this->fetch($request, '/chat-messages', [
                'filter' => ['channel' => $channelId],
                'sort' => '-id',
                'page' => ['limit' => self::MESSAGE_LIMIT],
            ]);

            if ($messages !== null) {
                $payload['messages'] = $messages;
            }

            // The pinned bar is the strip that used to slide in over a conversation
            // which had already finished painting, because ChannelView fires this
            // request without awaiting it.
            $pinned = $this->fetch($request, '/chat-messages', [
                'filter' => [
                    'channel' => $channelId,
                    'pinned' => true,
                    'includeThreadReplies' => true,
                ],
                'sort' => '-pinnedAt',
                'page' => ['limit' => 1],
            ]);

            if ($pinned !== null) {
                $payload['pinned'] = $pinned;
            }
        }

        $threadId = $onChatRoute ? $this->threadId($request) : null;

        if ($channelId !== null && $threadId !== null) {
            $payload['threadId'] = $threadId;

            $replies = $this->fetch($request, '/chat-messages', [
                'filter' => ['thread' => $threadId],
                'sort' => '-id',
                'page' => ['limit' => self::MESSAGE_LIMIT],
            ]);

            if ($replies !== null) {
                $payload['threadMessages'] = $replies;
            }
        }

        $document->payload['ramonChat'] = $payload;
    }

    /**
     * Which conversation the page is about to show.
     *
     * Mirrors ChatPage::boot: the id the route names, otherwise the first channel
     * in the list, which is what the page selects on /chat. An id that is not in
     * the list is still returned — a deep link into a channel the sidebar does not
     * carry is the case that benefits most from arriving already loaded.
     *
     * Bare /chat restores the last channel the reader had open, which ChatState
     * keeps in localStorage — invisible here — and mirrors into the drawer
     * cookie. With the cookie the answer is exact; without it the first channel
     * in the list is a guess, and knowingly so: the sort makes it right most of
     * the time, since the last channel read is usually the last one written to,
     * and a miss costs only the stream loading the way it always did.
     * /chat/c/{id}, which is what a reload or a shared link produces, is exact
     * either way.
     *
     * @param array<string, mixed> $channels
     */
    private function activeChannelId(ServerRequestInterface $request, array $channels, ?int $remembered): ?int
    {
        $id = $this->routeParameter($request, 'id');

        if ($id !== null) {
            return $id;
        }

        if ($remembered !== null && $remembered > 0) {
            return $remembered;
        }

        $first = $channels['data'][0]['id'] ?? null;

        return is_numeric($first) && (int) $first > 0 ? (int) $first : null;
    }

    private function threadId(ServerRequestInterface $request): ?int
    {
        return $this->routeParameter($request, 'threadId');
    }

    /**
     * The drawer cookie: null when absent or malformed, otherwise the channel id
     * it carries (0 for an open drawer with nothing selected). A bare digit
     * string is all that is accepted — it is client-written, so nothing else
     * about it is trusted, and the id still goes through the API as the actor.
     */
    private function drawerChannel(ServerRequestInterface $request): ?int
    {
        $value = $request->getCookieParams()[self::DRAWER_COOKIE] ?? null;

        if (! is_string($value) || ! preg_match('/^\d{1,10}$/', $value)) {
            return null;
        }

        return (int) $value;
    }

    private function routeParameter(ServerRequestInterface $request, string $name): ?int
    {
        $parameters = $request->getAttribute('routeParameters');
        $value = is_array($parameters) ? ($parameters[$name] ?? null) : null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * One API call, made as the visitor.
     *
     * `withParentRequest` is what makes the preload honour visibility: every
     * policy, scope and capability flag resolves against the real actor, so this
     * hands the client nothing that the same request over HTTP would not have.
     *
     * A failure returns null rather than propagating. The payload is optional by
     * design — a preload that took the page down with it would be strictly worse
     * than the flash it exists to remove.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetch(ServerRequestInterface $request, string $path, array $params): ?array
    {
        try {
            $body = (string) $this->api
                ->withoutErrorHandling()
                ->withParentRequest($request)
                ->withQueryParams($params)
                ->get($path)
                ->getBody();

            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }
}
