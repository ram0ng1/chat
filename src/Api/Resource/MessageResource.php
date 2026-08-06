<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Resource;

use Carbon\Carbon;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Ramon\Chat\Bookmark;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\MessagePinToggled;
use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Event\MessageWasEdited;
use Ramon\Chat\Event\MessageWasPurged;
use Ramon\Chat\Event\MessageWasRestored;
use Ramon\Chat\Event\ReactionToggled;
use Ramon\Chat\Mention\MentionResolver;
use Ramon\Chat\Message;
use Ramon\Chat\MessageReaction;
use Ramon\Chat\MessageRevision;
use Ramon\Chat\Service\MessageDispatcher;
use Ramon\Chat\Thread;
use Tobyz\JsonApiServer\Context as OriginalContext;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * @extends AbstractDatabaseResource<Message>
 */
class MessageResource extends AbstractDatabaseResource
{
    public function __construct(
        protected Translator $translator,
        protected Events $events,
        protected MessageDispatcher $dispatcher,
        protected MentionResolver $mentions
    ) {
    }

    public function type(): string
    {
        return 'chat-messages';
    }

    public function model(): string
    {
        return Message::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make()
                // Authenticated again. Reading the chat is for accounts: the guest
                // read permission this briefly supported has been withdrawn, and
                // without the gate these endpoints would answer 200 with an empty
                // collection to anyone, which is a slower way of saying no.
                ->authenticated()
                ->defaultInclude(['user', 'user.groups', 'replyTo', 'replyTo.user', 'uploads', 'thread', 'thread.lastMessage', 'deletedBy'])
                ->eagerLoad(['user.groups', 'reactions', 'uploads', 'mentions', 'flags', 'channel'])
                ->eagerLoadWhere('bookmarks', fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)),

