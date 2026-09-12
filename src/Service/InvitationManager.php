<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelInvite;

/**
 * Cria e encerra convites para um canal.
 *
 * Ser adicionado a um canal virou uma pergunta: quem gerencia convida, e o
 * convidado entra ou recusa. A associação só nasce no aceite, e é o
 * MembershipManager quem a cria; aqui só existe o convite em si.
 */
class InvitationManager
{
    public function __construct(
        protected ConnectionInterface $db,
        protected ExtensionManager $extensions
    ) {
    }

    /**
     * Se o convidado poderia entrar no canal, deixando de lado o fato de ele
     * ser privado. O convite responde pela privacidade; o resto continua
     * valendo: o chat tem que estar liberado para a conta, um canal fechado
     * não recebe ninguém, e um canal preso a uma categoria restrita continua
     * exigindo a permissão da categoria. Um convite não pode virar uma porta
     * lateral para uma permissão do fórum.
     */
    public function mayEnter(User $actor, Channel $channel): bool
    {
        if (! $actor->exists || ! $actor->can('useChat')) {
            return false;
        }

        if (! $channel->isOpen()) {
            return false;
        }

        if ($channel->isDirect() || $channel->tag_id === null) {
            return true;
        }

        if (! $this->extensions->isEnabled('flarum-tags')) {
            return false;
        }

        return \Flarum\Tags\Tag::query()
            // @phpstan-ignore method.notFound (flarum/tags model scope)
            ->whereHasPermission($actor, 'viewForum')
            ->whereKey($channel->tag_id)
            ->exists();
    }

    /**
     * Convida quem ainda não é membro nem foi convidado.
     *
     * @param  iterable<User>  $users
     * @return ChannelInvite[] Os convites criados, na ordem recebida.
     */
    public function invite(Channel $channel, iterable $users, User $inviter): array
    {
        $created = [];

        foreach ($users as $user) {
            if ($channel->membershipFor($user) !== null) {
                continue;
            }

            if ($channel->pendingInviteFor($user) !== null) {
                continue;
            }

            $invite = new ChannelInvite();
            $invite->channel_id = $channel->id;
            $invite->user_id = $user->id;
            $invite->inviter_id = $inviter->id;
            $invite->save();

            $invite->setRelation('channel', $channel);
            $invite->setRelation('user', $user);
            $invite->setRelation('inviter', $inviter);

            $channel->forgetInvite($user);

            $created[] = $invite;
        }

        return $created;
    }

    /**
     * Consome o convite do usuário, devolvendo-o para quem vai criar a
     * associação. Null quando não havia convite.
     */
    public function accept(Channel $channel, User $user): ?ChannelInvite
    {
        return $this->remove($channel, $user);
    }

    public function decline(Channel $channel, User $user): ?ChannelInvite
    {
        return $this->remove($channel, $user);
    }

    public function cancel(Channel $channel, User $user): ?ChannelInvite
    {
        return $this->remove($channel, $user);
    }

    /**
     * Todos os convites pendentes do canal feitos por um mesmo convidador.
     *
     * É o conjunto que a sincronização de notificações precisa: o
     * NotificationSyncer casa notificações por tipo, assunto e remetente, e
     * recebe a lista completa de quem ainda deve ter a sua.
     *
     * @return User[]
     */
    public function pendingUsersInvitedBy(Channel $channel, ?User $inviter): array
    {
        return ChannelInvite::query()
            ->where('channel_id', $channel->id)
            ->when(
                $inviter !== null,
                fn ($query) => $query->where('inviter_id', $inviter->id),
                fn ($query) => $query->whereNull('inviter_id')
            )
            ->with('user')
            ->get()
            ->map(fn (ChannelInvite $invite) => $invite->user)
            ->filter()
            ->values()
            ->all();
    }

    protected function remove(Channel $channel, User $user): ?ChannelInvite
    {
        return $this->db->transaction(function () use ($channel, $user) {
            /** @var ChannelInvite|null $invite */
            $invite = ChannelInvite::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $user->id)
                ->with('inviter')
                ->first();

            if ($invite === null) {
                return null;
            }

            $invite->delete();

            $invite->setRelation('channel', $channel);
            $invite->setRelation('user', $user);

            $channel->forgetInvite($user);

            return $invite;
        });
    }
}
