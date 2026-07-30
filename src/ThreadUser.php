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
 * A user's tracking level and read state within a thread.
 *
 * @property int $id
 * @property int $thread_id
 * @property int $user_id
 * @property int $notification_level
 * @property int|null $last_read_message_id
 * @property int $unread_count
 * @property-read Thread|null $thread
 * @property-read User|null $user
 */
class ThreadUser extends AbstractModel
{
    public const LEVEL_ALWAYS = 2;
    public const LEVEL_MENTIONS = 1;
    public const LEVEL_NEVER = 0;

    protected $table = 'chat_thread_user';

    public $timestamps = true;

    protected $guarded = [];

    protected $casts = [
        'thread_id'            => 'integer',
        'user_id'              => 'integer',
        'notification_level'   => 'integer',
        'last_read_message_id' => 'integer',
        'unread_count'         => 'integer',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function wantsNotificationFor(bool $isMention): bool
    {
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

        return $this;
    }
}