            Endpoint\Index::make()
                ->authenticated()
                // Newest-first so a channel opens at the bottom with one page,
                // then pages backwards as the user scrolls up.
                ->defaultSort('-id')
                // `thread` has to be *included*, not merely eager-loaded. eagerLoad
                // populates the relation server-side for the serialiser's own use but
                // does not put the record in the JSON:API `included` array, so the
                // client's `message.thread()` stayed false and the reply indicator
                // never rendered. It appeared only for whoever had just created the
                // thread, because the Create response does include it — and vanished
                // for them too on the next reload.
                //
                // `thread.lastMessage` comes along for the indicator's preview text.
                //
                // `user.groups` feeds the author's badges, the same way core's
                // PostResource includes it for a post. Eager-loaded as well as
                // included: without it the serialiser reads the relation once per
                // row, which is fifty queries on a full page of messages.
                ->defaultInclude(['user', 'user.groups', 'replyTo', 'replyTo.user', 'uploads', 'thread', 'thread.lastMessage', 'deletedBy'])
                // `channel` because every capability flag resolves a policy that
                // reads it, and `bookmarks` because `isBookmarked` does — both were
                // lazy-loading once per row, which is a hundred queries on a full
                // page before a single one of them answered anything.
                //
                // Bookmarks are narrowed to the actor: the field only ever asks
                // whether *they* bookmarked it, and a popular message can carry a
                // row per member otherwise.
                ->eagerLoad(['user.groups', 'reactions', 'uploads', 'mentions', 'thread', 'flags', 'channel'])
                ->eagerLoadWhere('bookmarks', fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id))
                ->paginate(50),

            // Creation goes through MessageDispatcher rather than Create's own
            // field-deserialisation path: mentions, threads, uploads, counters and
            // unread fan-out must all happen in one transaction.
            //
            // It is still an Endpoint\Create, not a bare Endpoint. Create::setUp()
            // registers the POST / route, a beforeSerialization hook that loads
            // includes, and a response() that serialises the model with 201.
            // Chaining ->action() after make() replaces only the action and keeps
            // those. A bare Endpoint has no response(), and Endpoint::handle() only
            // auto-serialises a returned model when $context->model is set — which
            // is never true for a collection-level route, so it returned null and
            // JsonApi::handle() raised "Return value must be of type
            // ResponseInterface, null returned".
            Endpoint\Create::make()
                ->authenticated()
                ->action(function (Context $context) {
                    $attributes = Arr::get($context->body(), 'data.attributes', []);

                    $channel = $this->findChannel($context, (int) Arr::get($attributes, 'channelId'));
                    $actor = $context->getActor();

                    if (! $actor->can('postMessage', $channel)) {
                        throw new ForbiddenException();
                    }

                    $thread = $this->findThread($context, Arr::get($attributes, 'threadId'), $channel);
                    $replyTo = $this->findReplyTarget($context, Arr::get($attributes, 'replyToId'));

                    $createThread = (bool) Arr::get($attributes, 'createThread', false);

                    if ($createThread && ! $channel->threading_enabled) {
                        throw new ValidationException([
                            'createThread' => $this->translator->trans('ramon-chat.api.threading_disabled'),
                        ]);
                    }

                    // Enforced here as well as on the button: `canCreateThread` only
                    // decides whether the affordance is drawn, and a request may
                    // arrive with `createThread` set regardless of what was drawn.
                    if ($createThread && $replyTo !== null && ! $actor->can('createThread', $replyTo)) {
                        throw new ForbiddenException();
                    }

                    return $this->dispatcher->send(
                        channel: $channel,
                        actor: $actor,
                        content: (string) Arr::get($attributes, 'content', ''),
                        thread: $thread,
                        replyTo: $replyTo,
                        uploadIds: (array) Arr::get($attributes, 'uploadIds', []),
                        createThread: $createThread
                    );
                })
                ->defaultInclude(['user', 'user.groups', 'replyTo', 'replyTo.user', 'uploads', 'thread']),

            Endpoint\Endpoint::make('edit')
                ->route('PATCH', '/{id}')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('edit', $message)) {
                        throw new ForbiddenException();
                    }

                    $content = trim((string) Arr::get($context->body(), 'data.attributes.content', ''));

                    if ($content === '') {
                        throw new ValidationException([
                            'content' => $this->translator->trans('ramon-chat.api.message_empty'),
                        ]);
                    }

                    $message->reviseContent($content, $actor);
                    $message->save();

                    // Re-resolve mentions: an edit that removes a mention must
                    // also remove the notification pressure it created.
                    $this->mentions->sync(
                        $message,
                        $message->channel !== null && $actor->can('mentionChannelWide', $message->channel)
                    );

                    $this->events->dispatch(new MessageWasEdited($message, $actor));

                    return $message;
                })
                ->defaultInclude(['user', 'user.groups', 'replyTo', 'replyTo.user', 'uploads']),

            Endpoint\Endpoint::make('remove')
                ->route('POST', '/{id}/delete')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('delete', $message)) {
                        throw new ForbiddenException();
                    }

                    $message->deleted_at = Carbon::now();
                    $message->deleted_by_id = $actor->id;
                    $message->save();

                    $this->refreshContainers($message);

                    $this->events->dispatch(new MessageWasDeleted($message, $actor));
                })
                ->response(fn () => new EmptyResponse(204)),

            // Removing the row for good, tombstone and all. A second step after
            // deletion rather than a stronger first one: this cannot be undone, and
            // a channel full of tombstones is the problem it exists to solve.
            Endpoint\Endpoint::make('purge')
                ->route('POST', '/{id}/purge')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('forceDelete', $message)) {
                        throw new ForbiddenException();
                    }

                    // Held before the row goes, because afterwards there is nothing
                    // left to ask which channel and thread to recount.
                    $channel = $message->channel;
                    $thread = $message->thread;

                    // Held for the same reason, and read before the row goes.
                    $messageId = (int) $message->id;
                    $threadId = $message->thread_id;

                    // Reactions, mentions, revisions, bookmarks, reports and upload
                    // rows all cascade on the foreign key. The files themselves are
                    // already gone: they were removed when the message was deleted,
                    // which this can only follow.
                    $message->delete();

                    // `chat_channels.last_message_id` and the thread's counters are
                    // plain columns with no foreign key, so nothing in the database
                    // repairs them — a purged last message would leave the sidebar
                    // pointing at a row that no longer exists.
                    $channel?->refreshMetadata()->save();
                    $thread?->refreshMetadata()->save();

                    // Announced so the row leaves everyone's stream, not just the
                    // moderator's. A purge is the one deletion with nothing left
                    // behind to redraw — the others turn into a tombstone, which is
                    // why they ride on the message-changed event and this cannot.
                    if ($channel !== null) {
                        $this->events->dispatch(
                            new MessageWasPurged($messageId, $channel, $threadId, $actor)
                        );
                    }
                })
                ->response(fn () => new EmptyResponse(204)),

            Endpoint\Endpoint::make('restore')
                ->route('POST', '/{id}/restore')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('restore', $message)) {
                        throw new ForbiddenException();
                    }

                    $message->deleted_at = null;
                    $message->deleted_by_id = null;
                    $message->save();

                    $this->refreshContainers($message);

                    $this->events->dispatch(new MessageWasRestored($message, $actor));

                    return $message;
                })
                ->defaultInclude(['user', 'user.groups', 'uploads']),

            Endpoint\Endpoint::make('react')
                ->route('POST', '/{id}/react')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('react', $message)) {
                        throw new ForbiddenException();
                    }

                    $emoji = $this->normaliseEmoji(
                        (string) Arr::get($context->body(), 'data.attributes.emoji', '')
                    );

                    /** @var MessageReaction|null $existing */
                    $existing = MessageReaction::query()
                        ->where('message_id', $message->id)
                        ->where('user_id', $actor->id)
                        ->where('emoji', $emoji)
                        ->first();

                    // Reacting is a toggle, so the same request both adds and
                    // removes depending on current state.
                    if ($existing !== null) {
                        $existing->delete();
                        $added = false;
                    } else {
                        $reaction = new MessageReaction();
                        $reaction->message_id = $message->id;
                        $reaction->user_id = $actor->id;
                        $reaction->emoji = $emoji;
                        $reaction->created_at = Carbon::now();
                        $reaction->save();
                        $added = true;
                    }

                    $message->unsetRelation('reactions');

                    // A reaction lives in its own table, so nothing about the
                    // message row changes when one is added or removed — and the
                    // polling fallback reconciles on exactly that column. Without
                    // the touch, a forum with no websocket showed reactions only
                    // to whoever left them, and to everyone else only after a
                    // reload. See Search\Filter\MessageChangedFilter.
                    $message->touch();

                    $this->events->dispatch(new ReactionToggled($message, $actor, $emoji, $added));

                    return $message;
                })
                ->defaultInclude(['user', 'user.groups', 'uploads']),

            Endpoint\Endpoint::make('bookmark')
                ->route('POST', '/{id}/bookmark')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('bookmark', $message)) {
                        throw new ForbiddenException();
                    }

                    /** @var Bookmark|null $existing */
                    $existing = Bookmark::query()
                        ->where('message_id', $message->id)
                        ->where('user_id', $actor->id)
                        ->first();

                    if ($existing !== null) {
                        $existing->delete();
                    } else {
                        $bookmark = new Bookmark();
                        $bookmark->message_id = $message->id;
                        $bookmark->user_id = $actor->id;
                        $bookmark->name = Arr::get($context->body(), 'data.attributes.name');
                        $bookmark->save();
                    }

                    return $message;
                })
                ->defaultInclude(['user', 'user.groups']),

            // The edit history. Its own endpoint rather than a relationship: it is
            // read rarely and by few people, and including it in the message schema
            // would put every prior version of every message on the wire for the
            // whole stream.
            Endpoint\Endpoint::make('revisions')
                ->route('GET', '/{id}/revisions')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('viewRevisions', $message)) {
                        throw new ForbiddenException();
                    }

                    $revisions = $message->revisions()
                        ->with('editedBy')
                        ->orderBy('id')
                        ->get();

                    return $revisions->map(fn (MessageRevision $revision) => [
                        'id'        => (int) $revision->id,
                        'content'   => $revision->content,
                        'createdAt' => $revision->created_at?->toIso8601String(),
                        'editedBy'  => $revision->editedBy === null ? null : [
                            'id'          => (int) $revision->editedBy->id,
                            'username'    => $revision->editedBy->username,
                            'displayName' => $revision->editedBy->display_name,
                            'avatarUrl'   => $revision->editedBy->avatar_url,
                        ],
                    ])->values()->all();
                })
                // A plain array, not a model, so the response has to be built here:
                // Endpoint::handle() only auto-serialises when $context->model is
                // the thing being returned.
                ->response(fn ($data) => new JsonResponse([
                    'data' => [
                        'type'       => 'chat-message-revisions',
                        'attributes' => ['revisions' => $data],
                    ],
                ])),

            Endpoint\Endpoint::make('pin')
                ->route('POST', '/{id}/pin')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('pin', $message)) {
                        throw new ForbiddenException();
                    }

                    // A toggle, so the client needs no separate unpin route and two
                    // rapid clicks cannot leave a half-applied state.
                    if ($message->isPinned()) {
                        $message->pinned_at = null;
                        $message->pinned_by_id = null;
                    } else {
                        $message->pinned_at = Carbon::now();
                        $message->pinned_by_id = $actor->id;
                    }

                    $message->save();

                    $this->events->dispatch(new MessagePinToggled($message, $actor));

                    return $message;
                })
                ->defaultInclude(['user', 'user.groups', 'pinnedBy']),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('content')
                ->nullable()
                // Deleted messages are returned as tombstones so the stream keeps
                // its shape, but their text is withheld from everyone except the
                // author and moderators.
                ->get(fn (Message $m, Context $context) => $this->visibleContent($m, $context->getActor())),

            Schema\Str::make('contentHtml')
                ->nullable()
                ->get(function (Message $m, Context $context) {
                    if ($this->isRedacted($m, $context->getActor()) || $m->isSystem()) {
                        return null;
                    }

                    return $m->formatContent($context->request);
                }),

            Schema\Str::make('type'),
            Schema\Str::make('systemKey')->nullable(),
            Schema\Arr::make('systemData')->nullable(),

            Schema\Integer::make('number')->nullable(),
            Schema\Integer::make('channelId'),
            Schema\Integer::make('threadId')->nullable(),
            Schema\Integer::make('replyToId')->nullable(),

            Schema\DateTime::make('createdAt'),
            Schema\DateTime::make('editedAt')->nullable(),
            Schema\DateTime::make('deletedAt')->nullable(),

            // The cursor the polling fallback reconciles against. Every change a
            // message can undergo — an edit, a deletion, a pin, a reaction —
            // advances this column, so the client can ask for "whatever moved
            // since" in one filter instead of needing a separate poll per kind of
            // change. See Search\Filter\MessageChangedFilter.
            Schema\DateTime::make('updatedAt'),

            Schema\Boolean::make('isDeleted')
                ->get(fn (Message $m) => $m->isDeleted()),

            Schema\Boolean::make('isEdited')
                ->get(fn (Message $m) => $m->isEdited()),

            // Whether someone other than the author removed it.
            //
            // The client used to infer this from whether the text had been
            // withheld, which is a different question: a message the author
            // deleted is withheld from everyone else too, so their own deletion
            // was announced to the channel as a moderator removal.
            //
            // Read from the column, so it does not depend on `deletedBy` having
            // been included — a realtime push carries no relations.
            Schema\Boolean::make('isModeratorDeleted')
                ->get(fn (Message $m) => $m->isDeleted()
                    && $m->deleted_by_id !== null
                    && (int) $m->deleted_by_id !== (int) $m->user_id),

            Schema\DateTime::make('pinnedAt')->nullable(),

            Schema\Boolean::make('isPinned')
                ->get(fn (Message $m) => $m->isPinned()),

            // Reaction summary: emoji => { count, reacted }. Sent pre-aggregated
            // so the client never has to hold every reaction row in memory.
            Schema\Arr::make('reactionSummary')
                ->get(fn (Message $m, Context $context) => $this->reactionSummary($m, $context->getActor())),

            Schema\Arr::make('mentionedUsers')
                ->get(fn (Message $m) => $m->mentions
                    ->where('type', 'user')
                    ->pluck('user_id')
                    ->filter()
                    ->values()
                    ->all()),

            Schema\Boolean::make('mentionsChannelWide')
                ->get(fn (Message $m) => $m->mentions->contains(fn ($mention) => $mention->isChannelWide())),

            Schema\Boolean::make('isBookmarked')
                ->get(function (Message $m, Context $context) {
                    $actor = $context->getActor();

                    if (! $actor->exists) {
                        return false;
                    }

                    return $m->bookmarks->contains(fn (Bookmark $b) => $b->user_id === $actor->id);
                }),

            // ── Capability flags ─────────────────────────────────────────────
            Schema\Boolean::make('canEdit')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('edit', $m)),

            Schema\Boolean::make('canDelete')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('delete', $m)),

            Schema\Boolean::make('canReact')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('react', $m)),

            Schema\Boolean::make('canReply')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('reply', $m)),

            Schema\Boolean::make('canCreateThread')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('createThread', $m)),

            Schema\Boolean::make('canMove')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('move', $m)),

            Schema\Boolean::make('canPin')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('pin', $m)),

            Schema\Boolean::make('canForceDelete')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('forceDelete', $m)),

            Schema\Boolean::make('canFlag')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('flag', $m)),

            // Whether *this* actor has already reported it, so the button reads
            // "reported" instead of inviting a second filing that would only
            // overwrite the first.
            Schema\Boolean::make('isFlagged')
                ->get(function (Message $m, Context $context) {
                    $actor = $context->getActor();

                    if (! $actor->exists) {
                        return false;
                    }

                    return $m->flags->contains(
                        fn ($flag) => $flag->user_id === $actor->id && $flag->resolved_at === null
                    );
                }),

            // How many people have reported it and nobody has yet dealt with it.
            // Moderators only: a visible count would tell everyone which messages
            // are being reported, which is both an invitation to pile on and a way
            // to find out you have been reported.
            //
            // Read from the eager-loaded relation rather than counted per row —
            // a query inside a field getter is one query per message in the stream.
            Schema\Integer::make('flagsCount')
                ->visible(fn (Message $m, Context $context) => $context->getActor()->hasPermission('ramon-chat.moderate'))
                ->get(fn (Message $m) => $m->flags->whereNull('resolved_at')->count()),

            Schema\Relationship\ToOne::make('user')->type('users')->includable(),
            Schema\Relationship\ToOne::make('editedBy')->type('users')->includable(),
            Schema\Relationship\ToOne::make('deletedBy')->type('users')->includable(),
            Schema\Relationship\ToOne::make('pinnedBy')->type('users')->includable(),
            Schema\Relationship\ToOne::make('replyTo')->type('chat-messages')->includable(),
            Schema\Relationship\ToOne::make('thread')->type('chat-threads')->includable(),
            Schema\Relationship\ToOne::make('channel')->type('chat-channels')->includable(),
            Schema\Relationship\ToMany::make('uploads')->type('chat-uploads')->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('id'),
            SortColumn::make('createdAt'),
            SortColumn::make('number'),
            // Most recently pinned first, for the pinned panel.
            SortColumn::make('pinnedAt'),
        ];
    }


    /**
     * A deleted message's text is withheld from everyone but its author and
     * moderators; the row itself still serialises so the client can render a
     * tombstone in place rather than silently reflowing the stream.
     */
    protected function isRedacted(Message $message, User $actor): bool
    {
        if (! $message->isDeleted()) {
            return false;
        }

        return ! ($actor->can('ramon-chat.moderate') || $actor->id === $message->user_id);
    }

    protected function visibleContent(Message $message, User $actor): ?string
    {
        if ($this->isRedacted($message, $actor)) {
            return null;
        }

        return $message->content;
    }

    /**
     * @return array<string, array{count: int, reacted: bool}>
     */
    protected function reactionSummary(Message $message, User $actor): array
    {
        $summary = [];

        foreach ($message->reactions as $reaction) {
            $emoji = $reaction->emoji;

            if (! isset($summary[$emoji])) {
                $summary[$emoji] = ['count' => 0, 'reacted' => false];
            }

            $summary[$emoji]['count']++;

            if ($actor->exists && $reaction->user_id === $actor->id) {
                $summary[$emoji]['reacted'] = true;
            }
        }

        return $summary;
    }

    protected function findChannel(Context $context, int $channelId): Channel
    {
        /** @var Channel|null $channel */
        $channel = Channel::query()
            ->whereVisibleTo($context->getActor())
            ->find($channelId);

        if ($channel === null) {
            throw new ValidationException([
                'channelId' => $this->translator->trans('ramon-chat.api.channel_not_found'),
            ]);
        }

        return $channel;
    }

    protected function findThread(Context $context, mixed $threadId, Channel $channel): ?Thread
    {
        if ($threadId === null || $threadId === '') {
            return null;
        }

        /** @var Thread|null $thread */
        $thread = Thread::query()
            ->whereVisibleTo($context->getActor())
            ->where('channel_id', $channel->id)
            ->find((int) $threadId);

        if ($thread === null) {
            throw new ValidationException([
                'threadId' => $this->translator->trans('ramon-chat.api.invalid_thread_target'),
            ]);
        }

        return $thread;
    }

    protected function findReplyTarget(Context $context, mixed $replyToId): ?Message
    {
        if ($replyToId === null || $replyToId === '') {
            return null;
        }

        /** @var Message|null $target */
        $target = Message::query()
            ->whereVisibleTo($context->getActor())
            ->find((int) $replyToId);

        if ($target === null) {
            throw new ValidationException([
                'replyToId' => $this->translator->trans('ramon-chat.api.invalid_reply_target'),
            ]);
        }

        return $target;
    }

    /**
     * @throws ValidationException
     */
    protected function normaliseEmoji(string $emoji): string
    {
        // Accept both `:heart:` and `heart`, store the bare shortcode.
        $emoji = trim($emoji, ": \t\n\r");

        if ($emoji === '' || mb_strlen($emoji) > 60 || ! preg_match('/^[a-z0-9_+\-]+$/i', $emoji)) {
            throw new ValidationException([
                'emoji' => $this->translator->trans('ramon-chat.api.invalid_reaction'),
            ]);
        }

        return $emoji;
    }

    /**
     * Recomputes channel and thread counters after a delete or restore, where
     * incrementing would drift.
     */
    protected function refreshContainers(Message $message): void
    {
        $channel = $message->channel;

        if ($channel !== null) {
            $channel->refreshMetadata()->save();
        }

        $thread = $message->thread;

        if ($thread !== null) {
            $thread->refreshMetadata()->save();
        }
    }
}
