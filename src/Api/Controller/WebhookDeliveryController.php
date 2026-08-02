<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Message;
use Ramon\Chat\Service\UnreadTracker;
use Ramon\Chat\Webhook;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * Slack-compatible incoming webhook.
 *
 * Accepts either a JSON body or a form-encoded `payload` field, matching what
 * Slack-targeting integrations already send, so existing tooling works unchanged.
 *
 * This route is CSRF-exempt (see extend.php) because the delivering service
 * cannot hold a Flarum session. The secret path key is the only credential, which
 * is why it is compared in constant time and never serialised back to clients.
 */
class WebhookDeliveryController implements RequestHandlerInterface
{
    public function __construct(
        protected Events $events,
        protected Translator $translator,
        protected SettingsRepositoryInterface $settings,
        protected UnreadTracker $unread
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Guard the array access: `getAttribute()` returns null on a request that
        // did not pass through ResolveRoute, and `null['key']` would fatal.
        $key = (string) Arr::get((array) $request->getAttribute('routeParameters'), 'key', '');

        $webhook = $this->resolve($key);

        if ($webhook === null) {
            throw new ForbiddenException();
        }

        $channel = $webhook->channel;

        if ($channel === null || ! $channel->acceptsMessages()) {
            throw new ValidationException([
                'channel' => $this->translator->trans('ramon-chat.api.webhook_channel_unavailable'),
            ]);
        }

        $text = $this->extractText($request);

        // The channel's own cap when it has one. A webhook posting into a room
        // that allows longer messages than the forum default was being trimmed
        // to the default; one posting into a room with a tighter cap was not
        // being trimmed at all.
        $max = $channel->maxMessageLength(
            (int) $this->settings->get('ramon-chat.max_message_length', 3000)
        );

        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
        }

        // Webhook messages have no author. `user_id` stays null and the display
        // identity comes from the webhook row, so a deleted admin account never
        // orphans past deliveries.
        $message = new Message();
        $message->channel_id = $channel->id;
        $message->user_id = null;
        $message->webhook_id = $webhook->id;
        $message->type = Message::TYPE_TEXT;
        $message->setContentAttribute($text, null);
        $message->save();

        $channel->last_message_id = $message->id;
        $channel->last_message_at = $message->created_at;
        $channel->messages_count++;
        $channel->save();

        $message->setRelation('channel', $channel);

        // Webhook traffic still creates unread pressure, otherwise an integration
        // channel would never badge.
        $this->unread->recordNewMessage($message);

        $webhook->recordDelivery()->save();

        $this->events->dispatch(new MessageWasSent($message, null));

        return new JsonResponse([
            'data' => [
                'type'       => 'chat-messages',
                'id'         => (string) $message->id,
                'attributes' => ['channelId' => $channel->id],
            ],
        ], 201);
    }

    /**
     * Constant-time key comparison. A plain `where('key', $key)` would be a
     * timing oracle on the index lookup; fetching candidates by prefix and
     * comparing with hash_equals avoids leaking the key one character at a time.
     */
    protected function resolve(string $key): ?Webhook
    {
        if (strlen($key) < 16) {
            return null;
        }

        $candidates = Webhook::query()
            ->where('active', true)
            ->where('key', 'like', substr($key, 0, 8).'%')
            ->with('channel')
            ->get();

        foreach ($candidates as $candidate) {
            if (hash_equals($candidate->key, $key)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    protected function extractText(ServerRequestInterface $request): string
    {
        $body = $request->getParsedBody();

        // Slack posts either a JSON body or `payload=<json>` form-encoded.
        if (is_array($body) && isset($body['payload']) && is_string($body['payload'])) {
            $decoded = json_decode($body['payload'], true);

            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        if (! is_array($body)) {
            $decoded = json_decode((string) $request->getBody(), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $text = (string) (
            Arr::get($body, 'text')
            ?? Arr::get($body, 'data.attributes.text')
            ?? ''
        );

        // Slack "attachments" carry the body when `text` is empty; falling back
        // to them is what makes most off-the-shelf integrations work.
        if (trim($text) === '') {
            $fallbacks = [];

            foreach ((array) Arr::get($body, 'attachments', []) as $attachment) {
                foreach (['fallback', 'text', 'title', 'pretext'] as $field) {
                    $value = Arr::get((array) $attachment, $field);

                    if (is_string($value) && trim($value) !== '') {
                        $fallbacks[] = trim($value);
                        break;
                    }
                }
            }

            $text = implode("\n", $fallbacks);
        }

        $text = trim($text);

        if ($text === '') {
            throw new ValidationException([
                'text' => $this->translator->trans('ramon-chat.api.webhook_empty'),
            ]);
        }

        return $text;
    }
}
