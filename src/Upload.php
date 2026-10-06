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

/**
 * A file attached to a chat message.
 *
 * Two columns say where the file is, and they answer different questions.
 * `storage` is where the bytes are: `local`, the chat's own disks, or `fof`,
 * whatever fof/upload is configured with (see Storage\UploadStorage).
 * `is_private` is how the file is served: straight off its public location, or
 * only through ServeUploadController after a visibility check — see
 * Service\UploadPrivacy for the rule.
 *
 * A local file's `is_private` also picks which of the two local disks holds it.
 * A file on fof/upload is public by construction, so `fof` with `is_private`
 * set is only ever transient: the moment between flagging a file private and
 * pulling its bytes back onto the private disk, during which it is served
 * through the controller and its public URL is no longer handed out.
 *
 * @property int $id
 * @property int|null $message_id
 * @property int|null $user_id
 * @property string $path
 * @property bool $is_private
 * @property string $storage
 * @property string|null $storage_adapter
 * @property string|null $remote_url
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

    public const STORAGE_LOCAL = 'local';
    public const STORAGE_FOF = 'fof';

    protected $table = 'chat_uploads';

    /**
     * Handed over by ChatServiceProvider; see Channel::$extensions for why a
     * static is safe here.
     */
    protected static ?UrlGenerator $url = null;

    public static function setUrlGenerator(UrlGenerator $url): void
    {
        static::$url = $url;
    }

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
     * Whether the bytes are somewhere other than the chat's own disks.
     */
    public function isRemote(): bool
    {
        return $this->storage === self::STORAGE_FOF;
    }

    /**
     * A public file is addressed directly, so the web server or the bucket
     * serves it. A private one is addressed by id through ServeUploadController,
     * which is the only thing that can reach its disk — and which checks who is
     * asking. Privacy is read first, so a remote file on its way to the private
     * disk is never handed out by its public URL.
     */
    public function url(): string
    {
        $url = static::$url;

        if ($this->is_private) {
            return $url->to('api')->route('chat.uploads.file', ['id' => $this->id]);
        }

        if ($this->isRemote() && $this->remote_url) {
            return $this->remote_url;
        }

        return $url->to('forum')->path('assets/chat/'.$this->path);
    }
}
