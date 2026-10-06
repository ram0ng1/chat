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
 * A pending invite: someone with `manageMembers` called a user into the
 * channel and they have not answered yet. Goes away when accepted, declined or
 * cancelled.
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
