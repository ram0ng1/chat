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
 * An ownership transfer in progress: whoever started it hands the channel to
 * a member. Before confirmation it awaits the code emailed to whoever started
 * it; afterwards, it awaits the answer from the recipient.
 *
 * @property int $id
 * @property int $channel_id
 * @property int $from_user_id
 * @property int $to_user_id
 * @property string|null $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Channel|null $channel
 * @property-read User|null $fromUser
 * @property-read User|null $toUser
 */
class ChannelTransfer extends AbstractModel
{
    protected $table = 'chat_channel_transfers';

    public $timestamps = true;

    protected $hidden = ['code_hash'];

    protected $casts = [
        'channel_id'   => 'integer',
        'from_user_id' => 'integer',
        'to_user_id'   => 'integer',
        'attempts'     => 'integer',
        'expires_at'   => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
