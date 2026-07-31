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
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\ChannelStatusChanged;
use Ramon\Chat\Event\ChannelWasCreated;
use Ramon\Chat\Event\ChannelWasDeleted;
use Ramon\Chat\Event\ChannelWasEdited;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UserLeftChannel;
use Ramon\Chat\Notification\ChannelInviteBlueprint;
use Ramon\Chat\Service\ChannelArchiver;
use Ramon\Chat\Service\MembershipManager;
use Ramon\Chat\Service\SlowMode;
use Ramon\Chat\Service\UnreadTracker;
use Tobyz\JsonApiServer\Context as OriginalContext;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * @extends AbstractDatabaseResource<Channel>
 */
class ChannelResource extends AbstractDatabaseResource
{
    public function __construct(
        protected Translator $translator,
        protected Events $events,
        protected UnreadTracker $unread,
        protected MembershipManager $memberships,
        protected ChannelArchiver $archiver,
        protected NotificationSyncer $notifications
    ) {
    }

    public function type(): string
    {
        return 'chat-channels';
    }

    public function model(): string
    {
        return Channel::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    /**
     * The generic Create endpoint builds its model here rather than through
     * Channel::build(), so the defaults that factory would have applied have to be
     * set explicitly.
     */
    public function newModel(OriginalContext $context): object
    {
        if ($context->creating(self::class)) {
            $channel = new Channel();

            $channel->type = Channel::TYPE_CATEGORY;
            $channel->status = Channel::STATUS_OPEN;
            $channel->creator_id = $context->getActor()->id;

            // Set explicitly rather than left to the column defaults: the response
            // is serialised from this in-memory model, so anything unset comes back
            // as null and the client reads a tri-state where it expects a boolean.
            $channel->threading_enabled = (bool) resolve(\Flarum\Settings\SettingsRepositoryInterface::class)
                ->get('ramon-chat.threading_default', false);
            $channel->is_private = false;
            $channel->post_permission = Channel::POST_ALL;
            $channel->auto_join = false;
            $channel->auto_join_on_reply = false;
            $channel->post_discussions = false;
            $channel->allow_channel_wide_mentions = true;
            $channel->messages_count = 0;
            $channel->user_count = 0;

            return $channel;
        }

        return parent::newModel($context);
    }

    /**
     * Finishes what Channel::build() would have done, and what a bare insert
     * cannot: derive the slug, subscribe the creator, and announce the channel.
     *
     * Without the join the creator would not be following their own new channel,
     * so it would not appear in their sidebar — the symptom being "I created a
     * channel and nothing happened".
     */
    protected function saveModel(\Illuminate\Database\Eloquent\Model $model, OriginalContext $context): void
    {
        /** @var Channel $model */
        $isNew = ! $model->exists;

        // Slugs identify category channels in URLs; direct channels are addressed
        // by id because their name is derived from the participant list.
        if ($model->type === Channel::TYPE_CATEGORY && $model->name !== null && $model->name !== '') {
            if ($isNew || $model->isDirty('name') || $model->slug === null) {
                $model->slug = $model->generateSlug($model->name);
            }
        }

        parent::saveModel($model, $context);

        if (! $isNew) {
            // An edit that changed nothing is not worth broadcasting. `wasChanged`
            // reflects what the save actually wrote, so re-saving identical values
            // stays silent.
            if ($model->wasChanged()) {
                $this->events->dispatch(new ChannelWasEdited($model, $context->getActor()));
            }

            return;
        }

        $actor = $context->getActor();

        if ($actor->exists) {
            $this->memberships->join($model, $actor);
        }

        $model->refreshMetadata()->save();

        $this->events->dispatch(new ChannelWasCreated($model, $actor));
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
                ->eagerLoad(['creator', 'lastMessage.user']),

            Endpoint\Index::make()
                ->authenticated()
                ->defaultSort('-lastMessageAt')
                ->eagerLoad(['creator', 'lastMessage.user'])
                ->paginate(50),

            // visible() is invoked as (model, context) when the context carries a
            // model, and as (context) alone when it does not. Create has no model
            // yet, so a two-argument closure here is a TypeError at request time —
            // which is exactly what made channel creation return 500.
            //
            // Model-scoped endpoints below use ->can() instead: it resolves the
            // ability against $context->model, and is what core does.
            Endpoint\Create::make()
                ->authenticated()
                ->visible(fn (Context $context) => $context->getActor()->can('createChannel'))
                ->defaultInclude(['creator']),

            Endpoint\Update::make()
                ->authenticated()
                ->can('edit'),

            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete')
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    // Soft delete: the transcript may still be referenced by an
                    // archive discussion, so rows are retained until the
                    // retention command sweeps them.
                    $channel->deleted_at = Carbon::now();
                    $channel->deleted_by_id = $actor->id;
                    $channel->save();

                    $this->events->dispatch(new ChannelWasDeleted($channel, $actor));
                }),

