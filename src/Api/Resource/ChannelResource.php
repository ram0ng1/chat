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
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Laminas\Diactoros\Response\EmptyResponse;
use Ramon\Chat\Access\ScopeChannelVisibility;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelRank;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\ChannelModeratorChanged;
use Ramon\Chat\Event\ChannelStatusChanged;
use Ramon\Chat\Event\ChannelWasCreated;
use Ramon\Chat\Event\ChannelWasDeleted;
use Ramon\Chat\Event\ChannelWasEdited;
use Ramon\Chat\Event\InviteWasCancelled;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UserLeftChannel;
use Ramon\Chat\Event\UsersWereInvited;
use Ramon\Chat\Event\UserWasInvited;
use Ramon\Chat\Schedule\CalendarEvents;
use Ramon\Chat\Schedule\ChannelSchedule;
use Ramon\Chat\Schedule\OpeningHours;
use Ramon\Chat\Service\ActionThrottle;
use Ramon\Chat\Service\ChannelArchiver;
use Ramon\Chat\Service\ChannelOwnership;
use Ramon\Chat\Service\ChannelRanks;
use Ramon\Chat\Service\InvitationManager;
use Ramon\Chat\Service\MembershipManager;
use Ramon\Chat\Service\OwnershipTransfers;
use Ramon\Chat\Service\SlowMode;
use Ramon\Chat\Service\UnreadTracker;
use Tobyz\JsonApiServer\Context as OriginalContext;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * @extends AbstractDatabaseResource<Channel>
 */
class ChannelResource extends AbstractDatabaseResource
{
    /**
     * Pessoas que alguém que não é admin convida por hora, somando todos os canais.
     */
    public const INVITES_PER_HOUR = 100;

    /**
     * Entradas mais saídas por minuto num canal, para quem não é admin.
     */
    public const MEMBERSHIP_CHANGES_PER_MINUTE = 6;

    /** Canais que alguém que não é admin cria por hora. */
    public const CHANNELS_PER_HOUR = 10;

    /**
     * Escritas de rank por minuto para quem não é admin. Cada uma chega à tela de
     * todos os membros, então um script no editor viraria um flood de pushes.
     */
    public const RANK_CHANGES_PER_MINUTE = 30;

