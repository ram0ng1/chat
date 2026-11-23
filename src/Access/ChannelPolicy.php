<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Ramon\Chat\Channel;
use Ramon\Chat\Schedule\ChannelSchedule;
use Ramon\Chat\Service\ChannelOwnership;

/**
 * Permissões sobre canais. O Gate do Flarum só aplica o fallback de admin quando
 * nenhuma policy decidiu, então `false` nega até para admins. Por isso: `true`
 * concede, `false` só para invariantes estruturais e `null` quando falta permissão.
 */
class ChannelPolicy extends AbstractPolicy
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected VisibilityCache $cache,
        protected ChannelOwnership $ownership,
        protected ChannelSchedule $schedule
    ) {
    }

    /**
     * Memoizado por request: a sidebar resolve várias capabilities por canal e
     * quase todas passam por aqui, cada uma com o `EXISTS` do scope inteiro.
     */
    public function view(User $actor, Channel $channel): ?bool
    {
        return ScopeChannelVisibility::visibleTo($actor, $channel, $this->cache) ?: null;
    }

    /**
     * Ver não basta: canais fechados e arquivados continuam legíveis, mas
     * congelados.
     */
    public function postMessage(User $actor, Channel $channel): ?bool
    {
        // As rotas já barram visitantes, mas a policy responde certo sozinha.
        if (! $actor->exists) {
            return false;
        }

        // Estrutural: ninguém posta em canal congelado, nem admin.
        if (! $channel->acceptsMessages()) {
            return false;
        }

        if (! $this->view($actor, $channel)) {
            return null;
        }

        // Fora do horário do canal, só quem cuida da sala escreve: moderadores do
        // chat, o dono e os moderadores do canal (admins têm toda permissão). Assim a
        // moderação prepara o evento antes de abrir.
        if (! $this->schedule->isOpen($channel) && ! $this->ownership->moderates($actor, $channel)) {
            return false;
        }

        // Canal de anúncios: todos leem, moderadores (e o dono) escrevem.
        if ($channel->restrictsPostingToModerators()
            && ! $this->ownership->moderates($actor, $channel)) {
            return false;
        }

        // Postar exige ser membro em qualquer canal: quem foi removido e guardou o
        // link não continua falando. Num canal público o composer vira o botão de
        // entrar; no canal para todos, `includes()` só é false para quem saiu.
        return $channel->includes($actor) ? true : false;
    }

    public function join(User $actor, Channel $channel): ?bool
    {
        // O cliente desenha o botão de entrar a partir daqui; visitante não tem
        // como ser membro.
        if (! $actor->exists) {
            return false;
        }

        // Estrutural: em canal direto só se entra por convite.
        if ($channel->isDirect() || ! $channel->isOpen()) {
            return false;
        }

        // Canal privado é só por convite. Entram também os mesmos que o enxergam
        // (ScopeChannelVisibility::restrictPrivate): `accessPrivateChannels`,
        // `inspectChannels` e quem modera o canal. Ver sem poder entrar deixaria a
        // permissão parecendo quebrada. Um convite pendente também vale: aceitá-lo
        // é entrar, e o endpoint consome o convite.
        if ($channel->isPrivate()
            && $channel->membershipFor($actor) === null
            && ! $actor->hasPermission('ramon-chat.accessPrivateChannels')
            && ! $actor->hasPermission('ramon-chat.inspectChannels')
            && ! $this->ownership->moderates($actor, $channel)
            && $channel->pendingInviteFor($actor) === null) {
            return false;
        }

        return $this->view($actor, $channel);
    }

    /**
     * Entrar sem aparecer: fora da lista e da contagem, sem aviso nem notificação.
     * Permissão própria (`inspectChannels`) para dar a um grupo de auditoria sem
     * entregar moderação.
     */
    public function joinHidden(User $actor, Channel $channel): ?bool
    {
        if (! $actor->hasPermission('ramon-chat.inspectChannels')) {
            return false;
        }

        return $this->join($actor, $channel);
    }

    /**
     * Editar nome, descrição, emoji, tag e threads: `editChannel` no fórum todo,
     * `editOwnChannels`/`manageOwnChannels` nos próprios (ChannelOwnership) ou
     * `moderate`. Moderador de canal não: ele cuida de pessoas e mensagens.
     */
    public function edit(User $actor, Channel $channel): ?bool
    {
        // Canal direto não tem configuração própria, e o formulário inclui a tag,
        // que decide quem vê o canal. Renomear DM em grupo é `manageMembers`.
        if ($channel->isDirect()) {
            return $actor->hasPermission('ramon-chat.moderate') ? true : false;
        }

        if ($actor->hasPermission('ramon-chat.editChannel')) {
            return true;
        }

        if ($this->ownership->edits($actor, $channel)) {
            return true;
        }

        return $actor->hasPermission('ramon-chat.moderate') ? true : null;
    }

    /**
     * Fechar e reabrir. Não vale em canal arquivado: reabrir pelo status deixaria
     * os carimbos de arquivo para trás. Sair do arquivo é com `unarchive`.
     */
    public function close(User $actor, Channel $channel): ?bool
    {
        if ($channel->isDirect() || $channel->isArchived()) {
            return false;
        }

        return $this->ownership->moderates($actor, $channel) ? true : null;
    }

    /**
     * Arquivar copia a transcrição para uma discussão, então só com o canal
     * fechado, para ela não mudar enquanto chegam mensagens.
     */
    public function archive(User $actor, Channel $channel): ?bool
    {
        if (! (bool) $this->settings->get('ramon-chat.allow_archiving_channels', true)) {
            return false;
        }

        if ($channel->isDirect() || ! $channel->isClosed()) {
            return false;
        }

        return $this->ownership->controls($actor, $channel) ? true : null;
    }

    /**
     * Fora da configuração de arquivamento: desligá-la impede novos arquivos, mas
     * não prende os que já existem.
     */
    public function unarchive(User $actor, Channel $channel): ?bool
    {
        if ($channel->isDirect() || ! $channel->isArchived()) {
            return false;
        }

        return $this->ownership->controls($actor, $channel) ? true : null;
    }

    public function delete(User $actor, Channel $channel): ?bool
    {
        if ($channel->isDirect()) {
            // O histórico direto é dos participantes; só admin o destrói.
            return $actor->isAdmin() ? true : false;
        }

        return $this->ownership->controls($actor, $channel) ? true : null;
    }

    /**
     * Adicionar e remover pessoas: com membros donos dos canais, o criador e os
     * moderadores que ele nomeou cuidam da sala.
     */
    public function manageMembers(User $actor, Channel $channel): ?bool
    {
        if ($channel->isDirect()) {
            if ($channel->creator_id !== $actor->id || $channel->membershipFor($actor) === null) {
                return $this->ownership->moderates($actor, $channel) ? true : null;
            }

            // Conversa a dois com mensagens é dos dois: chamar uma terceira pessoa
            // entregaria o histórico. Só grupos e conversas vazias mudam.
            return $channel->messages_count === 0 || $channel->activeParticipantCount() > 2;
        }

        return $this->ownership->moderates($actor, $channel) ? true : null;
    }

    /**
     * Escolher moderadores: o dono e a moderação do chat, nunca um moderador de
     * canal. Desligado com canais nas mãos dos admins, quando o papel não faz
     * nada (ChannelOwnership).
     */
    public function manageModerators(User $actor, Channel $channel): ?bool
    {
        if ($channel->isDirect() || ! $this->ownership->membersOwnChannels()) {
            return false;
        }

        return $this->ownership->controls($actor, $channel) ? true : null;
    }

    /**
     * Cargos do canal: os mesmos que escolhem moderadores. Cargos são só rótulos e
     * não concedem nada, então valem também com canais nas mãos dos admins
     * (`controls` passa a ser a moderação do chat).
     */
    public function manageRanks(User $actor, Channel $channel): ?bool
    {
        if (! $channel->isCategory() || $channel->isDeleted()) {
            return false;
        }

        return $this->ownership->controls($actor, $channel) ? true : null;
    }

    /**
     * Passar o canal a outro membro: o dono, ou admins (inclusive quando o criador
     * sumiu). A moderação do chat não: posse é entre o dono e quem assume.
     */
    public function transferOwnership(User $actor, Channel $channel): ?bool
    {
        if (! $channel->isCategory()
            || $channel->isArchived()
            || $channel->isDeleted()
            || ! $this->ownership->membersOwnChannels()) {
            return false;
        }

        if ($actor->isAdmin()) {
            return true;
        }

        return $this->ownership->manages($actor, $channel) ? true : null;
    }

    /**
     * @see \Ramon\Chat\MessageMention::TYPE_HERE
     * @see \Ramon\Chat\MessageMention::TYPE_ALL
     */
    public function mentionChannelWide(User $actor, Channel $channel): ?bool
    {
        // Estrutural: o canal desligou @here/@all.
        if (! $channel->allow_channel_wide_mentions) {
            return false;
        }

        // Num canal para todos, @all notificaria o fórum inteiro.
        if ($channel->isForEveryone()) {
            return false;
        }

        if (! $actor->can('postMessage', $channel)) {
            return false;
        }

        return $actor->can('ramon-chat.mentionChannelWide') ? true : null;
    }

    public function viewMembers(User $actor, Channel $channel): ?bool
    {
        // Quem só tem o convite de um canal privado vê a linha (para responder),
        // não quem está lá dentro.
        if (! ScopeChannelVisibility::readsContents($actor, $channel)) {
            return null;
        }

        return $this->view($actor, $channel);
    }

    public function manageWebhooks(User $actor, Channel $channel): ?bool
    {
        return $actor->isAdmin() ? true : null;
    }
}
