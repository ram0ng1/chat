<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um convite pendente: alguém com `manageMembers` chamou um usuário para o
 * canal e ele ainda não respondeu. Some ao ser aceito, recusado ou cancelado.
 *
 * @property int $id
 * @property int $channel_id
 * @property int $user_id
 * @property int|null $inviter_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Channel|null $channel
 * @property-read User|null $user
 * @property-read User|null $inviter
 */
class ChannelInvite extends AbstractModel
{
    protected $table = 'chat_channel_invites';

    public $timestamps = true;

    protected $casts = [
        'channel_id' => 'integer',
        'user_id'    => 'integer',
        'inviter_id' => 'integer',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }
}
