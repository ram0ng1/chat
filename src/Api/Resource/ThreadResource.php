<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Locale\Translator;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Ramon\Chat\Event\ThreadWasEdited;
use Ramon\Chat\Service\UnreadTracker;
use Ramon\Chat\Thread;
use Ramon\Chat\ThreadUser;
use Tobyz\JsonApiServer\Context as OriginalContext;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * @extends AbstractDatabaseResource<Thread>
 */
class ThreadResource extends AbstractDatabaseResource
{
    public function __construct(
        protected Translator $translator,
        protected Events $events,
        protected UnreadTracker $unread
    ) {
    }

    public function type(): string
    {
        return 'chat-threads';
    }

    public function model(): string
    {
        return Thread::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    /**
     * What a page of threads has to have in hand before it is serialised.
     *
     * `creator` and `originalMessage` were included but never loaded, and an
     * include only decides what ends up in the response — the serialiser still
     * reaches for the relation per row. At thirty threads a page that was a
     * query per thread for each of them.
     *
     * The root message is the costly one: it is serialised by MessageResource,
     * which resolves nine capability flags and four relation-backed summaries,
     * so an unloaded root cost another seven or so queries per thread on its own.
     */
    protected const EAGER_LOAD = [
        'channel',
        'creator',
        'lastMessage.user',
        'originalMessage.user.groups',
        'originalMessage.channel',
        'originalMessage.reactions',
        'originalMessage.uploads',
        'originalMessage.mentions',
        'originalMessage.flags',
    ];

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make()
                // Authenticated again. Reading the chat is for accounts: the guest
                // read permission this briefly supported has been withdrawn, and
                // without the gate these endpoints would answer 200 with an empty
                // collection to anyone, which is a slower way of saying no.
                ->authenticated()
                ->defaultInclude(['creator', 'originalMessage', 'originalMessage.user'])
                // `channel` because every policy on a thread reads it, visibility
                // included — the Index already loads it for the same reason.
                ->eagerLoad(self::EAGER_LOAD)
                ->eagerLoadWhere(
                    'actorMembership',
                    fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)
                )
                ->eagerLoadWhere(
                    'originalMessage.bookmarks',
                    fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)
                ),

            Endpoint\Index::make()
                ->authenticated()
                ->defaultSort('-lastMessageAt')
                ->defaultInclude(['creator', 'originalMessage', 'originalMessage.user'])
                ->eagerLoad(self::EAGER_LOAD)
                // Four fields on every row read the actor's membership, and the
                // renaming, posting and closing policies read it again. Loaded
                // once for the page instead of once per thread —
                // `membershipFor()` checks the row belongs to the actor before
                // trusting it.
                ->eagerLoadWhere(
                    'actorMembership',
                    fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)
                )
                ->eagerLoadWhere(
                    'originalMessage.bookmarks',
                    fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)
                )
                ->paginate(30),

            // ->can() rather than visible(): it resolves the ability against
            // $context->model, which is what core does for model-scoped endpoints.
            Endpoint\Update::make()
                ->authenticated()
                ->can('rename')
                ->action(function (Context $context) {
                    /** @var Thread $thread */
                    $thread = $context->model;

                    $this->events->dispatch(new ThreadWasEdited($thread, $context->getActor()));

                    return $thread;
                }),

            Endpoint\Endpoint::make('read')
                ->route('POST', '/{id}/read')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Thread $thread */
                    $thread = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('view', $thread)) {
                        throw new ForbiddenException();
                    }

                    $upTo = Arr::get($context->body(), 'data.attributes.lastReadMessageId');

                    $this->unread->markThreadRead(
                        $thread,
                        $actor,
                        $upTo === null ? null : (int) $upTo
                    );
                })
                ->response(fn () => new EmptyResponse(204)),

            // Per-thread tracking level, the bell menu in Discourse's thread
            // header.
            Endpoint\Endpoint::make('tracking')
                ->route('POST', '/{id}/tracking')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Thread $thread */
                    $thread = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('view', $thread)) {
                        throw new ForbiddenException();
                    }

                    $level = (int) Arr::get($context->body(), 'data.attributes.notificationLevel', ThreadUser::LEVEL_ALWAYS);

                    if (! in_array($level, [ThreadUser::LEVEL_NEVER, ThreadUser::LEVEL_MENTIONS, ThreadUser::LEVEL_ALWAYS], true)) {
                        $level = ThreadUser::LEVEL_ALWAYS;
                    }

                    $membership = ThreadUser::query()
                        ->where('thread_id', $thread->id)
                        ->where('user_id', $actor->id)
                        ->first();

                    if ($membership === null) {
                        $membership = new ThreadUser();
                        $membership->thread_id = $thread->id;
                        $membership->user_id = $actor->id;
                    }

                    $membership->notification_level = $level;
                    $membership->save();

                    // The response serialises this same thread, and four of its
                    // fields read the membership back. Handing over the row just
                    // saved keeps the memoised lookup from answering with what it
                    // knew before the write.
                    $thread->forgetMembership($actor);
                    $thread->setRelation('actorMembership', $membership);

                    return $thread;
                }),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title')
                ->nullable()
                ->maxLength(200)
                ->writable(fn (Thread $t, Context $context) => $context->getActor()->can('rename', $t)),

            Schema\Str::make('status'),

            Schema\Integer::make('channelId'),
            Schema\Integer::make('originalMessageId')->nullable(),
            Schema\Integer::make('repliesCount'),
            Schema\Integer::make('lastMessageId')->nullable(),
            Schema\DateTime::make('lastMessageAt')->nullable(),
            Schema\DateTime::make('createdAt'),

            // ── Per-actor tracking state ─────────────────────────────────────
            Schema\Integer::make('notificationLevel')
                ->get(fn (Thread $t, Context $context) => $this->membership($t, $context->getActor())?->notification_level
                    ?? ThreadUser::LEVEL_NEVER),

            Schema\Integer::make('unreadCount')
                ->get(fn (Thread $t, Context $context) => $this->membership($t, $context->getActor())?->unread_count ?? 0),

            Schema\Integer::make('lastReadMessageId')
                ->get(fn (Thread $t, Context $context) => $this->membership($t, $context->getActor())?->last_read_message_id ?? 0),

            Schema\Boolean::make('isParticipating')
                ->get(fn (Thread $t, Context $context) => $this->membership($t, $context->getActor()) !== null),

            Schema\Boolean::make('canRename')
                ->get(fn (Thread $t, Context $context) => $context->getActor()->can('rename', $t)),

            Schema\Boolean::make('canPostMessage')
                ->get(fn (Thread $t, Context $context) => $context->getActor()->can('postMessage', $t)),

            Schema\Boolean::make('canClose')
                ->get(fn (Thread $t, Context $context) => $context->getActor()->can('close', $t)),

            Schema\Relationship\ToOne::make('creator')->type('users')->includable(),
            Schema\Relationship\ToOne::make('channel')->type('chat-channels')->includable(),
            Schema\Relationship\ToOne::make('originalMessage')->type('chat-messages')->includable(),
            Schema\Relationship\ToOne::make('lastMessage')->type('chat-messages')->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('lastMessageAt'),
            SortColumn::make('createdAt'),
            SortColumn::make('repliesCount'),
        ];
    }


    /** @var array<int, ThreadUser|null|false> */
    protected array $membershipCache = [];

    protected function membership(Thread $thread, User $actor): ?ThreadUser
    {
        if (! $actor->exists) {
            return null;
        }

        if (! array_key_exists($thread->id, $this->membershipCache)) {
            $this->membershipCache[$thread->id] = $thread->membershipFor($actor);
        }

        return $this->membershipCache[$thread->id] ?: null;
    }
}
