<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\MessageWasMoved;
use Ramon\Chat\Message;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * Moves messages to another channel — the moderator action Discourse exposes as
 * "select messages and move them to a different channel".
 *
 * Moving is the one operation that breaks the (channel_id, number) sequence, so
 * each moved message is re-numbered in its destination and both channels have
 * their counters rebuilt from source afterwards.
 */
class MoveMessagesController implements RequestHandlerInterface
{
    protected const MAX_MESSAGES = 100;

    public function __construct(
        protected ConnectionInterface $db,
        protected Events $events,
        protected Translator $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('ramon-chat.moderate');

        $body = $request->getParsedBody();
        $attributes = (array) Arr::get($body, 'data.attributes', []);

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) Arr::get($attributes, 'messageIds', []))
        )));

        if ($ids === []) {
            throw new ValidationException([
                'messageIds' => $this->translator->trans('ramon-chat.api.move_empty'),
            ]);
        }

        if (count($ids) > self::MAX_MESSAGES) {
            throw new ValidationException([
                'messageIds' => $this->translator->trans('ramon-chat.api.move_too_many', [
                    'max' => self::MAX_MESSAGES,
                ]),
            ]);
        }

        /** @var Channel|null $target */
        $target = Channel::query()
            ->whereVisibleTo($actor)
            ->find((int) Arr::get($attributes, 'channelId'));

        if ($target === null) {
            throw new ValidationException([
                'channelId' => $this->translator->trans('ramon-chat.api.channel_not_found'),
            ]);
        }

        if (! $target->acceptsMessages()) {
            throw new ValidationException([
                'channelId' => $this->translator->trans('ramon-chat.api.move_target_frozen'),
            ]);
        }

        if (! $actor->can('postMessage', $target)) {
            throw new ForbiddenException();
        }

        $messages = Message::query()
            ->whereVisibleTo($actor)
            ->whereIn('id', $ids)
            ->with('channel')
            ->orderBy('id')
            ->get()
            ->reject(fn (Message $m) => $m->channel_id === $target->id);

        if ($messages->isEmpty()) {
            throw new ValidationException([
                'messageIds' => $this->translator->trans('ramon-chat.api.move_empty'),
            ]);
        }

        $moved = $this->db->transaction(function () use ($messages, $target, $actor) {
            $affected = [];
            $moved = 0;

            // Take the destination's current high-water mark once, then assign
            // sequential numbers. Doing this in PHP rather than per-row SQL keeps
            // the whole move to one pass and avoids N subqueries.
            $next = (int) Message::query()
                ->where('channel_id', $target->id)
                ->max('number');

            foreach ($messages as $message) {
                $source = $message->channel;

                if ($source !== null) {
                    $affected[$source->id] = $source;
                }

                $message->channel_id = $target->id;
                $message->number = ++$next;

                // A thread belongs to its channel, so a moved message cannot keep
                // its thread membership. Replies become plain messages in the
                // destination rather than dangling into a thread that is no longer
                // reachable from there.
                $message->thread_id = null;

                // Same reasoning for an inline reply pointer whose target stayed
                // behind.
                if ($message->reply_to_id !== null
                    && ! $messages->contains(fn (Message $m) => $m->id === $message->reply_to_id)) {
                    $message->reply_to_id = null;
                }

                $message->save();
                $moved++;

                $this->events->dispatch(new MessageWasMoved($message, $source ?? $target, $target, $actor));
            }

            $affected[$target->id] = $target;

            foreach ($affected as $channel) {
                $channel->refreshMetadata()->save();
            }

            return $moved;
        });

        return new JsonResponse([
            'data' => [
                'type'       => 'chat-message-moves',
                'attributes' => [
                    'moved'     => $moved,
                    'channelId' => $target->id,
                ],
            ],
        ]);
    }
}
