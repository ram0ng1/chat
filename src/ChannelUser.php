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
 * A user's membership of, and read state within, a channel.
 *
 * @property int $id
 * @property int $channel_id
 * @property int $user_id
 * @property bool $following
 * @property int $notification_level
 * @property bool $muted
 * @property int|null $last_read_message_id
 * @property int $unread_count
 * @property int $unread_mentions_count
 * @property Carbon|null $last_viewed_at
 * @property Carbon|null $joined_at
 * @property Carbon|null $left_at
 * @property-read Channel|null $channel
 * @property-read User|null $user
 */
class ChannelUser extends AbstractModel
{
    /** Notify on every message in the channel. */
    public const LEVEL_ALWAYS = 2;

    /** Notify only when directly mentioned. The default. */
    public const LEVEL_MENTIONS = 1;

    /** Never notify. */
    public const LEVEL_NEVER = 0;

    protected $table = 'chat_channel_user';

    public $timestamps = true;

    protected $guarded = [];

    protected $casts = [
        'channel_id'            => 'integer',
        'user_id'               => 'integer',
        'following'             => 'boolean',
        'notification_level'    => 'integer',
        'muted'                 => 'boolean',
        'last_read_message_id'  => 'integer',
        'unread_count'          => 'integer',
        'unread_mentions_count' => 'integer',
        'last_viewed_at'        => 'datetime',
        'joined_at'             => 'datetime',
        'left_at'               => 'datetime',
    ];

    public static function levels(): array
    {
        return [self::LEVEL_NEVER, self::LEVEL_MENTIONS, self::LEVEL_ALWAYS];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function hasLeft(): bool
    {
        return $this->left_at !== null;
    }

    /**
     * Muting is deliberately separate from level 0: a muted channel also stops
     * contributing unread badges, whereas level 0 only suppresses notifications.
     */
    public function wantsNotificationFor(bool $isMention): bool
    {
        if ($this->muted) {
            return false;
        }

        return match ($this->notification_level) {
            self::LEVEL_ALWAYS   => true,
            self::LEVEL_MENTIONS => $isMention,
            default              => false,
        };
    }

    public function markReadUpTo(?int $messageId): static
    {
        $this->last_read_message_id = $messageId;
        $this->unread_count = 0;
        $this->unread_mentions_count = 0;
        $this->last_viewed_at = Carbon::now();

        return $this;
    }
}
