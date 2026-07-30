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
use Flarum\Formatter\Formattable;
use Flarum\Formatter\HasFormattedContent;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One prior state of a message's content, written immediately before an edit.
 *
 * @property int $id
 * @property int $message_id
 * @property string|null $content
 * @property string|null $parsed_content
 * @property int|null $edited_by_id
 * @property Carbon|null $created_at
 * @property-read Message|null $message
 * @property-read User|null $editedBy
 */
class MessageRevision extends AbstractModel implements Formattable
{
    use HasFormattedContent;

    protected $table = 'chat_message_revisions';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'message_id'   => 'integer',
        'edited_by_id' => 'integer',
        'created_at'   => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_id');
    }
}