            Endpoint\Endpoint::make('read')
                ->route('POST', '/{id}/read')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('view', $channel)) {
                        throw new ForbiddenException();
                    }

                    $upTo = Arr::get($context->body(), 'data.attributes.lastReadMessageId');

                    $this->unread->markChannelRead(
                        $channel,
                        $actor,
                        $upTo === null ? null : (int) $upTo
                    );
                })
                ->response(fn () => new EmptyResponse(204)),

            Endpoint\Endpoint::make('join')
                ->route('POST', '/{id}/join')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    $hidden = (bool) Arr::get($context->body(), 'data.attributes.hidden', false);

                    // Two different rights: joining, and joining unseen. Checked
                    // separately so a member who forges `hidden: true` gets a 403
                    // rather than an invisible membership.
                    if (! $actor->can($hidden ? 'joinHidden' : 'join', $channel)) {
                        throw new ForbiddenException();
                    }

                    $this->memberships->join($channel, $actor, hidden: $hidden);

                    $this->events->dispatch(new UserJoinedChannel($channel, $actor, $actor, $hidden));
                })
                ->response(fn () => new EmptyResponse(204)),

            // Adding other people. Separate from `join`, which is the actor letting
            // themselves in: this puts someone else in a room, and for a private
            // channel it is the only way in, so it is the invitation mechanism.
            Endpoint\Endpoint::make('addMembers')
                ->route('POST', '/{id}/members')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('manageMembers', $channel)) {
                        throw new ForbiddenException();
                    }

                    $ids = array_values(array_unique(array_filter(array_map(
                        'intval',
                        (array) Arr::get($context->body(), 'data.attributes.userIds', [])
                    ))));

                    if ($ids === []) {
                        throw new ValidationException([
                            'userIds' => $this->translator->trans('ramon-chat.api.members_empty'),
                        ]);
                    }

                    if (count($ids) > 50) {
                        throw new ValidationException([
                            'userIds' => $this->translator->trans('ramon-chat.api.members_too_many', ['max' => 50]),
                        ]);
                    }

                    // Only real users, and only ones the actor can see — a bare id
                    // list must not be a way to discover which accounts exist.
                    $users = User::query()
                        ->whereVisibleTo($actor)
                        ->whereIn('id', $ids)
                        ->get();

                    $added = [];

                    foreach ($users as $user) {
                        // Already here: joining again would be a no-op, but it would
                        // still fire a notification telling them about a channel
                        // they have been in for weeks.
                        if ($channel->membershipFor($user) !== null) {
                            continue;
                        }

                        $this->memberships->join($channel, $user);

                        $this->events->dispatch(new UserJoinedChannel($channel, $user, $actor));

                        $added[] = $user;
                    }

                    // Told after the fact rather than asked first: this endpoint
                    // adds people, so the notification is "you were added", and its
                    // job is to make sure a channel never just appears in someone's
                    // sidebar with no explanation of where it came from.
                    if ($added !== []) {
                        $this->notifications->sync(
                            new ChannelInviteBlueprint($channel, $actor),
                            $added
                        );
                    }

                    return $channel;
                })
                ->defaultInclude(['participants']),

            // Removing someone else. `leave` is the self-service counterpart; this is
            // the moderation one, and it is a separate endpoint precisely so the
            // permission check is not a branch inside `leave` that has to distinguish
            // "me" from "them" on every ordinary departure.
            Endpoint\Endpoint::make('removeMember')
                ->route('POST', '/{id}/members/remove')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('manageMembers', $channel)) {
                        throw new ForbiddenException();
                    }

                    $userId = (int) Arr::get($context->body(), 'data.attributes.userId', 0);

                    // Visibility-scoped for the same reason `addMembers` is: an id
                    // that resolves differently depending on who asks must not become
                    // a way to probe for accounts.
                    $user = $userId > 0
                        ? User::query()->whereVisibleTo($actor)->whereKey($userId)->first()
                        : null;

                    if ($user === null) {
                        throw new ValidationException([
                            'userId' => $this->translator->trans('ramon-chat.api.members_empty'),
                        ]);
                    }

                    // Removing yourself is what `leave` is for. Routing it here would
                    // work, but it would let someone without manageMembers be refused
                    // permission to leave a channel they are standing in.
                    if ((int) $user->id === (int) $actor->id) {
                        throw new ValidationException([
                            'userId' => $this->translator->trans('ramon-chat.api.cannot_remove_self'),
                        ]);
                    }

                    // A moderator must not be able to eject someone who outranks them;
                    // otherwise the weaker permission removes the stronger one, and two
                    // moderators can take turns throwing each other out. Administrators
                    // are exempt — the whole point of the role is that it is final.
                    if (! $actor->isAdmin() && $user->can('ramon-chat.moderate')) {
                        throw new ForbiddenException();
                    }

                    $membership = $this->memberships->leave($channel, $user);

                    // Not a member — nothing to do, and reporting success on a no-op
                    // would tell the caller a removal happened that did not.
                    if ($membership === null) {
                        throw new ValidationException([
                            'userId' => $this->translator->trans('ramon-chat.api.not_a_member'),
                        ]);
                    }

                    $this->events->dispatch(new UserLeftChannel($channel, $user, $actor));

                    return $channel;
                })
                ->defaultInclude(['participants']),

            Endpoint\Endpoint::make('leave')
                ->route('POST', '/{id}/leave')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('view', $channel)) {
                        throw new ForbiddenException();
                    }

                    $this->memberships->leave($channel, $actor);

                    $this->events->dispatch(new UserLeftChannel($channel, $actor, $actor));
                })
                ->response(fn () => new EmptyResponse(204)),

            // Per-channel notification preferences: level, mute.
            Endpoint\Endpoint::make('notifications')
                ->route('POST', '/{id}/notifications')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('view', $channel)) {
                        throw new ForbiddenException();
                    }

                    $attributes = Arr::get($context->body(), 'data.attributes', []);

                    $this->memberships->updatePreferences(
                        $channel,
                        $actor,
                        Arr::has($attributes, 'notificationLevel')
                            ? (int) Arr::get($attributes, 'notificationLevel')
                            : null,
                        Arr::has($attributes, 'muted')
                            ? (bool) Arr::get($attributes, 'muted')
                            : null
                    );
                })
                ->response(fn () => new EmptyResponse(204)),

            Endpoint\Endpoint::make('status')
                ->route('POST', '/{id}/status')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    $status = (string) Arr::get($context->body(), 'data.attributes.status', '');

                    if (! in_array($status, [Channel::STATUS_OPEN, Channel::STATUS_CLOSED], true)) {
                        throw new ValidationException([
                            'status' => $this->translator->trans('ramon-chat.api.invalid_channel_status'),
                        ]);
                    }

                    if (! $actor->can('close', $channel)) {
                        throw new ForbiddenException();
                    }

                    $previous = $channel->status;
                    $channel->status = $status;
                    $channel->save();

                    $this->events->dispatch(new ChannelStatusChanged($channel, $previous, $actor));

                    return $channel;
                }),

            Endpoint\Endpoint::make('archive')
                ->route('POST', '/{id}/archive')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('archive', $channel)) {
                        throw new ForbiddenException();
                    }

                    $attributes = Arr::get($context->body(), 'data.attributes', []);

                    $this->archiver->archive(
                        $channel,
                        $actor,
                        discussionId: Arr::get($attributes, 'discussionId') !== null
                            ? (int) Arr::get($attributes, 'discussionId')
                            : null,
                        title: Arr::get($attributes, 'title')
                    );

                    return $channel;
                }),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('type')
                ->writableOnCreate()
                ->requiredOnCreate(),

            Schema\Str::make('name')
                ->nullable()
                ->maxLength(100)
                ->writable(fn (Channel $c, Context $context) => $this->mayWrite($c, $context)),

            Schema\Str::make('slug')
                ->nullable(),

            Schema\Str::make('description')
                ->nullable()
                ->maxLength(1000)
                ->writable(fn (Channel $c, Context $context) => $this->mayWrite($c, $context)),

            // Either a Unicode pictograph (what the picker stores) or a bare
            // shortcode (what an API client or an older row may carry). Anything
            // else was previously accepted and rendered as literal text, e.g.
            // ":speech_balloon:" spilling out of a 38px avatar circle.
            // Read-only: the picture is set through its own multipart endpoint,
            // not by writing a URL, so there is nothing for a client to assign here.
            Schema\Str::make('imageUrl')
                ->get(fn (Channel $c) => $c->imageUrl()),

            Schema\Str::make('emoji')
                ->nullable()
                ->maxLength(60)
                ->regex('/^(?:[a-z0-9_+\-]{1,60}|[\p{Extended_Pictographic}\x{FE0F}\x{200D}\x{1F3FB}-\x{1F3FF}]{1,16})$/u')
                ->writable(fn (Channel $c, Context $context) => $this->mayWrite($c, $context)),

            Schema\Str::make('status'),

            // Public or invitation-only. Guarded by mayWriteCategoryField for the
            // same reason tagId is: both decide who can see the channel, and neither
            // is something a direct channel's creator may set.
            Schema\Boolean::make('isPrivate')
                ->get(fn (Channel $c) => $c->isPrivate())
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context)),

            // Who may post: everyone who can be here, or moderators only.
            // Validated against the two known values rather than trusted, so a
            // typo cannot silently produce a channel nobody can post in.
            Schema\Str::make('postPermission')
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context))
                ->set(function (Channel $channel, $value) {
                    $channel->post_permission = $value === Channel::POST_MODERATORS
                        ? Channel::POST_MODERATORS
                        : Channel::POST_ALL;
                }),

            Schema\Boolean::make('threadingEnabled')
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context)),

            // Slow mode, in seconds. 0 is off.
            //
            // Editable by whoever may edit the channel rather than by
            // administrators only: it is a dial for a conversation moving too
            // fast to follow, and the person who notices that is the one already
            // looking after the room.
            //
            // Clamped rather than rejected. The interface offers a fixed set of
            // steps, so an out-of-range value means a client got it wrong, and
            // failing the whole save over it would be theatre. Six hours is the
            // ceiling, matching Discord's.
            Schema\Integer::make('slowModeSeconds')
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context))
                ->set(function (Channel $channel, $value) {
                    $channel->slow_mode_seconds = max(0, min(21600, (int) $value));
                }),

            // What the composer counts down from. Per-actor and answered by the
            // server: a reload would otherwise forget the cooldown and offer a
            // send that is then refused.
            Schema\Integer::make('slowModeRemaining')
                ->get(fn (Channel $c, Context $context) => $c->exists
                    ? resolve(SlowMode::class)->remainingFor($c, $context->getActor())
                    : 0),

            Schema\Boolean::make('autoJoin')
                ->writable(fn (Channel $c, Context $context) => $context->getActor()->isAdmin()
                    && ! ($c->exists && $c->isDirect())),

            // Grows the channel from participation in its bound category, rather
            // than adding every account up front like autoJoin does.
            // Carries the bound category's new discussions into the channel.
            Schema\Boolean::make('postDiscussions')
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context)),

            Schema\Boolean::make('autoJoinOnReply')
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context)),

            Schema\Boolean::make('allowChannelWideMentions')
                ->writable(fn (Channel $c, Context $context) => $this->mayWrite($c, $context)),

            Schema\Integer::make('tagId')
                ->nullable()
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context)),

            Schema\Integer::make('messagesCount'),
            Schema\Integer::make('userCount'),
            Schema\Integer::make('lastMessageId')->nullable(),
            Schema\DateTime::make('lastMessageAt')->nullable(),
            Schema\DateTime::make('createdAt'),
            Schema\DateTime::make('archivedAt')->nullable(),
            Schema\Integer::make('archivedDiscussionId')->nullable(),

            // ── Display helpers ──────────────────────────────────────────────
            Schema\Str::make('displayName')
                ->get(fn (Channel $c, Context $context) => $this->displayName($c, $context->getActor())),

            // ── Per-actor membership state ───────────────────────────────────
            Schema\Boolean::make('isFollowing')
                ->get(fn (Channel $c, Context $context) => $this->membership($c, $context->getActor())?->following ?? false),

            Schema\Boolean::make('isMuted')
                ->get(fn (Channel $c, Context $context) => $this->membership($c, $context->getActor())?->muted ?? false),

            Schema\Integer::make('notificationLevel')
                ->get(fn (Channel $c, Context $context) => $this->membership($c, $context->getActor())?->notification_level
                    ?? ChannelUser::LEVEL_MENTIONS),

            Schema\Integer::make('lastReadMessageId')
                ->get(fn (Channel $c, Context $context) => $this->membership($c, $context->getActor())?->last_read_message_id ?? 0),

            Schema\Integer::make('unreadCount')
                ->get(fn (Channel $c, Context $context) => $this->membership($c, $context->getActor())?->unread_count ?? 0),

            Schema\Integer::make('unreadMentionsCount')
                ->get(fn (Channel $c, Context $context) => $this->membership($c, $context->getActor())?->unread_mentions_count ?? 0),

            // ── Capability flags, so the client never renders a dead control ──
            Schema\Boolean::make('canPostMessage')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('postMessage', $c)),

            Schema\Boolean::make('canEdit')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('edit', $c)),

            // Two distinct affordances: rejoin, and rejoin unseen.
            Schema\Boolean::make('canJoinHidden')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('joinHidden', $c)),

            // So a lurking moderator can tell they are lurking; without it the UI
            // looks identical to an ordinary membership and the distinction is lost.
            Schema\Boolean::make('isHiddenMember')
                ->get(fn (Channel $c, Context $context) => (bool) ($this->membership($c, $context->getActor())?->hidden ?? false)),

            Schema\Boolean::make('canJoin')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('join', $c)),

            Schema\Boolean::make('canClose')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('close', $c)),

            Schema\Boolean::make('canArchive')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('archive', $c)),

            Schema\Boolean::make('canDelete')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('delete', $c)),

            Schema\Boolean::make('canManageMembers')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('manageMembers', $c)),

            Schema\Boolean::make('canMentionChannelWide')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('mentionChannelWide', $c)),

            Schema\Relationship\ToOne::make('creator')
                ->type('users')
                ->includable(),

            Schema\Relationship\ToOne::make('lastMessage')
                ->type('chat-messages')
                ->includable(),

            // `includable()` is what makes `?include=participants` legal; without it
            // the request is rejected outright with a 400 rather than merely omitting
            // the relationship, which is what the info panel's member tab asks for.
            Schema\Relationship\ToMany::make('participants')
                ->type('users')
                ->includable()
                ->visible(fn (Channel $c, Context $context) => $context->getActor()->can('viewMembers', $c)),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('lastMessageAt'),
            SortColumn::make('name'),
            SortColumn::make('messagesCount'),
            SortColumn::make('createdAt'),
        ];
    }


    /**
     * Whether the actor may write a configurable channel field.
     *
     * Creation and editing are different rights. Gating writes on `edit` alone
     * would mean a user holding only `createChannel` could create a channel but
     * not name or describe it, because ChannelPolicy::edit falls through to
     * `moderate` for a channel with no creator set yet.
     */
    protected function mayWrite(Channel $channel, Context $context): bool
    {
        if (! $channel->exists) {
            return $context->getActor()->can('createChannel');
        }

        return $context->getActor()->can('edit', $channel);
    }

    /**
     * Whether the actor may write a field that only exists for category channels —
     * the bound tag, threading, and the two auto-join modes.
     *
     * Belt to ChannelPolicy::edit's braces. Even a moderator editing a direct
     * channel must not be able to bind it to a tag: visibility for a direct channel
     * comes from its participant list, and a tag would put a second, conflicting
     * rule in play.
     */
    protected function mayWriteCategoryField(Channel $channel, Context $context): bool
    {
        if ($channel->exists && $channel->isDirect()) {
            return false;
        }

        return $this->mayWrite($channel, $context);
    }

    /**
     * Memoises the actor's membership: the channel list serialises several
     * membership-backed fields per row, and each would otherwise be its own query.
     *
     * Keyed by channel *and* actor. It was keyed by channel alone, on the
     * assumption that a resource instance never outlives one request and therefore
     * only ever sees one actor. That assumption does not hold — the container hands
     * back the same instance for as long as it lives — and when it broke, one
     * user's membership was served in another user's response: `isFollowing` and
     * `isHiddenMember` both came back describing whoever was serialised first.
     *
     * @var array<string, ChannelUser|null|false>
     */
    protected array $membershipCache = [];

    protected function membership(Channel $channel, User $actor): ?ChannelUser
    {
        if (! $actor->exists) {
            return null;
        }

        $key = $channel->id.':'.$actor->id;

        if (! array_key_exists($key, $this->membershipCache)) {
            $this->membershipCache[$key] = $channel->membershipFor($actor);
        }

        return $this->membershipCache[$key] ?: null;
    }

    /**
     * Direct channels have no stored name — they are labelled by the other
     * participants, from the perspective of whoever is reading.
     */
    protected function displayName(Channel $channel, User $actor): string
    {
        if (! $channel->isDirect()) {
            return (string) ($channel->name ?? '');
        }

        if ($channel->name !== null && $channel->name !== '') {
            return $channel->name;
        }

        $others = $channel->participants
            ->reject(fn (User $u) => $u->id === $actor->id)
            ->map(fn (User $u) => $u->display_name)
            ->values();

        if ($others->isEmpty()) {
            return $this->translator->trans('ramon-chat.api.direct_channel_self');
        }

        return $others->take(3)->join(', ').(
            $others->count() > 3
                ? ' +'.($others->count() - 3)
                : ''
        );
    }
}
