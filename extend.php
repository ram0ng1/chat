<?php

/*
 * This file is part of ramon/chat.
 *
 * Copyright (c) Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat;

use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use League\Flysystem\Visibility;
use Ramon\Chat\Service\UnreadTracker;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // Serves the lazily loaded chunks webpack splits out of forum.js — the
        // 154 KB emoji map among them. Without this the dynamic import 404s and
        // shortcodes silently stop resolving beyond the small built-in set.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        // Everything the chat draws on first paint, in the boot payload, so the
        // page opens complete instead of filling in over three round trips. Runs
        // for chat routes only — see PreloadChat, which guards on the route name.
        ->content(Content\PreloadChat::class)
        // Full-screen mode. The drawer is rendered over whatever page is open,
        // so it needs no route of its own.
        ->route('/chat', 'chat.index', Frontend\RequireChatAccess::class)
        ->route('/chat/c/{id}', 'chat.channel', Frontend\RequireChatAccess::class)
        ->route('/chat/c/{id}/t/{threadId:\d+}', 'chat.thread', Frontend\RequireChatAccess::class)
        ->route('/chat/browse', 'chat.browse', Frontend\RequireChatAccess::class)
        ->route('/chat/browse/{filter}', 'chat.browse.filter', Frontend\RequireChatAccess::class)
        ->route('/chat/threads', 'chat.threads', Frontend\RequireChatAccess::class)
        ->route('/chat/search', 'chat.search', Frontend\RequireChatAccess::class)
        // Both halves are needed for every chat route: this one so a direct load or
        // a refresh of the URL is served the forum page at all, and the matching
        // `app.routes[...]` in js/src/forum/index.tsx so the client knows what to
        // mount once it boots. Registering only the client side leaves a route that
        // works while navigating and 404s on reload.
        ->route('/chat/bookmarks', 'chat.bookmarks', Frontend\RequireChatAccess::class)

        // The moderation queue. Behind the same access gate as every other chat
        // route; the queue's own contents are gated again by `ramon-chat.moderate`,
        // both on the endpoints and in the resource's scope.
        ->route('/chat/flags', 'chat.flags', Frontend\RequireChatAccess::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    // Backs the `ramon-chat::emails.*` views used by ChatMentionBlueprint.
    (new Extend\View())
        ->namespace('ramon-chat', __DIR__.'/views'),

    (new Extend\ServiceProvider())
        ->register(ChatServiceProvider::class),

    // ── Storage for message attachments ──────────────────────────────────────
    (new Extend\Filesystem())
        ->disk('chat', function (Paths $paths, UrlGenerator $url) {
            return [
                'root'       => "$paths->public/assets/chat",
                'url'        => $url->to('forum')->path('assets/chat'),
                'visibility' => Visibility::PUBLIC,
            ];
        }),

    // ── Model wiring ─────────────────────────────────────────────────────────
    (new Extend\Model(User::class))
        ->belongsToMany('chatChannels', Channel::class, 'chat_channel_user', 'user_id', 'channel_id')
        ->hasMany('chatMessages', Message::class, 'user_id'),

    (new Extend\ModelVisibility(Channel::class))
        ->scope(Access\ScopeChannelVisibility::class),

    (new Extend\ModelVisibility(Message::class))
        ->scope(Access\ScopeMessageVisibility::class),

    (new Extend\ModelVisibility(Thread::class))
        ->scope(Access\ScopeThreadVisibility::class),

    (new Extend\Policy())
        ->modelPolicy(Channel::class, Access\ChannelPolicy::class)
        ->modelPolicy(Message::class, Access\MessagePolicy::class)
        ->modelPolicy(Thread::class, Access\ThreadPolicy::class)
        ->globalPolicy(Access\GlobalPolicy::class),

    // ── API ──────────────────────────────────────────────────────────────────
    new Extend\ApiResource(Api\Resource\ChannelResource::class),
    new Extend\ApiResource(Api\Resource\MessageResource::class),
    new Extend\ApiResource(Api\Resource\ThreadResource::class),
    new Extend\ApiResource(Api\Resource\UploadResource::class),
    new Extend\ApiResource(Api\Resource\WebhookResource::class),
    new Extend\ApiResource(Api\Resource\MessageFlagResource::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canUseChat')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('useChat')),

            Schema\Boolean::make('canCreateChatChannel')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('createChannel')),

            // Drives the @here / @all rows in the composer's autocomplete. Offering
            // them to someone the server would refuse is worse than not offering.
            Schema\Boolean::make('canMentionChatChannelWide')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.mentionChannelWide')),

            // Gates the "move messages" control in the selection bar, which is the
            // one selection action MoveMessagesController requires `moderate` for.
            Schema\Boolean::make('canModerateChat')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.moderate')),

            // The paperclip is drawn from this. The permission was enforced only in
            // UploadController, so someone without it still saw the control and got
            // a 403 on use — the server was right and the interface was lying.
            // The sticker button is drawn from this. Enforced server-side too, in
            // the message dispatcher — a hidden button is a courtesy, not a gate.
            Schema\Boolean::make('canSendChatStickers')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.sendStickers')),

            Schema\Boolean::make('canUploadChatFiles')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.upload')),

            Schema\Boolean::make('canStartChatDirect')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('startDirect')),

            // Draws the report button. The policy still decides per message — you
            // cannot report your own, or one already deleted — so this only says
            // whether the actor may report anything at all.
            Schema\Boolean::make('canFlagChatMessages')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.flagMessage')),

            // The composer restarts its own countdown after a send rather than
            // waiting for the server to tell it to, so it needs to know who the
            // wait does not apply to. `slowModeRemaining` already answers this on
            // load — this is the same answer, for the moment there is no fresh
            // channel payload to read it from.
            Schema\Boolean::make('canBypassChatSlowMode')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.bypassSlowMode')),

            // The badge on the moderation link, and the link itself. Counted only
            // for moderators, so the ordinary page load never runs this query.
            Schema\Integer::make('chatOpenFlagsCount')
                ->get(function ($forum, Context $context) {
                    $actor = $context->getActor();

                    if (! $actor->hasPermission('ramon-chat.moderate')) {
                        return 0;
                    }

                    // `whereHas` before `whereNull`: the latter is typed as
                    // returning a query builder rather than an Eloquent one, so
                    // the relation method is not available after it.
                    //
                    // `whereVisibleTo` is registered by Flarum's
                    // ScopeVisibilityTrait as a model scope, which static analysis
                    // cannot see on a builder instance.
                    return MessageFlag::query()
                        // @phpstan-ignore method.notFound (Flarum model scope)
                        ->whereHas('message', fn ($query) => $query->whereVisibleTo($actor))
                        ->whereNull('resolved_at')
                        ->count();
                }),
        ]),

    (new Extend\ApiResource(UserResource::class))
        ->fields(fn () => [
            // Only ever exposed to the user themselves — unread state is private.
            // Each counts through the channels the actor can currently see; see
            // UnreadTracker::visibleMemberships() for why a stored counter is
            // not enough on its own.
            Schema\Integer::make('chatUnreadChannelsCount')
                ->visible(fn (User $user, Context $context) => $context->getActor()->is($user))
                ->get(fn (User $user) => resolve(UnreadTracker::class)->totalUnreadFor($user)),

            // The message count, not the channel count: the drawer header shows
            // "how much am I behind", which is a number of messages.
            Schema\Integer::make('chatUnreadMessagesCount')
                ->visible(fn (User $user, Context $context) => $context->getActor()->is($user))
                ->get(fn (User $user) => resolve(UnreadTracker::class)->totalUnreadMessagesFor($user)),

            Schema\Integer::make('chatUnreadMentionsCount')
                ->visible(fn (User $user, Context $context) => $context->getActor()->is($user))
                ->get(fn (User $user) => resolve(UnreadTracker::class)->totalUnreadMentionsFor($user)),
        ]),

    // ── Settings ─────────────────────────────────────────────────────────────
    (new Extend\Settings())
        ->default('ramon-chat.channel_retention_days', 90)
        ->default('ramon-chat.dm_retention_days', 0)
        ->default('ramon-chat.max_messages_per_second', 2)
        ->default('ramon-chat.min_message_length', 1)
        ->default('ramon-chat.max_message_length', 3000)
        ->default('ramon-chat.message_edit_window_minutes', 0)
        ->default('ramon-chat.allow_uploads', true)
        ->default('ramon-chat.max_upload_size', 10485760)
        ->default('ramon-chat.allow_archiving_channels', true)
        ->default('ramon-chat.threading_default', false)
        // Whether realtime pushes go through the queue instead of running in the
        // request that caused them. Off by default: Flarum's database queue runs
        // its worker once a minute, and a chat message that arrives a minute late
        // has not arrived. Turn it on only alongside a worker that runs
        // continuously — see Realtime\ChatBroadcaster. Never serialised to the
        // forum: it changes nothing the client can see or act on.
        ->default('ramon-chat.queue_realtime', false)
        // Notification sound. 'none' disables it; the others name a file under
        // assets/sounds, published to public/assets/extensions/ramon-chat.
        ->default('ramon-chat.notification_sound', 'chime')
        // Branding: the label and icon used by the header button and the drawer
        // header. Empty title falls back to the translated "Chat".
        ->default('ramon-chat.title', '')
        ->default('ramon-chat.icon', 'fas fa-comments')
        ->default('ramon-chat.show_icon', true)
        ->serializeToForum('ramon-chat.maxMessageLength', 'ramon-chat.max_message_length', 'intval')
        ->serializeToForum('ramon-chat.minMessageLength', 'ramon-chat.min_message_length', 'intval')
        ->serializeToForum('ramon-chat.maxUploadSize', 'ramon-chat.max_upload_size', 'intval')
        ->serializeToForum('ramon-chat.allowUploads', 'ramon-chat.allow_uploads', 'boolval')
        ->serializeToForum('ramon-chat.threadingDefault', 'ramon-chat.threading_default', 'boolval')
        ->serializeToForum('ramon-chat.notificationSound', 'ramon-chat.notification_sound')
        ->serializeToForum('ramon-chat.title', 'ramon-chat.title')
        ->serializeToForum('ramon-chat.icon', 'ramon-chat.icon')
        ->serializeToForum('ramon-chat.showIcon', 'ramon-chat.show_icon', 'boolval')
        ->serializeToForum('ramon-chat.allowArchivingChannels', 'ramon-chat.allow_archiving_channels', 'boolval')

        // The bot's identity. Public on purpose: it is drawn on every announcement
        // in the stream, so it is no more secret than a username. Only the name and
        // the avatar are here — there is no account and no credential behind them.
        ->serializeToForum('ramon-chat.botName', 'ramon-chat.bot_name')

        // An external URL the admin typed. Kept alongside the uploaded file rather
        // than replaced by it, so switching to an upload and back does not lose it.
        ->serializeToForum('ramon-chat.botAvatarUrl', 'ramon-chat.bot_avatar_url')

        // The uploaded file, stored as a path on the assets disk and turned into a
        // URL where the assets base is known.
        ->serializeToForum('ramon-chat.botAvatarPath', 'ramon-chat.bot_avatar_path')

        // When set, announcements are posted as this user and there is no bot at
        // all — see AnnounceDiscussions.
        ->serializeToForum('ramon-chat.botUserId', 'ramon-chat.bot_user_id', fn ($v) => $v ? (int) $v : null),

    // ── Per-user chat preferences (/settings) ────────────────────────────────
    (new Extend\User())
        ->registerPreference('ramon-chat.enabled', 'boolVal', true)
        ->registerPreference('ramon-chat.allowChannelWideMentions', 'boolVal', true)
        ->registerPreference('ramon-chat.sound', 'strVal', 'default')
        ->registerPreference('ramon-chat.emailNotifications', 'boolVal', false)
        ->registerPreference('ramon-chat.openInDrawer', 'boolVal', true)

        // Which keystroke sends, for this member. Three states, not two, and the
        // third is the point: 'default' means "whatever the forum is set to", so
        // a member who has never touched it follows `send_with_ctrl_enter` and an
        // admin who had turned that on does not have it silently undone for
        // everyone the moment this preference exists. 'enter' and 'ctrl' are the
        // two explicit answers. See sendsOnCtrlEnter() in utils/shortcuts.ts.
        ->registerPreference('ramon-chat.sendWithCtrlEnter', 'strVal', 'enter'),

    // ── Non-JSON:API routes ──────────────────────────────────────────────────
    // These carry payloads JSON:API cannot express (multipart uploads) or are
    // addressed by a secret rather than a resource id (webhooks).
    (new Extend\Routes('api'))
        ->post('/chat/uploads', 'chat.uploads.store', Api\Controller\UploadController::class)
        ->post('/chat/typing', 'chat.typing', Api\Controller\TypingController::class)
        ->post('/chat/drafts', 'chat.drafts.store', Api\Controller\DraftController::class)
        ->get('/chat/drafts', 'chat.drafts.index', Api\Controller\ListDraftsController::class)
        ->post('/chat/direct', 'chat.direct.start', Api\Controller\StartDirectController::class)
        ->post('/chat/transcript', 'chat.transcript', Api\Controller\TranscriptController::class)
        ->post('/chat/messages/move', 'chat.messages.move', Api\Controller\MoveMessagesController::class)
        // Slack-compatible incoming webhook, authenticated by the secret path key.
        ->post('/chat/hooks/{key}', 'chat.webhooks.deliver', Api\Controller\WebhookDeliveryController::class)

        // The bot's avatar. Multipart, so it cannot be a JSON:API field; both
        // controllers assert admin themselves rather than relying on the route.
        ->post('/chat/bot-avatar', 'chat.bot.avatar.upload', Api\Controller\UploadBotAvatarController::class)
        ->delete('/chat/bot-avatar', 'chat.bot.avatar.delete', Api\Controller\DeleteBotAvatarController::class)

        // The channel's picture. Guarded by the channel's own `edit` policy rather
        // than by admin, so whoever may rename a channel may also give it a mark.
        ->post('/chat/channels/{id}/image', 'chat.channels.image.set', Api\Controller\ChannelImageController::class)
        ->delete('/chat/channels/{id}/image', 'chat.channels.image.clear', Api\Controller\ChannelImageController::class),

    // The delivering service cannot hold a Flarum session token, so the webhook
    // route authenticates by its key instead. See WebhookDeliveryController for
    // the constant-time comparison that makes the key safe to use this way.
    (new Extend\Csrf())
        ->exemptRoute('chat.webhooks.deliver'),

    // ── Notifications ────────────────────────────────────────────────────────
    // Only mentions are mailable: a message notification can fire per message in
    // a busy channel, and routing that to email would be a mail-bomb.
    (new Extend\Notification())
        // Email only: the alert channel would put chat traffic in Flarum's bell,
        // and the chat surfaces its own mentions. Listing 'alert' here would also
        // offer a preference toggle for a channel that no longer delivers.
        ->type(Notification\ChatMentionBlueprint::class, ['email'])

        // No channels at all. Still registered so notification rows written by the
        // earlier version stay resolvable instead of rendering as an unknown type.
        ->type(Notification\ChatMessageBlueprint::class, [])
        ->type(Notification\ChannelInviteBlueprint::class, ['alert'])

        // Reports reach the moderators the way a flag on a post does, rather than
        // waiting to be found the next time someone opens the queue. Alert only:
        // a busy channel can produce a run of reports, and a mailbox is the wrong
        // place for a queue.
        ->type(Notification\MessageFlaggedBlueprint::class, ['alert']),

    // ── Domain listeners ─────────────────────────────────────────────────────
    (new Extend\Event())
        ->listen(Event\MessageWasSent::class, Listener\SendChatNotifications::class)
        ->listen(Event\ChannelWasCreated::class, Listener\AutoJoinUsers::class)
        ->listen(\Flarum\User\Event\Registered::class, Listener\JoinAutoJoinChannels::class)
        ->listen(Event\MessageWasDeleted::class, Listener\RecalculateUnreadCounts::class)
        // Deleting a reported message is what closes the reports about it. Without
        // this the queue keeps offering work that has already been done.
        ->listen(Event\MessageWasDeleted::class, Listener\ResolveFlagsOnModeration::class)

        // And takes its attachments off the disk. The chat disk is public, so a
        // deleted image stayed readable by URL to anyone who had seen it.
        ->listen(Event\MessageWasDeleted::class, Listener\PurgeUploadsOnDeletion::class)
        ->listen(Event\MessageWasMoved::class, Listener\RecalculateUnreadCounts::class)
        // Narrates membership changes into the stream, so a departure is visible to
        // whoever is left rather than silent.
        ->listen(Event\UserJoinedChannel::class, Listener\AnnounceMembershipChanges::class.'@whenJoined')
        ->listen(Event\UserLeftChannel::class, Listener\AnnounceMembershipChanges::class.'@whenLeft'),

    // ── Console ──────────────────────────────────────────────────────────────
    (new Extend\Console())
        ->command(Console\PruneChatCommand::class)
        ->schedule(Console\PruneChatCommand::class, function ($event) {
            // Retention is housekeeping: nightly is frequent enough, and 03:30
            // keeps a destructive job away from peak traffic.
            $event->daily()->at('03:30');
        }),

    // ── Search / filtering ───────────────────────────────────────────────────
    // Flarum 2 has no JSON:API resource filters: AbstractDatabaseResource::filters()
    // is final and throws, directing extensions to the search driver instead. Every
    // `filter[...]` the client sends therefore arrives through a searcher, and
    // Endpoint\Index routes through SearchManager once a model is searchable.
    (new Extend\SearchDriver(\Flarum\Search\Database\DatabaseSearchDriver::class))
        ->addSearcher(Channel::class, Search\ChannelSearcher::class)
        ->addFilter(Search\ChannelSearcher::class, Search\Filter\ChannelTypeFilter::class)
        ->addFilter(Search\ChannelSearcher::class, Search\Filter\ChannelStatusFilter::class)
        ->addFilter(Search\ChannelSearcher::class, Search\Filter\ChannelFollowingFilter::class)
        ->addFilter(Search\ChannelSearcher::class, Search\Filter\ChannelNameFilter::class)

        ->addSearcher(Message::class, Search\MessageSearcher::class)
        ->addFilter(Search\MessageSearcher::class, Search\Filter\MessageChannelFilter::class)
        ->addFilter(Search\MessageSearcher::class, Search\Filter\MessageThreadFilter::class)
        ->addFilter(Search\MessageSearcher::class, Search\Filter\MessageBeforeFilter::class)
        ->addFilter(Search\MessageSearcher::class, Search\Filter\MessageAfterFilter::class)
        // The other half of the polling fallback's cursor. `greaterThan` reaches
        // forward from the newest id and so can only carry arrivals; this one
        // carries changes to rows the poll has already gone past — reactions,
        // edits, deletions and pins, none of which were visible without a reload
        // on a forum that has no websocket.
        ->addFilter(Search\MessageSearcher::class, Search\Filter\MessageChangedFilter::class)
        ->addFilter(Search\MessageSearcher::class, Search\Filter\MessageBookmarkedFilter::class)
        ->addFilter(Search\MessageSearcher::class, Search\Filter\MessagePinnedFilter::class)
        ->addFilter(Search\MessageSearcher::class, Search\Filter\MessageTextFilter::class)

        // The composer's @ autocomplete, restricted to people in the channel.
        ->addFilter(\Flarum\User\Search\UserSearcher::class, Search\Filter\UserChatChannelFilter::class)

        ->addSearcher(Thread::class, Search\ThreadSearcher::class)
        ->addFilter(Search\ThreadSearcher::class, Search\Filter\ThreadChannelFilter::class)
        ->addFilter(Search\ThreadSearcher::class, Search\Filter\ThreadParticipatingFilter::class)

        // The moderation queue's one filter. Registered as a searcher because
        // Flarum 2 rejects any query parameter it does not recognise, and a bare
        // `?resolved=1` is not one — a filter has to come through here.
        ->addSearcher(MessageFlag::class, Search\MessageFlagSearcher::class)
        ->addFilter(Search\MessageFlagSearcher::class, Search\Filter\FlagResolvedFilter::class),

    // ── Category-driven auto-join, only meaningful with flarum/tags ──────────
    // A channel bound to a tag can grow from participation in that category. The
    // listener reads `$discussion->tags`, which only exists when tags is enabled.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new Extend\Event())
                ->listen(\Flarum\Post\Event\Posted::class, Listener\JoinChannelsOnReply::class)
                ->listen(\Flarum\Post\Event\Posted::class, Listener\AnnounceDiscussions::class),
        ]),

    // ── Realtime, only when flarum/realtime is present ───────────────────────
    // Chat payloads are addressed to individual users' private channels, never to
    // the shared `public` channel — see Realtime\ChatBroadcaster for why that
    // distinction is a privacy boundary rather than an optimisation.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-realtime', fn () => [
            (new Extend\Event())
                ->listen(Event\MessageWasSent::class, Realtime\BroadcastListener::class.'@whenMessageSent')
                ->listen(Event\MessageWasEdited::class, Realtime\BroadcastListener::class.'@whenMessageChanged')
                ->listen(Event\MessageWasDeleted::class, Realtime\BroadcastListener::class.'@whenMessageChanged')
                // Its own event, not the changed one: a purge leaves no row to
                // redraw, so the client removes it rather than restyling it.
                ->listen(Event\MessageWasPurged::class, Realtime\BroadcastListener::class.'@whenMessagePurged')
                ->listen(Event\MessageWasRestored::class, Realtime\BroadcastListener::class.'@whenMessageChanged')
                ->listen(Event\MessagePinToggled::class, Realtime\BroadcastListener::class.'@whenMessageChanged')
                ->listen(Event\ReactionToggled::class, Realtime\BroadcastListener::class.'@whenReactionToggled')
                ->listen(Event\ThreadWasCreated::class, Realtime\BroadcastListener::class.'@whenThreadChanged')
                ->listen(Event\ChannelStatusChanged::class, Realtime\BroadcastListener::class.'@whenChannelChanged')
                ->listen(Event\ChannelWasEdited::class, Realtime\BroadcastListener::class.'@whenChannelChanged'),
        ]),

    // ── Privacy and auditing ─────────────────────────────────────────────────
    // Both integrations reference classes that ship with the other extension, so
    // each is built inside its own closure: the Conditional only evaluates it when
    // that extension is enabled, and a forum without it never loads the class.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new \Flarum\Gdpr\Extend\UserData())
                ->addType(Gdpr\ChatData::class),
        ])
        ->whenExtensionEnabled('flarum-audit', fn () => [
            (new \Flarum\Audit\Extend\Audit())
                ->group('ramon-chat')
                ->using(new Audit\AuditIntegration()),
        ]),
];
