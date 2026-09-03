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
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ramon\Chat\Service\UploadPrivacy;

/**
 * A file attached to a chat message.
 *
 * `is_private` says which disk holds the file — see Service\UploadPrivacy. It
 * is the one thing every reader of `path` has to consult first, because the
 * same relative path exists on exactly one of the two disks.
 *
 * @property int $id
 * @property int|null $message_id
 * @property int|null $user_id
 * @property string $path
 * @property bool $is_private
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
    use ScopeVisibilityTrait;

    protected $table = 'chat_uploads';

    public $timestamps = true;


    protected $casts = [
        'message_id' => 'integer',
        'user_id'    => 'integer',
        'is_private' => 'boolean',
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

    /**
     * The name of the filesystem disk holding this file.
     */
    public function diskName(): string
    {
        return UploadPrivacy::diskFor((bool) $this->is_private);
    }

    /**
     * A public file is addressed directly, so the web server serves it. A private
     * one is addressed by id through ServeUploadController, which is the only
     * thing that can reach its disk — and which checks who is asking.
     */
    public function url(): string
    {
        $url = resolve(UrlGenerator::class);

        if ($this->is_private) {
            return $url->to('api')->route('chat.uploads.file', ['id' => $this->id]);
        }

        return $url->to('forum')->path('assets/chat/'.$this->path);
    }
}
