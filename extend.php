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

use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use League\Flysystem\Visibility;
use Ramon\Chat\Service\UnreadTracker;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // Serve os chunks lazy do webpack (o mapa de emoji, entre eles). Sem isso
        // o import dinâmico dá 404 e os shortcodes param de resolver.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        // Dados do primeiro paint no payload de boot, evitando três round trips.
        // Só roda nas rotas do chat (PreloadChat confere o nome da rota).
        ->content(Content\PreloadChat::class)
        // Modo tela cheia. O drawer abre sobre qualquer página e não tem rota.
        ->route('/chat', 'chat.index', Frontend\RequireChatAccess::class)
        ->route('/chat/c/{id}', 'chat.channel', Frontend\RequireChatAccess::class)
        ->route('/chat/c/{id}/t/{threadId:\d+}', 'chat.thread', Frontend\RequireChatAccess::class)
        ->route('/chat/browse', 'chat.browse', Frontend\RequireChatAccess::class)
        ->route('/chat/browse/{filter}', 'chat.browse.filter', Frontend\RequireChatAccess::class)
        ->route('/chat/threads', 'chat.threads', Frontend\RequireChatAccess::class)
        ->route('/chat/search', 'chat.search', Frontend\RequireChatAccess::class)
        // Toda rota do chat precisa estar aqui e em `app.routes` no cliente;
        // só no cliente, a rota funciona navegando e dá 404 no reload.
        ->route('/chat/bookmarks', 'chat.bookmarks', Frontend\RequireChatAccess::class)

        // Fila de moderação. O conteúdo é checado de novo por `ramon-chat.moderate`
        // nos endpoints e no scope do resource.
        ->route('/chat/flags', 'chat.flags', Frontend\RequireChatAccess::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less')
        ->content(Content\QueueDriver::class),

    new Extend\Locales(__DIR__.'/locale'),

    // Views `ramon-chat::emails.*` usadas pelo ChatMentionBlueprint.
    (new Extend\View())
        ->namespace('ramon-chat', __DIR__.'/views'),

    (new Extend\ServiceProvider())
        ->register(ChatServiceProvider::class),

    // ── Anexos ──
    // O disco público é servido pelo web server. O privado fica fora do webroot
    // e só é lido pelo ServeUploadController, que checa a visibilidade antes.
    // Service\UploadPrivacy decide o disco de cada arquivo.
    (new Extend\Filesystem())
        ->disk('chat', function (Paths $paths, UrlGenerator $url) {
            return [
                'root'       => "$paths->public/assets/chat",
                'url'        => $url->to('forum')->path('assets/chat'),
                'visibility' => Visibility::PUBLIC,
            ];
        })
        ->disk('chat-private', function (Paths $paths) {
            return [
                'root'       => "$paths->storage/chat-uploads",
                'visibility' => Visibility::PRIVATE,
            ];
        }),

    // ── Models ──
    (new Extend\Model(User::class))
        ->belongsToMany('chatChannels', Channel::class, 'chat_channel_user', 'user_id', 'channel_id')
        ->hasMany('chatMessages', Message::class, 'user_id'),

    (new Extend\ModelVisibility(Channel::class))
        ->scope(Access\ScopeChannelVisibility::class),

    (new Extend\ModelVisibility(Upload::class))
        ->scope(Access\ScopeUploadVisibility::class),

    (new Extend\ModelVisibility(Message::class))
        ->scope(Access\ScopeMessageVisibility::class),

    (new Extend\ModelVisibility(Thread::class))
        ->scope(Access\ScopeThreadVisibility::class),

    (new Extend\Policy())
        ->modelPolicy(Channel::class, Access\ChannelPolicy::class)
        ->modelPolicy(Message::class, Access\MessagePolicy::class)
        ->modelPolicy(Thread::class, Access\ThreadPolicy::class)
        ->globalPolicy(Access\GlobalPolicy::class),

    // ── API ──
    new Extend\ApiResource(Api\Resource\ChannelResource::class),
    new Extend\ApiResource(Api\Resource\MessageResource::class),
    new Extend\ApiResource(Api\Resource\ThreadResource::class),
    new Extend\ApiResource(Api\Resource\UploadResource::class),
    new Extend\ApiResource(Api\Resource\WebhookResource::class),
    new Extend\ApiResource(Api\Resource\MessageFlagResource::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumResourceFields::class),

    (new Extend\ApiResource(UserResource::class))
        ->fields(Api\UserResourceFields::class),

    // ── Configurações ──
    (new Extend\Settings())
        ->default('ramon-chat.channel_ownership', 'admin')
        ->default('ramon-chat.channel_retention_days', 90)
        ->default('ramon-chat.dm_retention_days', 0)
        ->default('ramon-chat.max_messages_per_second', 2)
        ->default('ramon-chat.min_message_length', 1)
        ->default('ramon-chat.max_message_length', 3000)
        ->default('ramon-chat.message_edit_window_minutes', 0)
        ->default('ramon-chat.allow_uploads', true)
        ->default('ramon-chat.max_upload_size', 10485760)
        // Destino dos anexos públicos: 'local' ou 'fof-upload' (só com a extensão
        // ativa). Anexos privados ignoram. Adapter vazio segue o mapeamento do
        // fof/upload. Nenhum dos dois vai ao fórum: revelaria onde ficam os arquivos.
        ->default(Storage\UploadStorage::SETTING, 'local')
        ->default('ramon-chat.fof_upload_adapter', '')
        ->default('ramon-chat.allow_archiving_channels', true)
        ->default('ramon-chat.threading_default', false)
        // Com o flarum/messages ativo, quem atende o "Enviar mensagem": 'messages'
        // (o dele, padrão) ou 'chat'. Sem ele, o chat sempre oferece o seu.
        ->default('ramon-chat.direct_messages_provider', 'messages')
        // Pushes realtime pela fila. Desligado por padrão: a fila de banco do Flarum
        // roda a cada minuto, atraso inaceitável no chat. Ligue só com worker
        // contínuo (ver Realtime\ChatBroadcaster).
        ->default('ramon-chat.queue_realtime', false)
        // Chave geral dos webhooks; desligada, a rota recusa qualquer key.
        ->default('ramon-chat.webhooks_enabled', true)
        // 'none' desliga; os demais nomeiam um arquivo em assets/sounds.
        ->default('ramon-chat.notification_sound', 'chime')
        // Título e ícone do botão e do drawer. Título vazio usa "Chat" traduzido.
        ->default('ramon-chat.title', '')
        ->default('ramon-chat.icon', 'fas fa-comments')
        ->default('ramon-chat.show_icon', true)
        ->serializeToForum('ramon-chat.maxMessageLength', 'ramon-chat.max_message_length', 'intval')
        ->serializeToForum('ramon-chat.minMessageLength', 'ramon-chat.min_message_length', 'intval')
        ->serializeToForum('ramon-chat.maxUploadSize', 'ramon-chat.max_upload_size', 'intval')
        ->serializeToForum('ramon-chat.allowUploads', 'ramon-chat.allow_uploads', 'boolval')
        ->serializeToForum('ramon-chat.threadingDefault', 'ramon-chat.threading_default', 'boolval')
        ->serializeToForum('ramon-chat.directMessagesProvider', 'ramon-chat.direct_messages_provider')
        ->serializeToForum('ramon-chat.notificationSound', 'ramon-chat.notification_sound')
        ->serializeToForum('ramon-chat.title', 'ramon-chat.title')
        ->serializeToForum('ramon-chat.icon', 'ramon-chat.icon')
        ->serializeToForum('ramon-chat.showIcon', 'ramon-chat.show_icon', 'boolval')
        ->serializeToForum('ramon-chat.allowArchivingChannels', 'ramon-chat.allow_archiving_channels', 'boolval')

        // Identidade do bot, pública de propósito: aparece em todo anúncio. Não há
        // conta nem credencial por trás.
        ->serializeToForum('ramon-chat.botName', 'ramon-chat.bot_name')

        // URL externa; fica junto do arquivo enviado para não se perder ao alternar.
        ->serializeToForum('ramon-chat.botAvatarUrl', 'ramon-chat.bot_avatar_url')

        // Caminho no disco de assets; vira URL no cliente.
        ->serializeToForum('ramon-chat.botAvatarPath', 'ramon-chat.bot_avatar_path')

        // Se definido, anúncios saem como este usuário, sem bot (ver AnnounceDiscussions).
        ->serializeToForum('ramon-chat.botUserId', 'ramon-chat.bot_user_id', Api\NullableUserId::class),

    // ── Preferências do usuário (/settings) ──
    (new Extend\User())
        ->registerPreference('ramon-chat.enabled', 'boolVal', true)
        ->registerPreference('ramon-chat.allowChannelWideMentions', 'boolVal', true)
        ->registerPreference('ramon-chat.sound', 'strVal', 'default')
        // Lida junto com `notify_chatMention_email` do core; ligada por padrão
        // como ela, para as duas não divergirem.
        ->registerPreference('ramon-chat.emailNotifications', 'boolVal', true)
        ->registerPreference('ramon-chat.openInDrawer', 'boolVal', true)

        // 'enter', 'ctrl' ou 'default' (segue a configuração do fórum).
        // Ver sendsOnCtrlEnter() em utils/shortcuts.ts.
        ->registerPreference('ramon-chat.sendWithCtrlEnter', 'strVal', 'enter'),

    // ── Rotas fora do JSON:API ──
    // Payloads multipart ou endereçados por segredo em vez de id (webhooks).
    (new Extend\Routes('api'))
        ->post('/chat/uploads', 'chat.uploads.store', Api\Controller\UploadController::class)
        // Bytes de anexo privado. Em /api para autenticar como o resto do chat
        // (cookie de sessão ou token).
        ->get('/chat/uploads/{id:\d+}/file', 'chat.uploads.file', Api\Controller\ServeUploadController::class)
        ->post('/chat/typing', 'chat.typing', Api\Controller\TypingController::class)
        // Prova a ida e volta do websocket logo após a inscrição; ver RealtimePingController.
        ->post('/chat/realtime/ping', 'chat.realtime.ping', Api\Controller\RealtimePingController::class)
        ->post('/chat/drafts', 'chat.drafts.store', Api\Controller\DraftController::class)
        ->get('/chat/drafts', 'chat.drafts.index', Api\Controller\ListDraftsController::class)
        ->post('/chat/direct', 'chat.direct.start', Api\Controller\StartDirectController::class)
        // Eventos do calendário para o horário do canal (ver Schedule\CalendarEvents).
        ->get('/chat/calendar-events', 'chat.calendar.events', Api\Controller\ListCalendarEventsController::class)
        // Fora do resource: canal privado é invisível a quem não entrou, então um
        // endpoint com scope não o acharia. O convite é a autorização; {id} é o canal.
        ->post('/chat/invites/{id:\d+}/accept', 'chat.invites.accept', Api\Controller\AcceptInviteController::class)
        ->post('/chat/invites/{id:\d+}/decline', 'chat.invites.decline', Api\Controller\DeclineInviteController::class)
        ->post('/chat/transcript', 'chat.transcript', Api\Controller\TranscriptController::class)
        ->post('/chat/messages/move', 'chat.messages.move', Api\Controller\MoveMessagesController::class)
        // Webhook compatível com Slack, autenticado pela key secreta no path.
        ->post('/chat/hooks/{key}', 'chat.webhooks.deliver', Api\Controller\WebhookDeliveryController::class)

        // Avatar do bot (multipart). Os controllers exigem admin por conta própria.
        ->post('/chat/bot-avatar', 'chat.bot.avatar.upload', Api\Controller\UploadBotAvatarController::class)
        ->delete('/chat/bot-avatar', 'chat.bot.avatar.delete', Api\Controller\DeleteBotAvatarController::class)

        // Imagem do canal, protegida pela policy `edit` do canal, não por admin.
        ->post('/chat/channels/{id}/image', 'chat.channels.image.set', Api\Controller\ChannelImageController::class)
        ->delete('/chat/channels/{id}/image', 'chat.channels.image.clear', Api\Controller\ChannelImageController::class),

    // O serviço externo não tem sessão; a rota autentica pela key, comparada em
    // tempo constante no WebhookDeliveryController.
    (new Extend\Csrf())
        ->exemptRoute('chat.webhooks.deliver'),

    // ── Notificações ──
    // Só menções vão por e-mail; por mensagem viraria uma enxurrada de e-mails.
    (new Extend\Notification())
        // Só e-mail: o chat mostra as próprias menções, sem poluir o sino do Flarum.
        ->type(Notification\ChatMentionBlueprint::class, ['email'])

        // Sem canais; registrado só para linhas antigas continuarem resolvíveis.
        ->type(Notification\ChatMessageBlueprint::class, [])
        // Convite e recusa: só alerta, não justificam e-mail.
        ->type(Notification\ChannelInviteBlueprint::class, ['alert'])
        ->type(Notification\ChannelInviteDeclinedBlueprint::class, ['alert'])

        // Denúncias avisam os moderadores. Só alerta: um canal movimentado gera
        // várias seguidas.
        ->type(Notification\MessageFlaggedBlueprint::class, ['alert'])

        // Promoção a moderador e transferência de dono. Só alerta; o código de
        // confirmação tem e-mail próprio.
        ->type(Notification\ModeratorPromotedBlueprint::class, ['alert'])
        ->type(Notification\OwnershipTransferBlueprint::class, ['alert'])
        ->type(Notification\OwnershipTransferDeclinedBlueprint::class, ['alert'])
        // Moderador que herda o canal quando o dono sai.
        ->type(Notification\OwnershipInheritedBlueprint::class, ['alert']),

    // ── Listeners ──
    (new Extend\Event())
        ->listen(Event\MessageWasSent::class, Listener\SendChatNotifications::class)
        ->listen(Event\MessageWasDeleted::class, Listener\RecalculateUnreadCounts::class)
        // Apagar a mensagem denunciada fecha as denúncias dela.
        ->listen(Event\MessageWasDeleted::class, Listener\ResolveFlagsOnModeration::class)

        // Remove os anexos do disco: no disco público continuariam acessíveis por URL.
        ->listen(Event\MessageWasDeleted::class, Listener\PurgeUploadsOnDeletion::class)
        ->listen(Event\MessageWasMoved::class, Listener\RecalculateUnreadCounts::class)
        // Canal tornado privado ou mensagem movida para um: anexos saem do disco
        // público. O envio em si é tratado no dispatcher.
        ->listen(Event\MessageWasMoved::class, Listener\KeepUploadsPrivate::class.'@whenMessageMoved')
        ->listen(Event\ChannelWasEdited::class, Listener\KeepUploadsPrivate::class.'@whenChannelEdited')
        ->listen(Event\ChannelWasDeleted::class, Listener\KeepUploadsPrivate::class.'@whenChannelDeleted')
        // Entradas e saídas aparecem no stream.
        ->listen(Event\UserJoinedChannel::class, Listener\AnnounceMembershipChanges::class.'@whenJoined')
        ->listen(Event\UserLeftChannel::class, Listener\AnnounceMembershipChanges::class.'@whenLeft')
        // Notificações de convite acompanham a tabela de convites: responder ou
        // cancelar tira do sino; a recusa avisa o dono e quem convidou.
        ->listen(Event\UsersWereInvited::class, Listener\NotifyInvitations::class.'@whenInvited')
        ->listen(Event\UserJoinedChannel::class, Listener\NotifyInvitations::class.'@whenJoined')
        ->listen(Event\InviteWasDeclined::class, Listener\NotifyInvitations::class.'@whenDeclined')
        ->listen(Event\InviteWasCancelled::class, Listener\NotifyInvitations::class.'@whenCancelled')
        ->listen(Event\ChannelModeratorChanged::class, Listener\NotifyModeratorChanges::class)
        // Transferência de dono: oferta e resposta no sino; a pendente cai se um
        // dos lados sai ou o canal some.
        ->listen(Event\OwnershipTransferRequested::class, Listener\NotifyOwnershipTransfers::class.'@whenRequested')
        ->listen(Event\OwnershipTransferEnded::class, Listener\NotifyOwnershipTransfers::class.'@whenEnded')
        ->listen(Event\ChannelOwnershipTransferred::class, Listener\NotifyOwnershipTransfers::class.'@whenTransferred')
        ->listen(Event\UserLeftChannel::class, Listener\CancelOwnershipTransfers::class.'@whenLeft')
        ->listen(Event\ChannelWasDeleted::class, Listener\CancelOwnershipTransfers::class.'@whenDeleted')
        ->listen(Event\ChannelWasArchived::class, Listener\CancelOwnershipTransfers::class.'@whenArchived')
        // Canais de conta apagada passam ao moderador mais antigo.
        ->listen(\Flarum\User\Event\Deleting::class, Listener\HandOverOwnedChannels::class)
        // Cache de cargos invalidado antes dos listeners realtime, que o leem.
        ->listen(Event\ChannelModeratorChanged::class, Listener\ForgetChannelRanks::class)
        ->listen(Event\ChannelOwnershipTransferred::class, Listener\ForgetChannelRanks::class)
        ->listen(Event\UserJoinedChannel::class, Listener\ForgetChannelRanks::class)
        ->listen(Event\UserLeftChannel::class, Listener\ForgetChannelRanks::class)
        ->listen(Event\UserLeftChannel::class, Listener\ConsumeInvites::class),

    // ── Console ──
    (new Extend\Console())
        ->command(Console\PruneChatCommand::class)
        ->schedule(Console\PruneChatCommand::class, Console\PruneChatSchedule::class)
        ->command(Console\PrivatizePendingUploadsCommand::class)
        ->schedule(Console\PrivatizePendingUploadsCommand::class, Console\PrivatizePendingUploadsSchedule::class),

    // ── Busca / filtros ──
    // No Flarum 2, AbstractDatabaseResource::filters() é final e lança exceção;
    // todo `filter[...]` passa por um searcher.
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
        // Polling sem websocket: `greaterThan` traz só mensagens novas; este traz
        // mudanças (reações, edições, exclusões, pins) em linhas já vistas.
        ->addFilter(Search\MessageSearcher::class, Search\Filter\MessageChangedFilter::class)
        ->addFilter(Search\MessageSearcher::class, Search\Filter\MessageBookmarkedFilter::class)
        ->addFilter(Search\MessageSearcher::class, Search\Filter\MessagePinnedFilter::class)
        ->addFilter(Search\MessageSearcher::class, Search\Filter\MessageTextFilter::class)

        // Autocomplete de @ do composer, restrito a membros do canal.
        ->addFilter(\Flarum\User\Search\UserSearcher::class, Search\Filter\UserChatChannelFilter::class)

        ->addSearcher(Thread::class, Search\ThreadSearcher::class)
        ->addFilter(Search\ThreadSearcher::class, Search\Filter\ThreadChannelFilter::class)
        ->addFilter(Search\ThreadSearcher::class, Search\Filter\ThreadParticipatingFilter::class)

        // Searcher porque o Flarum 2 rejeita query params desconhecidos como
        // `?resolved=1`.
        ->addSearcher(MessageFlag::class, Search\MessageFlagSearcher::class)
        ->addFilter(Search\MessageFlagSearcher::class, Search\Filter\FlagResolvedFilter::class),

    // ── Auto-join por categoria (flarum/tags) ──
    // O listener lê `$discussion->tags`, que só existe com tags ativa.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new Extend\Event())
                ->listen(\Flarum\Post\Event\Posted::class, Listener\JoinChannelsOnReply::class)
                ->listen(\Flarum\Post\Event\Posted::class, Listener\AnnounceDiscussions::class),
        ]),

    // ── Realtime (flarum/realtime) ──
    // Payloads vão ao canal privado de cada usuário, nunca ao `public`: é uma
    // fronteira de privacidade (ver Realtime\ChatBroadcaster).
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-realtime', fn () => [
            (new Extend\Event())
                ->listen(Event\MessageWasSent::class, Realtime\BroadcastListener::class.'@whenMessageSent')
                ->listen(Event\MessageWasEdited::class, Realtime\BroadcastListener::class.'@whenMessageChanged')
                ->listen(Event\MessageWasDeleted::class, Realtime\BroadcastListener::class.'@whenMessageChanged')
                // Evento próprio: no purge não há linha a redesenhar; o cliente remove.
                ->listen(Event\MessageWasPurged::class, Realtime\BroadcastListener::class.'@whenMessagePurged')
                ->listen(Event\MessageWasRestored::class, Realtime\BroadcastListener::class.'@whenMessageChanged')
                ->listen(Event\MessagePinToggled::class, Realtime\BroadcastListener::class.'@whenMessageChanged')
                ->listen(Event\ReactionToggled::class, Realtime\BroadcastListener::class.'@whenReactionToggled')
                ->listen(Event\ThreadWasCreated::class, Realtime\BroadcastListener::class.'@whenThreadChanged')
                ->listen(Event\ThreadWasEdited::class, Realtime\BroadcastListener::class.'@whenThreadChanged')
                // Uma vez por movimento, após o commit, para os dois canais lerem o estado novo.
                ->listen(Event\MessagesWereMoved::class, Realtime\BroadcastListener::class.'@whenMessagesMoved')
                ->listen(Event\ChannelWasCreated::class, Realtime\BroadcastListener::class.'@whenChannelCreated')
                ->listen(Event\ChannelStatusChanged::class, Realtime\BroadcastListener::class.'@whenChannelChanged')
                ->listen(Event\ChannelWasEdited::class, Realtime\BroadcastListener::class.'@whenChannelChanged')
                ->listen(Event\ChannelWasArchived::class, Realtime\BroadcastListener::class.'@whenChannelArchived')
                // Canal apagado sai da sidebar de todos na hora, em vez de dar 404.
                ->listen(Event\ChannelWasDeleted::class, Realtime\BroadcastListener::class.'@whenChannelDeleted')
                // Associação: atualiza a sidebar sem reload ao aceitar convite ou ser removido.
                ->listen(Event\UserWasInvited::class, Realtime\BroadcastListener::class.'@whenInvited')
                ->listen(Event\UserJoinedChannel::class, Realtime\BroadcastListener::class.'@whenJoined')
                ->listen(Event\UserLeftChannel::class, Realtime\BroadcastListener::class.'@whenLeft')
                ->listen(Event\InviteWasDeclined::class, Realtime\BroadcastListener::class.'@whenInviteDeclined')
                ->listen(Event\InviteWasCancelled::class, Realtime\BroadcastListener::class.'@whenInviteCancelled')
                ->listen(Event\ChannelModeratorChanged::class, Realtime\BroadcastListener::class.'@whenModeratorChanged')
                ->listen(Event\ChannelOwnershipTransferred::class, Realtime\BroadcastListener::class.'@whenOwnershipTransferred')
                ->listen(Event\OwnershipTransferRequested::class, Realtime\BroadcastListener::class.'@whenTransferRequested')
                ->listen(Event\OwnershipTransferEnded::class, Realtime\BroadcastListener::class.'@whenTransferEnded')
                // Cargos: redesenha autor e aba de membros para todos.
                ->listen(Event\ChannelRanksChanged::class, Realtime\BroadcastListener::class.'@whenRanksChanged')
                // Badge da fila; cada moderador recalcula.
                ->listen(Event\FlagsChanged::class, Realtime\BroadcastListener::class.'@whenFlagsChanged'),
        ]),

    // ── Privacidade e auditoria ──
    // Closures para não carregar classes de extensões ausentes.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new \Flarum\Gdpr\Extend\UserData())
                ->addType(Gdpr\ChatData::class),
        ])
        // Categoria que fica restrita tira os anexos dos canais do disco público.
        // Evento do Eloquent porque flarum/tags não dispara nenhum após salvar.
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new Extend\Event())
                ->listen('eloquent.saved: '.\Flarum\Tags\Tag::class, Listener\KeepUploadsPrivate::class.'@whenTagSaved'),
        ])
        ->whenExtensionEnabled('flarum-audit', fn () => [
            (new \Flarum\Audit\Extend\Audit())
                ->group('ramon-chat')
                ->using(new Audit\AuditIntegration()),
        ]),

    // ── Anexos via fof/upload ──
    // Só o dropdown do admin; Storage\UploadStorage escolhe o storage no upload
    // e confere se fof/upload está ativa.
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-upload', fn () => [
            (new Extend\Frontend('admin'))
                ->content(Content\FofUploadAdapters::class),
        ]),
];