    public function __construct(
        protected Translator $translator,
        protected Events $events,
        protected UnreadTracker $unread,
        protected MembershipManager $memberships,
        protected ChannelArchiver $archiver,
        protected ChannelOwnership $ownership,
        protected InvitationManager $invitations,
        protected SettingsRepositoryInterface $settings,
        protected SlowMode $slowMode,
        protected ActionThrottle $throttle,
        protected ExtensionManager $extensions,
        protected OwnershipTransfers $transfers,
        protected ChannelRanks $ranks,
        protected ChannelSchedule $schedule,
        protected CalendarEvents $calendar
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
     * O endpoint Create monta o model aqui, não pelo Channel::build(), então os
     * defaults daquela factory precisam ser postos à mão.
     */
    public function newModel(OriginalContext $context): object
    {
        if ($context->creating(self::class)) {
            $channel = new Channel();

            $channel->type = Channel::TYPE_CATEGORY;
            $channel->status = Channel::STATUS_OPEN;
            $channel->creator_id = $context->getActor()->id;

            // Explícitos, não defaults da coluna: a resposta sai deste model em
            // memória, e o que não for setado volta null onde o cliente espera bool.
            $channel->threading_enabled = (bool) $this->settings->get('ramon-chat.threading_default', false);
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
     * Completa o que o Channel::build() faria: slug, criador como membro (senão o
     * canal não aparece na sidebar dele) e o anúncio do canal.
     */
    protected function saveModel(\Illuminate\Database\Eloquent\Model $model, OriginalContext $context): void
    {
        /** @var Channel $model */
        $isNew = ! $model->exists;
        $actor = $context->getActor();

        if ($isNew && ! $actor->isAdmin() && ! $this->throttle->attempt('channel.'.$actor->id, self::CHANNELS_PER_HOUR, 3600)) {
            throw new ValidationException([
                'name' => $this->translator->trans('ramon-chat.api.action_rate_limited'),
            ]);
        }

        // Slug identifica canal de categoria na URL; conversa direta vai por id,
        // porque o nome vem dos participantes.
        if ($model->type === Channel::TYPE_CATEGORY && $model->name !== null && $model->name !== '') {
            if ($isNew || $model->isDirty('name') || $model->slug === null) {
                $model->slug = $model->generateSlug($model->name);
            }
        }

        parent::saveModel($model, $context);

        if (! $isNew) {
            // Edição sem mudança não vai para broadcast; `wasChanged` reflete o que
            // o save gravou de fato.
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
                // Ler o chat exige conta. Sem a trava, estes endpoints responderiam
                // 200 com coleção vazia a qualquer um.
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
                // Só conversas diretas: canal de categoria tira o rótulo do próprio
                // nome, e carregar todos os membros seria a query que isto evita.
                ->eagerLoadWhere(
                    'directParticipants',
                    fn ($query, Context $context) => $query
                        ->whereIn(
                            'chat_channel_user.channel_id',
                            Channel::query()
                                ->where('type', Channel::TYPE_DIRECT)
                                ->select('chat_channels.id')
                        )
                        // O leitor nunca entra no próprio rótulo nem nos avatares
                        // (`displayName()` e `Channel#others()` o descartam). Fora no
                        // SQL para o usuário do ator não ir para `included` em toda
                        // listagem, onde os três contadores de não lidas custam query.
                        ->where('users.id', '!=', (int) $context->getActor()->id)
                )
                // Serializada além de carregada: já vem para o rótulo, e assim a
                // sidebar desenha os avatares da conversa na primeira pintura.
                ->defaultInclude(['directParticipants'])
                // Leitura da aba Membros. Grupos pelo mesmo motivo de withMembers().
                ->eagerLoadWhenIncluded(['participants' => ['participants.groups']]),

            Endpoint\Index::make()
                ->authenticated()
                ->defaultSort('-lastMessageAt')
                ->eagerLoad(['creator', 'lastMessage.user'])
                // Vários campos e as policies leem a membership do ator; carregada
                // uma vez por página, não por canal. `membershipFor()` confere que a
                // linha é do ator antes de confiar nela.
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
                // Só conversas diretas: canal de categoria tira o rótulo do próprio
                // nome, e carregar todos os membros seria a query que isto evita.
                ->eagerLoadWhere(
                    'directParticipants',
                    fn ($query, Context $context) => $query
                        ->whereIn(
                            'chat_channel_user.channel_id',
                            Channel::query()
                                ->where('type', Channel::TYPE_DIRECT)
                                ->select('chat_channels.id')
                        )
                        // O leitor nunca entra no próprio rótulo nem nos avatares
                        // (`displayName()` e `Channel#others()` o descartam). Fora no
                        // SQL para o usuário do ator não ir para `included` em toda
                        // listagem, onde os três contadores de não lidas custam query.
                        ->where('users.id', '!=', (int) $context->getActor()->id)
                )
                ->defaultInclude(['directParticipants'])
                ->paginate(50),

            // visible() recebe (model, context) quando há model e só (context)
            // quando não há. No Create ainda não há model, então closure de dois
            // argumentos é TypeError. Os endpoints com model usam ->can(), como o core.
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

                    // Soft delete: uma discussão de arquivo pode citar a transcrição,
                    // então as linhas ficam até o comando de retenção limpar.
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

                    // O marcador de leitura mora na membership; num canal para todos
                    // o leitor pode ainda não ter uma, e ler é o que a cria.
                    $this->memberships->ensure($channel, $actor);

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

                    // Entrar e entrar oculto são direitos separados: forjar
                    // `hidden: true` dá 403, não uma membership invisível.
                    if (! $actor->can($hidden ? 'joinHidden' : 'join', $channel)) {
                        throw new ForbiddenException();
                    }

                    $this->assertNotTogglingMembership($channel, $actor);

                    // Aceitar convite é entrar. Consumido antes, para o anúncio dizer
                    // quem convidou.
                    $invite = $this->invitations->accept($channel, $actor);

                    $membership = $this->memberships->join($channel, $actor, hidden: $hidden);

                    // Só anuncia se algo mudou: entrar onde já está é no-op e não
                    // pode gravar outra linha "entrou".
                    if ($membership->wasRecentlyCreated || $membership->wasChanged(['left_at', 'hidden'])) {
                        $this->events->dispatch(new UserJoinedChannel(
                            $channel,
                            $actor,
                            $actor,
                            $hidden,
                            acceptedInvite: $invite !== null,
                            invitedBy: $invite?->inviter
                        ));
                    }

                    // O registro atualizado, com as flags: o cliente desenha o
                    // composer a partir de `canPostMessage`.
                    return $channel;
                }),

            // Convidar outras pessoas; em canal privado é a única entrada. Ninguém
            // é posto no canal sem escolher: o convidado recebe a notificação, e
            // aceitar é o que cria a membership.
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

                    // Só usuários visíveis ao ator: uma lista de ids não pode revelar
                    // quais contas existem.
                    $users = User::query()
                        ->whereVisibleTo($actor)
                        ->whereIn('id', $ids)
                        ->get();

                    // O mesmo teto de quem abre a conversa direta vale para quem
                    // entra depois, convites pendentes contados.
                    if ($channel->isDirect()) {
                        $present = $channel->memberships()->whereNull('left_at')->pluck('user_id')
                            ->merge($channel->invites()->pluck('user_id'));
                        $newcomers = $users->pluck('id')->diff($present);

                        if ($present->unique()->count() + $newcomers->count() > Channel::MAX_DIRECT_PARTICIPANTS) {
                            throw new ValidationException([
                                'userIds' => $this->translator->trans('ramon-chat.api.direct_too_many', [
                                    'max' => Channel::MAX_DIRECT_PARTICIPANTS,
                                ]),
                            ]);
                        }
                    }

                    // Cada convite é uma notificação alheia, então há cota por hora.
                    // Cobra por pessoa pedida, não por convite criado: descobrir quais
                    // seriam pulados é o trabalho que a cota protege.
                    if (! $actor->isAdmin()) {
                        foreach ($users as $ignored) {
                            if (! $this->throttle->attempt('invite.'.$actor->id, self::INVITES_PER_HOUR, 3600)) {
                                throw new ValidationException([
                                    'userIds' => $this->translator->trans('ramon-chat.api.invite_rate_limited'),
                                ]);
                            }
                        }
                    }

                    // Membros e já convidados são pulados lá dentro: repetir não
                    // acumula convites nem renotifica.
                    $invited = $this->invitations->invite($channel, $users, $actor);

                    foreach ($invited as $invite) {
                        $this->events->dispatch(new UserWasInvited($channel, $invite->user, $actor, $invite));
                    }

                    if ($invited !== []) {
                        $this->events->dispatch(new UsersWereInvited($channel, $actor, $invited));
                    }

                    return $this->withMembers($channel)->load('invitedUsers.groups');
                })
                ->defaultInclude(['participants', 'invitedUsers']),

            // Retira um convite ainda sem resposta. As respostas do convidado têm
            // rotas próprias (AcceptInviteController, DeclineInviteController):
            // canal privado ainda é invisível para ele.
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

                    return $this->withMembers($channel)->load('invitedUsers.groups');
                })
                ->defaultInclude(['participants', 'invitedUsers']),

            // Remover outra pessoa. Separado de `leave` para a checagem de permissão
            // não virar um branch dentro de toda saída comum.
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

                    // Com scope de visibilidade, como em `addMembers`, para não servir
                    // de sonda de contas.
                    $user = $userId > 0
                        ? User::query()->whereVisibleTo($actor)->whereKey($userId)->first()
                        : null;

                    if ($user === null) {
                        throw new ValidationException([
                            'userId' => $this->translator->trans('ramon-chat.api.members_empty'),
                        ]);
                    }

                    // Remover a si mesmo é `leave`, que não exige manageMembers.
                    if ((int) $user->id === (int) $actor->id) {
                        throw new ValidationException([
                            'userId' => $this->translator->trans('ramon-chat.api.cannot_remove_self'),
                        ]);
                    }

                    // Ninguém expulsa quem tem permissão maior, senão dois moderadores
                    // se expulsariam em turnos. Admin é exceção: a palavra dele é final.
                    if (! $actor->isAdmin() && $user->can('ramon-chat.moderate')) {
                        throw new ForbiddenException();
                    }

                    // Mesma regra dentro da sala: moderador do canal não expulsa o dono
                    // nem outro moderador; só quem controla o canal pode.
                    $target = $channel->membershipFor($user);

                    // Para quem não vê ocultos, membro oculto não é membro: a resposta
                    // é a de um estranho, senão o dono acharia quem inspeciona pela
                    // diferença e o expulsaria.
                    if ($target !== null && $target->isHidden() && ! $this->ownership->seesHiddenMembers($actor)) {
                        $target = null;
                    }

                    if (! $this->ownership->controls($actor, $channel)
                        && ((int) $channel->creator_id === (int) $user->id
                            || ($target !== null && $target->isModerator()))) {
                        throw new ForbiddenException();
                    }

                    $membership = $target !== null ? $this->memberships->leave($channel, $user, $actor) : null;

                    // Não é membro: sucesso aqui afirmaria uma remoção que não houve.
                    if ($membership === null) {
                        throw new ValidationException([
                            'userId' => $this->translator->trans('ramon-chat.api.not_a_member'),
                        ]);
                    }

                    $this->events->dispatch(new UserLeftChannel($channel, $user, $actor, $membership->isHidden()));

                    return $this->withMembers($channel);
                })
                ->defaultInclude(['participants']),

            // Moderador do canal: o que o papel dá é do ChannelOwnership; quem pode
            // conceder, do ChannelPolicy::manageModerators.
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

            // Transferência em três passos: o dono escolhe e recebe um código por
            // e-mail, digita o código, e o membro aceita ou recusa. Cada passo
            // responde com canal e membros. Regras em Service\OwnershipTransfers.
            Endpoint\Endpoint::make('startTransfer')
                ->route('POST', '/{id}/transfer')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('transferOwnership', $channel)) {
                        throw new ForbiddenException();
                    }

                    $userId = (int) Arr::get($context->body(), 'data.attributes.userId', 0);

                    $user = $userId > 0
                        // @phpstan-ignore method.notFound (Flarum model scope)
                        ? User::query()->whereVisibleTo($actor)->whereKey($userId)->first()
                        : null;

                    if ($user === null) {
                        throw new ValidationException([
                            'userId' => $this->translator->trans('ramon-chat.api.not_a_member'),
                        ]);
                    }

                    $this->transfers->start($channel, $actor, $user);

                    return $this->withMembers($channel);
                })
                ->defaultInclude(['participants']),

            Endpoint\Endpoint::make('confirmTransfer')
                ->route('POST', '/{id}/transfer/confirm')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;

                    $this->transfers->confirm(
                        $channel,
                        $context->getActor(),
                        (string) Arr::get($context->body(), 'data.attributes.code', '')
                    );

                    return $this->withMembers($channel);
                })
                ->defaultInclude(['participants']),

            Endpoint\Endpoint::make('cancelTransfer')
                ->route('POST', '/{id}/transfer/cancel')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;

                    $this->transfers->cancel($channel, $context->getActor());

                    return $this->withMembers($channel);
                })
                ->defaultInclude(['participants']),

            Endpoint\Endpoint::make('acceptTransfer')
                ->route('POST', '/{id}/transfer/accept')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;

                    $this->transfers->accept($channel, $context->getActor());

                    return $this->withMembers($channel);
                })
                ->defaultInclude(['participants']),

            Endpoint\Endpoint::make('declineTransfer')
                ->route('POST', '/{id}/transfer/decline')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;

                    $this->transfers->decline($channel, $context->getActor());

                    return $this->withMembers($channel);
                })
                ->defaultInclude(['participants']),

            // Ranks do canal (rótulos antes do nome dos membros). Cada endpoint
            // responde com o canal, cujo `rankBook` é o estado novo. Quem pode:
            // ChannelPolicy::manageRanks; o que é aceito: Service\ChannelRanks.
            Endpoint\Endpoint::make('createRank')
                ->route('POST', '/{id}/ranks')
                ->authenticated()
                ->action(fn (Context $context) => $this->rankAction(
                    $context,
                    fn (Channel $channel, User $actor, array $attributes) => $this->ranks->create($channel, $actor, $attributes)
                )),

            // `rankId` é o id do rank, ou `owner` / `moderator` para os dois que
            // todo canal tem.
            Endpoint\Endpoint::make('updateRank')
                ->route('POST', '/{id}/ranks/update')
                ->authenticated()
                ->action(fn (Context $context) => $this->rankAction(
                    $context,
                    fn (Channel $channel, User $actor, array $attributes) => $this->ranks->update($channel, $actor, $this->rankTarget($attributes), $attributes)
                )),

            // Apaga o rank e quem o tinha; num rank embutido, volta aos defaults.
            Endpoint\Endpoint::make('deleteRank')
                ->route('POST', '/{id}/ranks/delete')
                ->authenticated()
                ->action(fn (Context $context) => $this->rankAction(
                    $context,
                    fn (Channel $channel, User $actor, array $attributes) => $this->ranks->delete($channel, $actor, $this->rankTarget($attributes))
                )),

            Endpoint\Endpoint::make('reorderRanks')
                ->route('POST', '/{id}/ranks/order')
                ->authenticated()
                ->action(fn (Context $context) => $this->rankAction(
                    $context,
                    fn (Channel $channel, User $actor, array $attributes) => $this->ranks->reorder($channel, $actor, (array) ($attributes['rankIds'] ?? []))
                )),

            // Todos os ranks de um membro de uma vez: os omitidos são retirados.
            Endpoint\Endpoint::make('assignRanks')
                ->route('POST', '/{id}/ranks/assign')
                ->authenticated()
                ->action(fn (Context $context) => $this->rankAction(
                    $context,
                    function (Channel $channel, User $actor, array $attributes) {
                        $userId = (int) ($attributes['userId'] ?? 0);

                        $user = $userId > 0
                            // @phpstan-ignore method.notFound (Flarum model scope)
                            ? User::query()->whereVisibleTo($actor)->whereKey($userId)->first()
                            : null;

                        if ($user === null) {
                            throw new ValidationException([
                                'userId' => $this->translator->trans('ramon-chat.api.not_a_member'),
                            ]);
                        }

                        $this->ranks->assign($channel, $actor, $user, (array) ($attributes['rankIds'] ?? []));
                    }
                )),

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

                    $this->assertNotTogglingMembership($channel, $actor);

                    $membership = $this->memberships->leave($channel, $actor, $actor);

                    // Sair de onde não está não muda nada e não pode ser anunciado.
                    if ($membership !== null) {
                        $this->events->dispatch(
                            new UserLeftChannel($channel, $actor, $actor, $membership->isHidden())
                        );
                    }
                })
                ->response(fn () => new EmptyResponse(204)),

            // Preferências de notificação do canal: nível e mute.
            Endpoint\Endpoint::make('notifications')
                ->route('POST', '/{id}/notifications')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    // Preferências moram na membership, mas não são porta de entrada:
                    // `ensure()` só cria uma para quem um canal para todos já inclui,
                    // senão daria para entrar passando por cima da policy de join.
                    if (! $actor->can('view', $channel) || $this->memberships->ensure($channel, $actor) === null) {
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

                    // Antes da ability, que um canal arquivado também reprova: um 403
                    // diria ao dono que ele não pode, quando só não pode por aqui.
                    if ($channel->isArchived()) {
                        throw new ValidationException([
                            'status' => $this->translator->trans('ramon-chat.api.channel_archived_status'),
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

            // Sai do arquivo como fechado. O realtime é o da mudança de status, que
            // já atualiza o composer dos membros.
            Endpoint\Endpoint::make('unarchive')
                ->route('POST', '/{id}/unarchive')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Channel $channel */
                    $channel = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('unarchive', $channel)) {
                        throw new ForbiddenException();
                    }

                    return $this->archiver->unarchive($channel, $actor);
                }),
        ];
    }

    public function fields(): array
    {
        return [
            // Aqui só se cria `category`. Conversa direta é com o
            // StartDirectController, que valida os participantes e reaproveita a
            // conversa existente.
            Schema\Str::make('type')
                ->writableOnCreate()
                ->requiredOnCreate()
                ->in([Channel::TYPE_CATEGORY]),

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

            // Só leitura: a imagem é definida pelo endpoint multipart próprio.
            Schema\Str::make('imageUrl')
                ->get(fn (Channel $c) => $c->imageUrl()),

            // Pictograma Unicode (o que o picker grava) ou shortcode simples (API
            // ou linhas antigas). Qualquer outra coisa apareceria como texto cru.
            Schema\Str::make('emoji')
                ->nullable()
                ->maxLength(60)
                ->regex('/^(?:[a-z0-9_+\-]{1,60}|[\p{Extended_Pictographic}\x{FE0F}\x{200D}\x{1F3FB}-\x{1F3FF}]{1,16})$/u')
                ->writable(fn (Channel $c, Context $context) => $this->mayWrite($c, $context)),

            Schema\Str::make('status'),

            // Público ou só por convite. Passa por mayWriteCategoryField, como
            // tagId: os dois decidem quem vê o canal. Quem pode criar canal escolhe
            // o tipo; não há permissão separada para privado.
            Schema\Boolean::make('isPrivate')
                ->get(fn (Channel $c) => $c->isPrivate())
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context)),

            // Quem posta: todos ou só moderadores. Validado contra os dois valores,
            // para um erro de digitação não gerar canal onde ninguém posta.
            Schema\Str::make('postPermission')
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context))
                ->set(function (Channel $channel, $value) {
                    $channel->post_permission = $value === Channel::POST_MODERATORS
                        ? Channel::POST_MODERATORS
                        : Channel::POST_ALL;
                }),

            Schema\Boolean::make('threadingEnabled')
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context)),

            // Slow mode em segundos (0 desliga). Editável por quem edita o canal,
            // não só admin. Valor fora da faixa é ajustado, não recusado: a
            // interface só oferece passos fixos. Teto de seis horas.
            Schema\Integer::make('slowModeSeconds')
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context))
                ->set(function (Channel $channel, $value) {
                    $channel->slow_mode_seconds = max(0, min(21600, (int) $value));
                }),

            // Horário de funcionamento (ver Schedule\OpeningHours); null é sempre aberto.
            // Quem edita o canal muda. Público para quem vê o canal: são só horários, e
            // o composer precisa deles para dizer quando abre.
            Schema\Arr::make('schedule')
                ->nullable()
                ->get(fn (Channel $c) => ChannelSchedule::of($c))
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context))
                ->set(function (Channel $channel, $value) {
                    try {
                        $schedule = OpeningHours::normalize($value, $this->calendar);
                    } catch (InvalidArgumentException $e) {
                        throw new ValidationException([
                            'schedule' => $this->translator->trans('ramon-chat.api.'.$e->getMessage()),
                        ]);
                    }

                    $channel->setAttribute('schedule', $schedule === null ? null : json_encode($schedule));
                    $this->schedule->forget($channel);
                }),

            // O estado agora e a próxima mudança, calculados no servidor: o cliente não
            // expande recorrência de calendário nem confia no relógio do aparelho.
            Schema\Boolean::make('scheduleOpen')
                ->get(fn (Channel $c) => $this->schedule->state($c)['open']),

            Schema\DateTime::make('scheduleNextChange')
                ->nullable()
                ->get(fn (Channel $c) => $this->schedule->state($c)['nextChange']),

            // O evento ligado sumiu ou saiu do ar: o canal fica fechado até trocarem o
            // horário, e o formulário avisa.
            Schema\Boolean::make('scheduleUnavailable')
                ->get(fn (Channel $c) => $this->schedule->state($c)['unavailable']),

            Schema\Str::make('scheduleEventTitle')
                ->nullable()
                ->get(function (Channel $c) {
                    $schedule = ChannelSchedule::of($c);

                    return ($schedule['mode'] ?? null) === OpeningHours::MODE_EVENT
                        ? $this->calendar->title((int) $schedule['eventId'])
                        : null;
                }),

            // Tamanho máximo da mensagem. Null segue a setting do fórum, não "sem
            // limite" (ver Channel::maxMessageLength()). Ajustado como o slow mode;
            // piso de 100 e teto igual ao maior que a setting do fórum aceita.
            Schema\Integer::make('maxMessageLength')
                ->nullable()
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context))
                ->set(function (Channel $channel, $value) {
                    $channel->max_message_length = ($value === null || (int) $value <= 0)
                        ? null
                        : max(100, min(50000, (int) $value));
                }),

            // De onde o composer conta. Por ator e vindo do servidor, para um
            // reload não esquecer a espera.
            Schema\Integer::make('slowModeRemaining')
                ->get(fn (Channel $c, Context $context) => $c->exists
                    ? $this->slowMode->remainingFor($c, $context->getActor())
                    : 0),

            // Se o slow mode vale para o ator: não vale com a permissão de bypass
            // nem para quem cuida da sala (dono e moderadores). O composer reinicia
            // a contagem sozinho e precisa disto.
            Schema\Boolean::make('bypassesSlowMode')
                ->get(fn (Channel $c, Context $context) => $c->exists
                    && $this->slowMode->isExempt($c, $context->getActor())),

            // Mesma trava dos outros campos de propósito: o json-api-server responde
            // 403 a um campo só *presente* no body e não gravável, e o
            // ChannelFormModal manda `autoJoin` em todo save, mesmo `false`.
            //
            // Ligar não grava nada por conta: quem vê o canal já está nele
            // (Channel::isForEveryone()), e a membership nasce quando houver o que
            // guardar. Mudar o valor exige curadoria global (ver setAudienceFlag()).
            Schema\Boolean::make('autoJoin')
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context))
                ->set(fn (Channel $c, $value, Context $context) => $this->setAudienceFlag($c, 'auto_join', 'autoJoin', $value, $context)),

            // Traz as discussões novas da categoria para o canal. Mesma trava: decide
            // que conteúdo do fórum é copiado; o AnnounceDiscussions é a segunda linha.
            Schema\Boolean::make('postDiscussions')
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context))
                ->set(fn (Channel $c, $value, Context $context) => $this->setAudienceFlag($c, 'post_discussions', 'postDiscussions', $value, $context)),

            // Adiciona quem participa da categoria, não todo mundo como o autoJoin,
            // mas ainda inclui quem não pediu: mesma trava.
            Schema\Boolean::make('autoJoinOnReply')
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context))
                ->set(fn (Channel $c, $value, Context $context) => $this->setAudienceFlag($c, 'auto_join_on_reply', 'autoJoinOnReply', $value, $context)),

            Schema\Boolean::make('allowChannelWideMentions')
                ->writable(fn (Channel $c, Context $context) => $this->mayWrite($c, $context)),

            // Vincular categoria restringe quem vê o canal. Escolha do criador, mas
            // só entre as categorias que ele mesmo vê.
            Schema\Integer::make('tagId')
                ->nullable()
                ->writable(fn (Channel $c, Context $context) => $this->mayWriteCategoryField($c, $context))
                ->set(fn (Channel $c, $value, Context $context) => $this->setTagId($c, $value, $context)),

            Schema\Integer::make('messagesCount'),
            Schema\Integer::make('userCount'),
            Schema\Integer::make('lastMessageId')->nullable(),
            Schema\DateTime::make('lastMessageAt')->nullable(),
            Schema\DateTime::make('createdAt'),
            Schema\DateTime::make('archivedAt')->nullable(),
            Schema\Integer::make('archivedDiscussionId')->nullable(),

            // ── Exibição ──
            Schema\Str::make('displayName')
                ->get(fn (Channel $c, Context $context) => $this->displayName($c, $context->getActor())),

            // ── Membership do ator ──
            Schema\Boolean::make('isFollowing')
                ->get(fn (Channel $c, Context $context) => $this->membership($c, $context->getActor())->following
                    ?? $c->includes($context->getActor())),

            Schema\Boolean::make('isMuted')
                ->get(fn (Channel $c, Context $context) => $this->membership($c, $context->getActor())->muted ?? false),

            Schema\Integer::make('notificationLevel')
                ->get(fn (Channel $c, Context $context) => $this->membership($c, $context->getActor())->notification_level
                    ?? ChannelUser::LEVEL_MENTIONS),

            Schema\Integer::make('lastReadMessageId')
                ->get(fn (Channel $c, Context $context) => $this->membership($c, $context->getActor())->last_read_message_id ?? 0),

            Schema\Integer::make('unreadCount')
                ->get(fn (Channel $c, Context $context) => $this->unread->unreadFor($c, $this->membership($c, $context->getActor()))),

            Schema\Integer::make('unreadMentionsCount')
                ->get(fn (Channel $c, Context $context) => $this->membership($c, $context->getActor())->unread_mentions_count ?? 0),

            // ── Flags de capacidade (o cliente não desenha controle morto) ──
            Schema\Boolean::make('canPostMessage')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('postMessage', $c)),

            Schema\Boolean::make('canEdit')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('edit', $c)),

            // Duas ações distintas: voltar e voltar oculto.
            Schema\Boolean::make('canJoinHidden')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('joinHidden', $c)),

            // Para o moderador oculto saber que está oculto.
            Schema\Boolean::make('isHiddenMember')
                ->get(fn (Channel $c, Context $context) => (bool) $this->membership($c, $context->getActor())?->isHidden()),

            Schema\Boolean::make('canJoin')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('join', $c)),

            // Convite pendente do leitor e quem enviou. Lido da relação
            // `actorInvite`, que as listagens já fazem eager-load.
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

            Schema\Boolean::make('canUnarchive')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('unarchive', $c)),

            Schema\Boolean::make('canDelete')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('delete', $c)),

            Schema\Boolean::make('canManageMembers')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('manageMembers', $c)),

            Schema\Boolean::make('canManageModerators')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('manageModerators', $c)),

            Schema\Boolean::make('canManageRanks')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('manageRanks', $c)),

            // Ranks e quem tem cada um. Igual para todo leitor, então vem de cache
            // por canal. Fica fora da listagem (omitido, não null, para um refresh
            // não apagar o que o cliente já tem); mensagens trazem o rank do autor.
            Schema\Arr::make('rankBook')
                ->nullable()
                ->visible(fn (Channel $c, Context $context) => ! $context->listing()
                    && ScopeChannelVisibility::readsContents($context->getActor(), $c))
                ->get(fn (Channel $c) => $this->ranks->book($c)),

            Schema\Boolean::make('canTransferOwnership')
                ->get(fn (Channel $c, Context $context) => $context->getActor()->can('transferOwnership', $c)),

            // Transferência pendente, como o leitor pode vê-la: quem iniciou (ou
            // pode iniciar) desde o código; o destinatário depois do código; mais
            // ninguém. Só junto da lista de membros, como `moderatorIds`.
            Schema\Arr::make('ownershipTransfer')
                ->nullable()
                ->get(fn (Channel $c, Context $context) => $this->transferState($c, $context)),

            // A aba Membros marca o dono sem carregar o criador.
            Schema\Integer::make('creatorId')
                ->property('creator_id')
                ->nullable(),

            // Moderadores do canal, sempre que a lista de membros vem na resposta;
            // vazio na listagem. O serializer calcula atributos antes de resolver
            // includes, então não basta ler a relação carregada: os endpoints de
            // membros a carregam (ver withMembers()) e o resto é resolvido aqui.
            Schema\Arr::make('moderatorIds')
                ->get(function (Channel $c, Context $context) {
                    if (! ScopeChannelVisibility::readsContents($context->getActor(), $c)) {
                        return [];
                    }

                    if (! $c->relationLoaded('participants')) {
                        $include = explode(',', (string) ($context->request->getQueryParams()['include'] ?? ''));

                        if (! in_array('participants', array_map('trim', $include), true)) {
                            return [];
                        }

                        return $c->memberships()
                            ->whereNull('left_at')
                            ->where('hidden', false)
                            ->where('is_moderator', true)
                            ->pluck('user_id')
                            ->map(fn ($id) => (int) $id)
                            ->values()
                            ->all();
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

            // Só onde o conteúdo pode ser lido: o convidado de canal privado vê a
            // linha, não a última mensagem.
            Schema\Relationship\ToOne::make('lastMessage')
                ->type('chat-messages')
                ->includable()
                ->visible(fn (Channel $c, Context $context) => ScopeChannelVisibility::readsContents($context->getActor(), $c)),

            // Sem `includable()`, `?include=participants` dá 400. Só na leitura de
            // um canal (aba Membros): na listagem carregaria todos os membros de
            // até 50 canais.
            Schema\Relationship\ToMany::make('participants')
                ->type('users')
                ->includable()
                ->visible(fn (Channel $c, Context $context) => ! $context->endpoint instanceof Endpoint\Index
                    && $context->getActor()->can('viewMembers', $c)),

            // Convidados sem resposta, só para quem gerencia membros: não é algo
            // que a sala lê.
            Schema\Relationship\ToMany::make('invitedUsers')
                ->type('users')
                ->includable()
                ->visible(fn (Channel $c, Context $context) => $context->getActor()->can('manageMembers', $c)),

            /*
             * O outro lado da conversa direta, para os avatares da sidebar. Não é
             * `participants`, que na listagem traria todos os membros de todo canal:
             * esta só tem eager-load em conversas diretas (`eagerLoadWhere` no Index
             * e no Show).
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
     * Se o ator pode gravar um campo configurável. Criar e editar são direitos
     * distintos: só com `edit`, quem tem `createChannel` não poderia nomear o canal,
     * porque ChannelPolicy::edit cai em `moderate` enquanto não há criador.
     */
    protected function mayWrite(Channel $channel, Context $context): bool
    {
        if (! $channel->exists) {
            return $context->getActor()->can('createChannel');
        }

        return $context->getActor()->can('edit', $channel);
    }

    /**
     * Campos só de canal de categoria (tag, threads, os dois auto-join). Reforço ao
     * ChannelPolicy::edit: conversa direta tira a visibilidade dos participantes, e
     * uma tag criaria uma segunda regra conflitante.
     */
    protected function mayWriteCategoryField(Channel $channel, Context $context): bool
    {
        if ($channel->exists && $channel->isDirect()) {
            return false;
        }

        return $this->mayWrite($channel, $context);
    }

    /**
     * Grava uma chave que vai além da sala (incluir contas, copiar conteúdo do
     * fórum), mas só muda o valor para quem faz curadoria global (moderador do chat,
     * `editChannel` ou admin). O campo segue gravável porque o form manda tudo em
     * todo save (ver autoJoin): o valor igual ao salvo passa, só a mudança é recusada.
     */
    protected function setAudienceFlag(Channel $channel, string $column, string $field, mixed $value, Context $context): void
    {
        $value = (bool) $value;

        if ($value === (bool) $channel->getAttribute($column)) {
            return;
        }

        if (! $this->curatesChannels($context->getActor())) {
            throw new ValidationException([
                $field => $this->translator->trans('ramon-chat.api.channel_flag_not_allowed'),
            ]);
        }

        $channel->setAttribute($column, $value);
    }

    /**
     * Vincula ou desvincula a categoria. Recusa categoria invisível ao ator, e
     * qualquer uma com o flarum/tags desligado: o canal sumiria, porque o
     * ScopeChannelVisibility falha fechado.
     */
    protected function setTagId(Channel $channel, mixed $value, Context $context): void
    {
        $tagId = ($value === null || (int) $value <= 0) ? null : (int) $value;

        if ($tagId === ($channel->tag_id === null ? null : (int) $channel->tag_id)) {
            return;
        }

        if ($tagId !== null) {
            $visible = $this->extensions->isEnabled('flarum-tags')
                && \Flarum\Tags\Tag::query()
                    // @phpstan-ignore method.notFound (Flarum model scope)
                    ->whereVisibleTo($context->getActor())
                    ->whereKey($tagId)
                    ->exists();

            if (! $visible) {
                throw new ValidationException([
                    'tagId' => $this->translator->trans('ramon-chat.api.channel_tag_not_found'),
                ]);
            }
        }

        $channel->tag_id = $tagId;
    }

    protected function curatesChannels(User $actor): bool
    {
        return $actor->hasPermission('ramon-chat.moderate')
            || $actor->hasPermission('ramon-chat.editChannel');
    }

    /**
     * Entrar e sair gravam uma linha na sala, então ir e voltar vira flood. Admins
     * ficam isentos, como no RateLimiter.
     */
    protected function assertNotTogglingMembership(Channel $channel, User $actor): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if (! $this->throttle->attempt('membership.'.$actor->id.'.'.$channel->id, self::MEMBERSHIP_CHANGES_PER_MINUTE, 60)) {
            throw new ValidationException([
                'channel' => $this->translator->trans('ramon-chat.api.membership_rate_limited'),
            ]);
        }
    }

    /**
     * Executa uma escrita de rank e responde com o canal, que traz o `rankBook` novo.
     *
     * @param  callable(Channel, User, array<string, mixed>): mixed  $write
     */
    protected function rankAction(Context $context, callable $write): Channel
    {
        /** @var Channel $channel */
        $channel = $context->model;
        $actor = $context->getActor();

        if (! $actor->can('manageRanks', $channel)) {
            throw new ForbiddenException();
        }

        if (! $actor->isAdmin()
            && ! $this->throttle->attempt('ranks.'.$actor->id, self::RANK_CHANGES_PER_MINUTE, 60)) {
            throw new ValidationException([
                'rankId' => $this->translator->trans('ramon-chat.api.rank_rate_limited'),
            ]);
        }

        $attributes = Arr::get($context->body(), 'data.attributes', []);

        $write($channel, $actor, is_array($attributes) ? $attributes : []);

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function rankTarget(array $attributes): int|string
    {
        $target = $attributes['rankId'] ?? null;

        if (is_string($target) && in_array($target, ChannelRank::builtins(), true)) {
            return $target;
        }

        return is_numeric($target) ? (int) $target : 0;
    }

    /**
     * Dá ou tira o papel de moderador do canal, só em membership ativa: dado a quem
     * saiu, viraria surpresa no dia em que voltasse.
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

        // Para quem não vê ocultos, membro oculto é não membro, como em
        // `removeMember`: a resposta diferente denunciaria quem inspeciona, e
        // promovê-lo anunciaria o nome dele à sala.
        if ($membership !== null && $membership->isHidden() && ! $this->ownership->seesHiddenMembers($actor)) {
            $membership = null;
        }

        if ($membership === null || $membership->hasLeft()) {
            throw new ValidationException([
                'userId' => $this->translator->trans('ramon-chat.api.not_a_member'),
            ]);
        }

        if ($membership->isModerator() !== $moderator) {
            $membership->is_moderator = $moderator;
            // Quando o papel foi dado decide quem herda o canal se o dono sair
            // (OwnershipSuccession).
            $membership->moderator_since = $moderator ? Carbon::now() : null;
            $membership->save();

            $this->events->dispatch(new ChannelModeratorChanged($channel, $user, $moderator, $actor));
        }

        return $this->withMembers($channel);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function transferState(Channel $channel, Context $context): ?array
    {
        if (! $channel->exists || ! $channel->isCategory()) {
            return null;
        }

        if (! $channel->relationLoaded('participants')) {
            $include = explode(',', (string) ($context->request->getQueryParams()['include'] ?? ''));

            if (! in_array('participants', array_map('trim', $include), true)) {
                return null;
            }
        }

        $transfer = $this->transfers->pending($channel);

        if ($transfer === null) {
            return null;
        }

        $actor = $context->getActor();
        $actorId = (int) $actor->id;
        $outgoing = (int) $transfer->from_user_id === $actorId || $actor->can('transferOwnership', $channel);

        if (! $outgoing && ((int) $transfer->to_user_id !== $actorId || ! $transfer->isConfirmed())) {
            return null;
        }

        return [
            'id'         => (int) $transfer->id,
            'fromUserId' => (int) $transfer->from_user_id,
            'toUserId'   => (int) $transfer->to_user_id,
            'confirmed'  => $transfer->isConfirmed(),
            'incoming'   => ! $outgoing,
            'expiresAt'  => $transfer->expires_at->toIso8601String(),
        ];
    }

    /**
     * O canal com a lista de membros recarregada: uma cópia anterior traria os
     * papéis antigos nos pivots, e o serializer lê `moderatorIds` antes do include.
     * Com os grupos, porque para admin o UserResource do core pergunta
     * `editCredentials` de cada usuário, e isso faria uma query por membro.
     */
    protected function withMembers(Channel $channel): Channel
    {
        return $channel->load('participants.groups');
    }

    /**
     * A membership do ator, lida por vários campos. O memo fica na instância do
     * canal (`membershipFor()`), não aqui: o resource vive tanto quanto o container
     * e vazaria estado entre requisições. Cada requisição carrega seus models.
     */
    protected function membership(Channel $channel, User $actor): ?ChannelUser
    {
        if (! $actor->exists) {
            return null;
        }

        return $channel->membershipFor($actor);
    }

    /**
     * Conversa direta não tem nome gravado: o rótulo são os outros participantes,
     * do ponto de vista de quem lê.
     */
    protected function displayName(Channel $channel, User $actor): string
    {
        if (! $channel->isDirect()) {
            return (string) ($channel->name ?? '');
        }

        if ($channel->name !== null && $channel->name !== '') {
            return $channel->name;
        }

        // O conjunto já carregado pelo endpoint, se houver: uma query para a
        // sidebar toda, não uma por conversa.
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
