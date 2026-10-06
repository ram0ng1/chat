<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\ChannelOwnershipTransferred;

/**
 * Quem fica com o canal quando o dono sai.
 *
 * O moderador mais antigo no papel (`moderator_since`; para papéis anteriores
 * a essa coluna, a entrada no canal, e por fim o id da associação) que possa
 * de fato ser dono de canais. Sem nenhum, o canal fica sem dono: moderadores
 * do chat e administradores continuam no controle, e um administrador pode
 * entregá-lo a alguém pela transferência. O canal nunca vai para um membro
 * qualquer que não recebeu confiança alguma.
 *
 * Só no modo "membros". No modo "administradores" ser dono não concede nada e
 * os papéis de moderador estão desligados, então quem criou o canal continua
 * registrado como criador.
 *
 * `settle()` grava dentro da transação de quem chama (a saída do canal), e
 * `announce()` dispara os eventos depois do commit.
 */
class OwnershipSuccession
{
    public function __construct(
        protected ChannelOwnership $ownership,
        protected OwnershipTransfers $transfers,
        protected Events $events
    ) {
    }

    /**
     * Passa o canal adiante se quem sai é o dono. Null quando não era.
     *
     * @return array{channel: Channel, previous: User, heir: User|null}|null
     */
    public function settle(Channel $channel, User $leaving): ?array
    {
        if (! $channel->isCategory() || ! $this->ownership->membersOwnChannels()) {
            return null;
        }

        /** @var Channel|null $fresh */
        $fresh = Channel::query()->whereKey($channel->id)->lockForUpdate()->first();

        if ($fresh === null || $fresh->creator_id === null || (int) $fresh->creator_id !== (int) $leaving->id) {
            return null;
        }

        $heir = $this->heir($channel, $leaving);

        $fresh->creator_id = $heir?->id;
        $fresh->save();

        if ($heir !== null) {
            ChannelUser::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $heir->id)
                ->update(['is_moderator' => false, 'moderator_since' => null]);
        }

        $channel->creator_id = $fresh->creator_id;
        $channel->syncOriginalAttribute('creator_id');
        $channel->unsetRelation('creator');
        $channel->forgetMembership();

        return ['channel' => $channel, 'previous' => $leaving, 'heir' => $heir];
    }

    /**
     * O que vem depois do commit: a transferência pendente perde o sentido, e
     * a sala fica sabendo do novo dono como numa transferência aceita.
     *
     * @param  array{channel: Channel, previous: User, heir: User|null}|null  $settled
     */
    public function announce(?array $settled, ?User $actor = null): void
    {
        if ($settled === null) {
            return;
        }

        $this->transfers->cancelFor($settled['channel'], null, $actor);

        if ($settled['heir'] !== null) {
            $this->events->dispatch(new ChannelOwnershipTransferred(
                $settled['channel'],
                $settled['heir'],
                $settled['previous'],
                null,
                $actor,
                inherited: true
            ));
        }
    }

    /**
     * As duas etapas numa transação própria, para quem não sai pelo endpoint:
     * uma conta apagada ou anonimizada.
     */
    public function handOver(Channel $channel, User $leaving, ?User $actor = null): void
    {
        $settled = $channel->getConnection()->transaction(fn () => $this->settle($channel, $leaving));

        $this->announce($settled, $actor);
    }

    /**
     * Todos os canais de que a conta é dona, para a exclusão e a
     * anonimização.
     */
    public function handOverAll(User $leaving): void
    {
        Channel::query()
            ->where('creator_id', $leaving->id)
            ->where('type', Channel::TYPE_CATEGORY)
            ->get()
            ->each(fn (Channel $channel) => $this->handOver($channel, $leaving));
    }

    protected function heir(Channel $channel, User $leaving): ?User
    {
        $candidates = ChannelUser::query()
            ->with('user')
            ->where('channel_id', $channel->id)
            ->where('user_id', '!=', $leaving->id)
            ->whereNull('left_at')
            ->where('hidden', false)
            ->where('is_moderator', true)
            ->orderByRaw('CASE WHEN moderator_since IS NULL THEN 1 ELSE 0 END')
            ->orderBy('moderator_since')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $membership) {
            /** @var ChannelUser $membership */
            $user = $membership->user;

            if ($user !== null && $this->mayOwn($user)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * A mesma régua da transferência: sem `manageOwnChannels` ser dono seria
     * inerte, e o herdeiro perderia o papel de moderador em troca de nada.
     */
    protected function mayOwn(User $user): bool
    {
        $suspendedUntil = $user->getAttribute('suspended_until');

        if ($suspendedUntil !== null && Carbon::parse($suspendedUntil)->isFuture()) {
            return false;
        }

        return $user->hasPermission('ramon-chat.manageOwnChannels');
    }
}
