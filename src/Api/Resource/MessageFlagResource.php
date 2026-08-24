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
use Flarum\Group\Group;
use Flarum\Locale\Translator;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Ramon\Chat\Message;
use Ramon\Chat\MessageFlag;
use Ramon\Chat\Notification\MessageFlaggedBlueprint;
use Tobyz\JsonApiServer\Context as OriginalContext;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * The chat's own moderation queue.
 *
 * flarum/flags cannot hold these: its `flags.post_id` is a non-nullable foreign key
 * into `posts`, so a chat message id is rejected by the database itself. Rather
 * than alter another extension's schema — which its next release would undo — the
 * chat keeps its own reports, with the same shape: who reported, why, in their own
 * words, and whether a moderator has dealt with it.
 *
 * @extends AbstractDatabaseResource<MessageFlag>
 */
class MessageFlagResource extends AbstractDatabaseResource
{
    public function __construct(
        protected Translator $translator,
        protected NotificationSyncer $notifications
    ) {
    }

    public function type(): string
    {
        return 'chat-message-flags';
    }

    public function model(): string
    {
        return MessageFlag::class;
    }

    /**
     * Reports are moderator-only reading, and only about messages the moderator can
     * already see.
     *
     * The permission is asserted on the endpoints too. This is the second layer: a
     * scope is what protects anything that reaches the query by another route, and
     * an authorisation that exists in exactly one place is one edit away from not
     * existing.
     */
    public function scope(Builder $query, OriginalContext $context): void
    {
        $actor = $context->getActor();

        if (! $actor->hasPermission('ramon-chat.moderate')) {
            $query->whereRaw('1 = 0');

            return;
        }

        // The queue inherits message visibility rather than defining its own. What
        // that grants is ScopeChannelVisibility's decision, and for a moderator it
        // includes private channels — the same trade the rest of the extension
        // makes, on the grounds that a room they cannot see is one they cannot
        // moderate. The point here is that the queue never grants *more* than
        // reading the channel would.
        //
        // `whereVisibleTo` is registered by Flarum's ScopeVisibilityTrait as a
        // model scope, which static analysis cannot see on a builder instance —
        // every other call site in this extension is baselined for the same reason.
        //
        // @phpstan-ignore method.notFound (Flarum model scope)
        $query->whereHas('message', fn (Builder $q) => $q->whereVisibleTo($actor));

        // No open/resolved default here. This scope guards `Show`, where a report
        // fetched by id is one someone asked for by name — hiding it because it had
        // been resolved would be a 404 for a row that exists. The listing's default
        // is the client's to state, through `filter[resolved]`.
    }

