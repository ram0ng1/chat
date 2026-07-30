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
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A resolved mention extracted from a message at send time. Persisting these
 * means notification fan-out and unread-mention counts never re-parse content.
 *
 * @property int $id
 * @property int $message_id
 * @property string $type
 * @property int|null $user_id
 * @property int|null $group_id
 * @property Carbon|null $created_at
 * @property-read Message|null $message
 * @property-read User|null $user
 * @property-read Group|null $group
 */
class MessageMention extends AbstractModel
{
    public const TYPE_USER = 'user';
    public const TYPE_GROUP = 'group';

    /** Everyone currently present in the channel. */
    public const TYPE_HERE = 'here';

    /** Every channel member, present or not. */
    public const TYPE_ALL = 'all';

    protected $table = 'chat_message_mentions';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'message_id' => 'integer',
        'user_id'    => 'integer',
        'group_id'   => 'integer',
        'created_at' => 'datetime',
    ];

    public function isChannelWide(): bool
    {
        return in_array($this->type, [self::TYPE_HERE, self::TYPE_ALL], true);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}
