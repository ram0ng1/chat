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
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\Locale\Translator;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\MessagesWereMoved;
use Ramon\Chat\Event\MessageWasMoved;
use Ramon\Chat\Message;
use Ramon\Chat\Thread;
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
        // The raw permission, not the Gate: `can()` on a permission name falls
        // through to the Gate's fallback, which any extension's catch-all policy
        // can answer for.
        if (! $actor->hasPermission('ramon-chat.moderate')) {
            throw new PermissionDeniedException();
        }

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

        $bySource = [];
        $leftThreads = [];

        $moved = $this->db->transaction(function () use ($messages, $target, $actor, &$bySource, &$leftThreads) {
            $affected = [];
            $moved = 0;

            // Every thread a moved message belonged to, captured before the loop
            // detaches them. Each keeps pointers (root, last reply) that may now
            // name a message in another channel — one the thread's readers may
            // not be allowed to see — so all of them are put right below.
            $threadIds = $messages->pluck('thread_id')->filter()->unique()->values()->all();
            $movedIds = $messages->pluck('id')->map(fn ($id) => (int) $id)->all();

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
                    $bySource[(int) $source->id][] = (int) $message->id;
                }

                if ($message->thread_id !== null) {
                    $leftThreads[(int) $message->id] = (int) $message->thread_id;
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

            // A thread stays in its channel with its replies; only the root
            // leaves. It goes on without one rather than being taken along: the
            // replies were not selected, and carrying them into the destination
            // would move conversation the moderator never chose. The panel still
            // opens from the threads list and falls back to an untitled label,
            // and the counters are recounted from what is still in it.
            if ($threadIds !== []) {
                foreach (Thread::query()->whereKey($threadIds)->get() as $thread) {
                    if ($thread->original_message_id !== null && in_array((int) $thread->original_message_id, $movedIds, true)) {
                        $thread->original_message_id = null;
                    }

                    $thread->refreshMetadata()->save();
                }
            }

            $affected[$target->id] = $target;

            foreach ($affected as $channel) {
                $channel->refreshMetadata()->save();
            }

            return $moved;
        });

        // After the commit, so a client that refetches on the push reads the
        // rows where they now are.
        $sources = Channel::query()->whereKey(array_keys($bySource))->get()->keyBy('id')->all();

        $this->events->dispatch(new MessagesWereMoved($bySource, $sources, $target, $leftThreads, $actor));

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
