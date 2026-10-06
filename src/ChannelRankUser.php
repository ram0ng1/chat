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
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um membro com um cargo criado pelo dono do canal.
 *
 * @property int $rank_id
 * @property int $user_id
 * @property int $channel_id
 * @property Carbon|null $created_at
 * @property-read ChannelRank|null $rank
 * @property-read Channel|null $channel
 */
class ChannelRankUser extends AbstractModel
{
    protected $table = 'chat_channel_rank_user';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'rank_id'    => 'integer',
        'user_id'    => 'integer',
        'channel_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function rank(): BelongsTo
    {
        return $this->belongsTo(ChannelRank::class, 'rank_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }
}
