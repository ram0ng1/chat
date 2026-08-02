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
use Flarum\Foundation\Paths;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a chat message.
 *
 * @property int $id
 * @property int|null $message_id
 * @property int|null $user_id
 * @property string $path
 * @property string $file_name
 * @property string|null $mime_type
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Message|null $message
 * @property-read User|null $user
 */
class Upload extends AbstractModel
{
    protected $table = 'chat_uploads';

    public $timestamps = true;


    protected $casts = [
        'message_id' => 'integer',
        'user_id'    => 'integer',
        'size'       => 'integer',
        'width'      => 'integer',
        'height'     => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isImage(): bool
    {
        return $this->mime_type !== null && str_starts_with($this->mime_type, 'image/');
    }

    /**
     * An upload is orphaned when its composer session never sent. The retention
     * command sweeps these so cancelled drafts do not accumulate on disk.
     */
    public function isOrphaned(): bool
    {
        return $this->message_id === null;
    }

    public function url(): string
    {
        return resolve(\Flarum\Http\UrlGenerator::class)
            ->to('forum')
            ->path('assets/chat/'.$this->path);
    }
}
