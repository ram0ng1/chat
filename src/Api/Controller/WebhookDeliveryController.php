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
use Flarum\Post\Exception\FloodingException;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Message;
use Ramon\Chat\Service\ActionThrottle;
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
    protected const MAX_PER_IP_PER_MINUTE = 120;

    protected const MAX_PER_WEBHOOK_PER_MINUTE = 60;

    public function __construct(
        protected Events $events,
        protected Translator $translator,
        protected SettingsRepositoryInterface $settings,
        protected UnreadTracker $unread,
        protected ActionThrottle $throttle
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Guard the array access: `getAttribute()` returns null on a request that
        // did not pass through ResolveRoute, and `null['key']` would fatal.
        $key = (string) Arr::get((array) $request->getAttribute('routeParameters'), 'key', '');

        // The admin switch for the whole feature. Refused before the key is
        // even looked at, so a valid URL is no help while webhooks are off.
        if (! (bool) $this->settings->get('ramon-chat.webhooks_enabled', true)) {
            throw new ForbiddenException();
        }

        // Per address, before the key is looked up: this route is unauthenticated,
        // so the only thing standing between a scanner and an unbounded run of
        // key lookups is how often one address may ask.
        $ip = (string) ($request->getAttribute('ipAddress') ?? Arr::get($request->getServerParams(), 'REMOTE_ADDR', ''));

        if (! $this->throttle->attempt('webhook.ip.'.sha1($ip), self::MAX_PER_IP_PER_MINUTE, 60)) {
            throw new FloodingException();
        }

        $webhook = $this->resolve($key);

        if ($webhook === null) {
            throw new ForbiddenException();
        }

        // And per webhook, so one leaked key cannot flood its channel faster than
        // a chat-bound integration has any reason to post.
        if (! $this->throttle->attempt('webhook.hook.'.$webhook->id, self::MAX_PER_WEBHOOK_PER_MINUTE, 60)) {
            throw new FloodingException();
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

        // Posted as the admin account chosen on the webhook, or as the bot. A bot
        // message has no `user_id`; the client draws its name and avatar from the
        // bot settings. Storing it as an authorless *text* message instead is what
        // made deliveries render as a deleted account.
        $author = $webhook->author();

        $message = $author !== null
            ? Message::build($channel, $author, $text)
            : Message::buildBot($channel, null, $text);

        $message->webhook_id = $webhook->id;
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

        $this->events->dispatch(new MessageWasSent($message, $author));

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
        // Keys are minted by Str::random, so alphanumeric only. Anything else is
        // not a key and never reaches the database.
        if (! preg_match('/\A[A-Za-z0-9]{16,128}\z/', $key)) {
            return null;
        }

        // The prefix is escaped: it comes from the URL, and an unescaped `%` or
        // `_` turned the lookup into a wildcard match over every active key,
        // which both widens the scan and leaks how many keys share a pattern
        // through the comparison time.
        $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], substr($key, 0, 8));

        $candidates = Webhook::query()
            ->where('active', true)
            ->where('key', 'like', $prefix.'%')
            ->with(['channel', 'user.groups'])
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
