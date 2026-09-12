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
use Ramon\Chat\Access\ScopeChannelVisibility;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\ChannelStatusChanged;
use Ramon\Chat\Event\ChannelWasCreated;
use Ramon\Chat\Event\ChannelWasDeleted;
use Ramon\Chat\Event\ChannelWasEdited;
use Ramon\Chat\Event\InviteWasCancelled;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UserLeftChannel;
use Ramon\Chat\Event\UserWasInvited;
use Ramon\Chat\Service\ChannelArchiver;
use Ramon\Chat\Service\ChannelOwnership;
use Ramon\Chat\Service\InvitationManager;
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
        protected ChannelOwnership $ownership,
        protected InvitationManager $invitations
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
                ->eagerLoad(['creator', 'lastMessage.user'])
                ->eagerLoadWhere(
                    'actorMembership',
                    fn ($query, Context $context) => $query
                        ->where('user_id', $context->getActor()->id)
                        ->whereNull('left_at')
                )
                ->eagerLoadWhere(
                    'actorInvite',
                    fn ($query, Context $context) => $query
                        ->where('user_id', $context->getActor()->id)
                )
                // Restricted to direct channels: a category channel's label comes
                // from its own name, and loading its whole membership to find that
                // out is exactly the query this is meant to save.
                ->eagerLoadWhere(
                    'directParticipants',
                    fn ($query, Context $context) => $query
                        ->whereIn(
                            'chat_channel_user.channel_id',
                            Channel::query()
                                ->where('type', Channel::TYPE_DIRECT)
                                ->select('chat_channels.id')
                        )
                        // The reader is never part of their own label, and never one
                        // of the avatars either — `displayName()` rejects them and so
                        // does `Channel#others()` on the client. Dropped in SQL so the
                        // row is not loaded, and so the actor's own user record is not
                        // dragged into `included` on every channel list, where its
                        // three unread counters each cost a query.
                        ->where('users.id', '!=', (int) $context->getActor()->id)
                )
                // Serialised as well as loaded. The relation was already being
                // fetched for the label; sending it costs nothing further and is
                // what lets the sidebar draw a conversation's avatars on the
                // first paint instead of after the members tab is opened.
                ->defaultInclude(['directParticipants']),

            Endpoint\Index::make()
                ->authenticated()
                ->defaultSort('-lastMessageAt')
                ->eagerLoad(['creator', 'lastMessage.user'])
                // Seven fields on every row read the actor's membership, and the
                // posting and joining policies read it again. Loaded once for the
                // page instead of once per channel — `membershipFor()` checks the
                // row belongs to the actor before trusting it.
                ->eagerLoadWhere(
                    'actorMembership',
                    fn ($query, Context $context) => $query
                        ->where('user_id', $context->getActor()->id)
                        ->whereNull('left_at')
                )
                ->eagerLoadWhere(
                    'actorInvite',
                    fn ($query, Context $context) => $query
                        ->where('user_id', $context->getActor()->id)
                )
                // Restricted to direct channels: a category channel's label comes
                // from its own name, and loading its whole membership to find that
                // out is exactly the query this is meant to save.
                ->eagerLoadWhere(
                    'directParticipants',
                    fn ($query, Context $context) => $query
                        ->whereIn(
                            'chat_channel_user.channel_id',
                            Channel::query()
                                ->where('type', Channel::TYPE_DIRECT)
                                ->select('chat_channels.id')
                        )
                        // The reader is never part of their own label, and never one
                        // of the avatars either — `displayName()` rejects them and so
                        // does `Channel#others()` on the client. Dropped in SQL so the
                        // row is not loaded, and so the actor's own user record is not
                        // dragged into `included` on every channel list, where its
                        // three unread counters each cost a query.
                        ->where('users.id', '!=', (int) $context->getActor()->id)
                )
                ->defaultInclude(['directParticipants'])
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

                    // Accepting an invitation is joining. Consumed first, so the
                    // membership is created knowing it answers one, and the
                    // announcement can say who asked them in.
                    $invite = $this->invitations->accept($channel, $actor);

                    $this->memberships->join($channel, $actor, hidden: $hidden);

                    $this->events->dispatch(new UserJoinedChannel(
                        $channel,
                        $actor,
                        $actor,
                        $hidden,
                        acceptedInvite: $invite !== null,
                        invitedBy: $invite?->inviter
                    ));

                    // The record as it now stands, capability flags included. The
                    // client draws the composer from `canPostMessage`, and a bare
                    // 204 left it reading the pre-join answer until a reload: the
                    // channel looked closed to someone who had just joined it.
                    return $channel;
                }),

            // Inviting other people. Separate from `join`, which is the actor
            // letting themselves in: this asks someone else into a room, and for a
            // private channel it is the only way in. Nobody is put in a channel
            // without a say: the invitee gets a notification with the choice, and
            // accepting it is what creates the membership.
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

                    // Members and people already invited are skipped inside, so a
                    // repeated request cannot pile up invites or re-notify anyone.
                    $invited = $this->invitations->invite($channel, $users, $actor);

                    foreach ($invited as $invite) {
                        $this->events->dispatch(new UserWasInvited($channel, $invite->user, $actor, $invite));
                    }

                    $channel->unsetRelation('invitedUsers');

                    return $channel;
                })
                ->defaultInclude(['participants', 'invitedUsers']),

            // Taking an invitation back before it is answered. The counterpart of
            // `removeMember` for people who are not members yet. The invitee's own
            // answers live on their own routes (Controller\AcceptInviteController,
            // Controller\DeclineInviteController): a private channel is not
            // visible to them yet, so a model-scoped endpoint could not find it.
            Endpoint\Endpoint::make('cancelInvite')
                ->route('POST', '/{id}/invites/cancel')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('manageMembers', $channel)) {
                        throw new ForbiddenException();
                    }

                    $userId = (int) Arr::get($context->body(), 'data.attributes.userId', 0);

                    $user = $userId > 0
                        // @phpstan-ignore method.notFound (Flarum model scope)
                        ? User::query()->whereVisibleTo($actor)->whereKey($userId)->first()
                        : null;

                    if ($user === null) {
                        throw new ValidationException([
                            'userId' => $this->translator->trans('ramon-chat.api.members_empty'),
                        ]);
                    }

                    $invite = $this->invitations->cancel($channel, $user);

                    if ($invite === null) {
                        throw new ValidationException([
                            'userId' => $this->translator->trans('ramon-chat.api.invite_not_found'),
                        ]);
                    }

                    $this->events->dispatch(new InviteWasCancelled($channel, $user, $actor, $invite->inviter));

                    $channel->unsetRelation('invitedUsers');

                    return $channel;
                })
                ->defaultInclude(['participants', 'invitedUsers']),

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

                    // The same rule inside the room: a channel moderator must not
                    // eject the owner or a fellow moderator. Only whoever controls
                    // the channel — its owner, or a chat moderator — may.
                    $target = $channel->membershipFor($user);

                    if (! $this->ownership->controls($actor, $channel)
                        && ((int) $channel->creator_id === (int) $user->id
                            || ($target !== null && $target->isModerator()))) {
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

                    $this->events->dispatch(new UserLeftChannel($channel, $user, $actor, $membership->isHidden()));

                    return $channel;
                })
                ->defaultInclude(['participants']),

            // Making a member a moderator of this channel, and undoing it. What
            // the role grants is decided by ChannelOwnership; who may hand it out
            // by ChannelPolicy::manageModerators.
            Endpoint\Endpoint::make('promoteModerator')
                ->route('POST', '/{id}/moderators')
                ->authenticated()
                ->action(fn (Context $context) => $this->setModerator($context, true))
                ->defaultInclude(['participants']),

            Endpoint\Endpoint::make('demoteModerator')
                ->route('POST', '/{id}/moderators/remove')
                ->authenticated()
                ->action(fn (Context $context) => $this->setModerator($context, false))
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

                    $membership = $this->memberships->leave($channel, $actor);

                    $this->events->dispatch(
                        new UserLeftChannel($channel, $actor, $actor, (bool) $membership?->isHidden())
                    );
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
            //
            // Public and private are one right, not two: whoever may create a
            // channel chooses which kind. A separate permission was tried and
            // withdrawn — it split a single decision across two checkboxes for no
            // gain an operator asked for.
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

            // How long a message may be here. Null means the channel follows
            // the forum-wide setting rather than "no limit" — see
            // Channel::maxMessageLength().
            //
            // Clamped like slow mode, and for the same reason: the form offers a
            // fixed set of steps, so a value outside the range means a client got
            // it wrong. The floor is 100 because a cap below that is a channel
            // nobody can write a sentence in; the ceiling matches the widest the
            // forum setting itself accepts.
            Schema\Integer::make('maxMessageLength')
                ->nullable()
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context))
                ->set(function (Channel $channel, $value) {
                    $channel->max_message_length = ($value === null || (int) $value <= 0)
                        ? null
                        : max(100, min(50000, (int) $value));
                }),

            // What the composer counts down from. Per-actor and answered by the
            // server: a reload would otherwise forget the cooldown and offer a
            // send that is then refused.
            Schema\Integer::make('slowModeRemaining')
                ->get(fn (Channel $c, Context $context) => $c->exists
                    ? resolve(SlowMode::class)->remainingFor($c, $context->getActor())
                    : 0),

            // Same gate as every other field on the form, deliberately: whoever may
            // create a channel fills in the whole form, and auto-join is part of it.
            //
            // It used to be administrator-only, and that was the cause of "I have
            // the permission and still cannot create a channel". json-api-server
            // answers 403 "Field [autoJoin] is not writable" for a field merely
            // *present* in the body while not writable for the actor, and
            // ChannelFormModal sends `autoJoin` on every save — `false` included.
            // So one admin-only attribute refused every channel creation by a
            // non-administrator, whatever the forum had granted. Administrators
            // never saw it, because they pass the gate.
            //
            // The cost is real and accepted: creating a channel with this on adds
            // every eligible account at once (AutoJoinUsers, chunked at 500), so a
            // large forum pays for it in membership rows.
            Schema\Boolean::make('autoJoin')
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context)),

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
                ->get(fn (Channel $c, Context $context) => (bool) $this->membership($c, $context->getActor())?->isHidden()),

            Schema\Boolean::make('canJoin')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('join', $c)),

            // A pending invitation for the reader, and who sent it. Read from the
            // `actorInvite` relation the list endpoints eager-load, so the
            // sidebar pays nothing per row for the common answer of "none".
            Schema\Boolean::make('isInvited')
                ->get(fn (Channel $c, Context $context) => $c->pendingInviteFor($context->getActor()) !== null),

            Schema\Integer::make('invitedById')
                ->nullable()
                ->get(fn (Channel $c, Context $context) => $c->pendingInviteFor($context->getActor())?->inviter_id),

            Schema\Str::make('invitedByName')
                ->nullable()
                ->get(fn (Channel $c, Context $context) => $c->pendingInviteFor($context->getActor())?->inviter?->display_name),

            Schema\Boolean::make('canClose')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('close', $c)),

            Schema\Boolean::make('canArchive')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('archive', $c)),

            Schema\Boolean::make('canDelete')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('delete', $c)),

            Schema\Boolean::make('canManageMembers')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('manageMembers', $c)),

            Schema\Boolean::make('canManageModerators')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('manageModerators', $c)),

            // So the members tab can label the owner without loading the creator.
            Schema\Integer::make('creatorId')
                ->property('creator_id')
                ->nullable(),

            // Which members hold the channel's own moderator role. Read off the
            // participants relation when it is loaded — the members tab asks for
            // it — and empty otherwise, so the channel list pays no query per row.
            Schema\Arr::make('moderatorIds')
                ->get(function (Channel $c) {
                    if (! $c->relationLoaded('participants')) {
                        return [];
                    }

                    return $c->participants
                        ->filter(fn (User $user) => (bool) ($user->pivot->is_moderator ?? false))
                        ->map(fn (User $user) => (int) $user->id)
                        ->values()
                        ->all();
                }),

            Schema\Boolean::make('canMentionChannelWide')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('mentionChannelWide', $c)),

            Schema\Relationship\ToOne::make('creator')
                ->type('users')
                ->includable(),

            // Only where the contents may be read: an invitee to a private
            // channel sees the row, not its last message.
            Schema\Relationship\ToOne::make('lastMessage')
                ->type('chat-messages')
                ->includable()
                ->visible(fn (Channel $c, Context $context) => ScopeChannelVisibility::readsContents($context->getActor(), $c)),

            // `includable()` is what makes `?include=participants` legal; without it
            // the request is rejected outright with a 400 rather than merely omitting
            // the relationship, which is what the info panel's member tab asks for.
            Schema\Relationship\ToMany::make('participants')
                ->type('users')
                ->includable()
                ->visible(fn (Channel $c, Context $context) => $context->getActor()->can('viewMembers', $c)),

            // Who has been asked in and not answered yet. Only for whoever can
            // manage the member list: a pending invite is between the channel's
            // managers and the person invited, not something the room reads.
            Schema\Relationship\ToMany::make('invitedUsers')
                ->type('users')
                ->includable()
                ->visible(fn (Channel $c, Context $context) => $context->getActor()->can('manageMembers', $c)),

            /*
             * The other side of a direct channel, for the avatars the sidebar draws
             * in place of a channel icon.
             *
             * `participants` cannot serve this. That one is what the members tab
             * asks for, and default-including it on the channel list would ship
             * every member of every category channel on every page load. This
             * relation is eager-loaded only for direct channels — see the
             * `eagerLoadWhere` on Index and Show — so default-including it costs
             * the single row a conversation actually has.
             *
             * Without it the sidebar had no participants to draw and fell back to
             * an envelope, and the avatars appeared only once something else had
             * fetched `?include=participants` into the store: opening the channel's
             * settings and switching to the Members tab.
             */
            Schema\Relationship\ToMany::make('directParticipants')
                ->type('users')
                ->includable()
                ->visible(fn (Channel $c, Context $context) => $c->isDirect() && $context->getActor()->can('viewMembers', $c)),
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

    /**
     * Sets or clears the channel-moderator role on a current member.
     *
     * Only on a live membership: the role is a fact about someone being in the
     * room, and granting it to somebody who is not would give them nothing
     * today and a surprise the day they are added back.
     */
    protected function setModerator(Context $context, bool $moderator): Channel
    {
        /** @var Channel $channel */
        $channel = $context->model;
        $actor = $context->getActor();

        if (! $actor->can('manageModerators', $channel)) {
            throw new ForbiddenException();
        }

        $userId = (int) Arr::get($context->body(), 'data.attributes.userId', 0);

        $user = $userId > 0
            ? User::query()->whereVisibleTo($actor)->whereKey($userId)->first()
            : null;

        if ($user === null) {
            throw new ValidationException([
                'userId' => $this->translator->trans('ramon-chat.api.members_empty'),
            ]);
        }

        $membership = $channel->membershipFor($user);

        if ($membership === null || $membership->hasLeft()) {
            throw new ValidationException([
                'userId' => $this->translator->trans('ramon-chat.api.not_a_member'),
            ]);
        }

        if ($membership->isModerator() !== $moderator) {
            $membership->is_moderator = $moderator;
            $membership->save();
        }

        // The response includes participants; a copy loaded before the change
        // would carry the old role on its pivot.
        $channel->unsetRelation('participants');

        return $channel;
    }

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

        // The eager-loaded set when the endpoint provided one, so a sidebar of
        // direct channels costs one query rather than one per conversation.
        $members = $channel->relationLoaded('directParticipants')
            ? $channel->getRelation('directParticipants')
            : $channel->participants;

        $others = $members
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