    /**
     * What each reported message needs loaded before it is serialised.
     *
     * The reports' own relations are not here and do not need to be: `user`,
     * `message` and `resolvedBy` are declared relationships, so EloquentBuffer
     * batches them across the whole page. What it does not cover is a relation
     * read from inside a field getter or a policy, and that is all of these.
     *
     * They are the *included message's* relations rather than this resource's.
     * Every reported message is serialised by MessageResource, which resolves
     * nine capability flags — each running a policy that reads `channel` — plus
     * the reaction, mention, bookmark and flag summaries. None of that goes
     * through the buffer, so unloaded it was about seven queries per report, and
     * this endpoint pages fifty at a time.
     *
     * The `message.` prefix is what carries them across: when the buffer loads
     * the reported messages, `Endpoint::getEagerLoadsFor('message')` hands it
     * everything named here beneath that relation.
     */
    protected const EAGER_LOAD = [
        'message.user.groups',
        'message.channel',
        'message.reactions',
        'message.uploads',
        'message.mentions',
        'message.flags',
    ];

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->can('ramon-chat.moderate')
                ->defaultSort('-createdAt')
                ->defaultInclude(['user', 'message', 'message.user', 'message.channel', 'resolvedBy'])
                ->eagerLoad(self::EAGER_LOAD)
                ->eagerLoadWhere(
                    'message.bookmarks',
                    fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)
                )
                ->paginate(50),

            Endpoint\Show::make()
                ->authenticated()
                ->can('ramon-chat.moderate')
                ->defaultInclude(['user', 'message', 'message.user', 'message.channel', 'resolvedBy'])
                ->eagerLoad(self::EAGER_LOAD)
                ->eagerLoadWhere(
                    'message.bookmarks',
                    fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)
                ),

            // Filing a report. Not a plain Create with writable fields: the message
            // has to be resolved and authorised before anything is written, and the
            // reporter is the actor rather than whoever the body names.
            Endpoint\Create::make()
                ->authenticated()
                ->action(function (Context $context) {
                    $attributes = Arr::get($context->body(), 'data.attributes', []);
                    $actor = $context->getActor();

                    $message = Message::whereVisibleTo($actor)
                        ->whereKey((int) Arr::get($attributes, 'messageId'))
                        ->first();

                    if (! $message instanceof Message) {
                        throw new ForbiddenException();
                    }

                    if (! $actor->can('flag', $message)) {
                        throw new ForbiddenException();
                    }

                    $reason = (string) Arr::get($attributes, 'reason', '');

                    if (! MessageFlag::isValidReason($reason)) {
                        throw new ValidationException([
                            'reason' => $this->translator->trans('ramon-chat.api.flag_reason_invalid'),
                        ]);
                    }

                    $detail = trim((string) Arr::get($attributes, 'detail', ''));

                    // `other` says nothing on its own, so it is the one reason that
                    // has to be explained. The rest are self-describing and the
                    // field stays optional — a required box is a reason not to file.
                    if ($reason === 'other' && $detail === '') {
                        throw new ValidationException([
                            'detail' => $this->translator->trans('ramon-chat.api.flag_detail_required'),
                        ]);
                    }

                    /** @var MessageFlag|null $existing */
                    $existing = MessageFlag::query()
                        ->where('message_id', $message->id)
                        ->where('user_id', $actor->id)
                        ->first();

                    // Reporting the same message twice is not a stronger signal.
                    // The second filing updates the first rather than failing on the
                    // unique index, so someone who picked the wrong reason can
                    // correct it — and a message reported, cleared, then reported
                    // again reopens instead of being silently swallowed.
                    $flag = $existing ?? new MessageFlag();

                    $flag->message_id = $message->id;
                    $flag->user_id = $actor->id;
                    $flag->reason = $reason;
                    $flag->detail = $detail === '' ? null : mb_substr($detail, 0, 1000);
                    $flag->resolved_at = null;
                    $flag->resolved_by_id = null;
                    $flag->created_at = Carbon::now();
                    $flag->save();

                    $this->notifyAdministrators($flag, $message, $actor);

                    return $flag;
                })
                ->defaultInclude(['user', 'message']),

            // Closing a report without acting on the message: the moderator looked
            // and decided there was nothing to do. Deleting the message is a
            // separate act on a separate endpoint, and the listener closes the
            // reports about it when it happens.
            Endpoint\Endpoint::make('resolve')
                ->route('POST', '/{id}/resolve')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var MessageFlag $flag */
                    $flag = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->hasPermission('ramon-chat.moderate')) {
                        throw new ForbiddenException();
                    }

                    $flag->resolve($actor)->save();

                    return $flag;
                })
                ->defaultInclude(['user', 'message', 'resolvedBy']),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('reason'),

            // The reporter's own words, so they carry whatever they typed. Rendered
            // as text by the client, never as HTML — see FlaggedMessagesList.
            Schema\Str::make('detail')->nullable(),

            Schema\Integer::make('messageId'),

            Schema\DateTime::make('createdAt')->nullable(),
            Schema\DateTime::make('resolvedAt')->nullable(),

            Schema\Boolean::make('isResolved')
                ->get(fn (MessageFlag $flag) => $flag->isResolved()),

            Schema\Relationship\ToOne::make('user')->type('users')->includable(),
            Schema\Relationship\ToOne::make('message')->type('chat-messages')->includable(),
            Schema\Relationship\ToOne::make('resolvedBy')->type('users')->includable(),
        ];
    }

    /**
     * Tells the administrators a report has arrived.
     *
     * Administrators rather than everyone who can moderate. Reading the queue and
     * being interrupted by it are different things: a forum can hand
     * `ramon-chat.moderate` to a wide group and still want the alert to reach the
     * few people who answer for the place. Everyone else finds the report where
     * they would look for it anyway — the queue and its badge.
     *
     * Candidates come from SQL, not from a scan. The first version of this loaded
     * every user and asked each one for the permission and the visibility check:
     * on a forum with six thousand accounts that is six thousand model hydrations
     * and six thousand queries per report, and the request simply never returned.
     * The visibility check survives, but it now runs over the handful of people
     * who could possibly qualify.
     *
     * The reporter is excluded — they know.
     */
    protected function notifyAdministrators(MessageFlag $flag, Message $message, User $reporter): void
    {
        // Built as statements rather than one chain: `whereExists` is typed as
        // returning the lower-level query builder, and the chained result would
        // hand back plain rows instead of User models.
        $query = User::query();
        $query->where('users.id', '!=', $reporter->id);
        $query->whereExists(function ($sub) {
            $sub->selectRaw(1)
                ->from('group_user')
                ->whereColumn('group_user.user_id', 'users.id')
                ->where('group_user.group_id', Group::ADMINISTRATOR_ID);
        });

        $recipients = $query
            ->get()
            // An administrator holds every permission, but not every channel is
            // theirs to read — a tag-bound channel still inherits its category's
            // `viewForum`. Naming a channel in an alert to someone who cannot open
            // it would leak the room.
            ->filter(fn (User $user) => $user->can('view', $message))
            ->values()
            ->all();

        if ($recipients === []) {
            return;
        }

        $this->notifications->sync(
            new MessageFlaggedBlueprint($flag, $message, $reporter),
            $recipients
        );
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('id'),
            SortColumn::make('createdAt'),
        ];
    }
}
