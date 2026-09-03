<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\ConnectionInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Event\ThreadWasCreated;
use Ramon\Chat\Mention\MentionResolver;
use Ramon\Chat\Message;
use Ramon\Chat\Thread;
use Ramon\Chat\ThreadUser;
use Ramon\Chat\Upload;

/**
 * The single path through which user-authored messages enter a channel.
 *
 * Centralising this keeps the invariants in one place: validation, rate
 * limiting, mention resolution, thread bookkeeping, attachment binding, counter
 * maintenance and unread fan-out all have to happen together or not at all.
 */
class MessageDispatcher
{
    public function __construct(
        protected ConnectionInterface $db,
        protected ExtensionManager $extensions,
        protected Events $events,
        protected SettingsRepositoryInterface $settings,
        protected Translator $translator,
        protected MentionResolver $mentions,
        protected RateLimiter $rateLimiter,
        protected SlowMode $slowMode,
        protected UnreadTracker $unread,
        protected UploadPrivacy $uploadPrivacy
    ) {
    }

    /**
     * @param  int[]  $uploadIds  Ids of previously uploaded, not-yet-attached files.
     *
     * @throws ValidationException
     */
    public function send(
        Channel $channel,
        User $actor,
        string $content,
        ?Thread $thread = null,
        ?Message $replyTo = null,
        array $uploadIds = [],
        bool $createThread = false
    ): Message {
        $content = trim($content);

        $this->assertValidContent($channel, $content, $uploadIds);
        $this->assertMaySendStickers($actor, $content);
        $this->rateLimiter->assertWithinLimit($actor);

        // After the content checks, before anything is written. A message that
        // fails validation must not cost the sender their turn — being told
        // "too short" and then "wait 30 seconds" for the same keystroke is the
        // kind of thing that makes people give up on a channel.
        $this->slowMode->assertMayPost($channel, $actor);

        // A reply that starts a thread must anchor to a message in this channel;
        // otherwise the thread would span channels and break the (channel,
        // number) ordering contract.
        if ($replyTo !== null && $replyTo->channel_id !== $channel->id) {
            throw new ValidationException([
                'replyTo' => $this->translator->trans('ramon-chat.api.invalid_reply_target'),
            ]);
        }

        if ($thread !== null && $thread->channel_id !== $channel->id) {
            throw new ValidationException([
                'thread' => $this->translator->trans('ramon-chat.api.invalid_thread_target'),
            ]);
        }

        $threadWasCreated = false;

        $message = $this->db->transaction(function () use (
            $channel, $actor, $content, &$thread, $replyTo, $uploadIds, $createThread, &$threadWasCreated
        ) {
            // Starting a thread from a message that has none yet: create the
            // thread, then back-fill the root message's thread_id so the thread
            // reads as a single query.
            if ($createThread && $thread === null && $replyTo !== null) {
                if ($replyTo->thread_id !== null) {
                    $thread = $replyTo->thread;
                } else {
                    $thread = Thread::build($channel, $replyTo, $actor);
                    $thread->save();

                    $replyTo->thread_id = $thread->id;
                    $replyTo->save();

                    $this->trackThread($thread, $replyTo->user_id !== null ? $replyTo->user : $actor);

                    $threadWasCreated = true;
                }
            }

            $message = Message::build($channel, $actor, $content, $thread, $replyTo);
            $message->save();

            $this->attachUploads($channel, $message, $actor, $uploadIds);

            $this->mentions->sync(
                $message,
                $actor->can('mentionChannelWide', $channel)
            );

            // Reload relations the downstream listeners and serialisers rely on.
            $message->setRelation('channel', $channel);
            $message->setRelation('user', $actor);

            if ($thread !== null) {
                $message->setRelation('thread', $thread);

                $thread->noteReply($message)->save();
                $this->trackThread($thread, $actor);
            }

            $channel->last_message_id = $message->id;
            $channel->last_message_at = $message->created_at;
            $channel->messages_count++;
            $channel->save();

            // The sender is implicitly caught up; everyone else gains an unread.
            $this->unread->recordNewMessage($message);

            return $message;
        });

        // Events fire *after* commit, never inside the transaction.
        //
        // Listeners have side effects the database cannot roll back: the realtime
        // broadcast puts the message on subscribers' websockets, and notifications
        // may send mail. Dispatching inside the transaction meant a client could
        // receive the push — and act on it — before the row was committed, and a
        // late rollback would leave a message that was announced but never existed.
        if ($threadWasCreated && $thread !== null) {
            $this->events->dispatch(new ThreadWasCreated($thread, $actor));
        }

        // Started only once the message is actually in. Marking the cooldown
        // before the transaction would penalise a send that then failed.
        $this->slowMode->noteSent($channel, $actor);

        $this->events->dispatch(new MessageWasSent($message, $actor));

        return $message;
    }

    /**
     * Posts a system message (joins, status changes). System messages bypass
     * rate limiting and mention resolution but still advance channel counters so
     * they order correctly in the stream.
     */
    public function sendSystem(Channel $channel, string $key, array $data = []): Message
    {
        $message = $this->db->transaction(function () use ($channel, $key, $data) {
            $message = Message::buildSystem($channel, $key, $data);
            $message->save();

            $message->setRelation('channel', $channel);

            $channel->last_message_id = $message->id;
            $channel->last_message_at = $message->created_at;
            $channel->messages_count++;
            $channel->save();

            return $message;
        });

        // After commit, as above.
        $this->events->dispatch(new MessageWasSent($message, null));

        return $message;
    }

    /**
     * Binds pending uploads to the message. Only the sender's own unattached
     * uploads are eligible, which prevents claiming someone else's file by id.
     *
     * This is also the gate on where the file lives. The composer names the
     * channel when it uploads, so the file normally starts on the right disk —
     * but the upload endpoint takes the client's word for the destination, and
     * this is where the destination is known for certain. A public file bound
     * to a private channel is moved here, inside the same transaction as the
     * message, so a move that fails is a send that fails rather than a picture
     * left published.
     *
     * @param  int[]  $uploadIds
     */
    protected function attachUploads(Channel $channel, Message $message, User $actor, array $uploadIds): void
    {
        $uploadIds = array_values(array_filter(array_map('intval', $uploadIds)));

        if ($uploadIds === []) {
            return;
        }

        Upload::query()
            ->whereIn('id', $uploadIds)
            ->where('user_id', $actor->id)
            ->whereNull('message_id')
            ->update([
                'message_id' => $message->id,
                'updated_at' => Carbon::now(),
            ]);

        if (! UploadPrivacy::requiredFor($channel)) {
            return;
        }

        $public = Upload::query()
            ->whereKey($uploadIds)
            ->where('message_id', $message->id)
            ->where('is_private', false)
            ->get();

        foreach ($public as $upload) {
            $this->uploadPrivacy->privatize($upload);
        }
    }

    /**
     * Replying to a thread opts you into it at "always", mirroring Discourse's
     * thread tracking. An existing membership is left untouched so a user who
     * deliberately lowered their level is not re-escalated by replying.
     */
    protected function trackThread(Thread $thread, ?User $user): void
    {
        if ($user === null || ! $user->exists) {
            return;
        }

        // One statement rather than a select followed by an insert. The unique
        // index on (thread_id, user_id) is what enforces "leave an existing
        // membership alone" — and it enforces it better than the read did, since a
        // read-then-write races with a second reply from the same user and can
        // still hit the constraint. Ignoring the duplicate is the intended outcome
        // either way.
        $now = Carbon::now();

        ThreadUser::query()->insertOrIgnore([
            'thread_id'          => $thread->id,
            'user_id'            => $user->id,
            'notification_level' => ThreadUser::LEVEL_ALWAYS,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    /**
     * Refuses a message carrying a sticker shortcode from someone not allowed to
     * send one.
     *
     * The composer hides its button for these people, but a hidden button is a
     * courtesy — the shortcode is plain text and anyone can type it, or post it
     * straight to the API. Without this the permission would decorate the
     * interface and grant nothing.
     *
     * Only shortcodes that match a real sticker count. Someone writing `:-)` or
     * quoting `:not_a_sticker:` is writing text, and refusing that would make the
     * permission feel arbitrary.
     *
     * Skipped entirely when ramon/stickers is absent: there is nothing to send, so
     * there is nothing to gate, and an install without it should not have messages
     * refused for mentioning a colon.
     *
     * @throws ValidationException
     */
    protected function assertMaySendStickers(User $actor, string $content): void
    {
        if ($content === '' || $actor->hasPermission('ramon-chat.sendStickers')) {
            return;
        }

        // Asked of the extension manager rather than by probing for a `stickers`
        // table. `ConnectionInterface` does not declare `getSchemaBuilder()` — it
        // lives on the concrete Connection — so that probe would fatal on any
        // driver implementing only the interface. It was also the wrong question:
        // what matters is whether the extension is enabled, not whether a table
        // happens to be left over from an uninstall.
        if (! $this->extensions->isEnabled('ramon-stickers')) {
            return;
        }

        preg_match_all('/:[\w+-]+:/', $content, $matches);

        if ($matches[0] === []) {
            return;
        }

        $used = $this->db->table('stickers')
            ->whereIn('text_to_replace', array_unique($matches[0]))
            ->exists();

        if (! $used) {
            return;
        }

        throw new ValidationException([
            'content' => $this->translator->trans('ramon-chat.api.stickers_not_allowed'),
        ]);
    }

    /**
     * @param  int[]  $uploadIds
     *
     * @throws ValidationException
     */
    protected function assertValidContent(Channel $channel, string $content, array $uploadIds): void
    {
        $min = (int) $this->settings->get('ramon-chat.min_message_length', 1);

        // The channel's own cap when it has one, the forum's otherwise. Read
        // here rather than passed in, so every path into `send()` is held to the
        // same limit as the composer shows.
        $max = $channel->maxMessageLength(
            (int) $this->settings->get('ramon-chat.max_message_length', 3000)
        );

        // An attachment-only message is legitimate, so the minimum applies to
        // the text only when there is nothing else being sent.
        if ($content === '' && $uploadIds === []) {
            throw new ValidationException([
                'content' => $this->translator->trans('ramon-chat.api.message_empty'),
            ]);
        }

        $length = mb_strlen($content);

        if ($content !== '' && $length < $min) {
            throw new ValidationException([
                'content' => $this->translator->trans('ramon-chat.api.message_too_short', ['min' => $min]),
            ]);
        }

        if ($length > $max) {
            throw new ValidationException([
                'content' => $this->translator->trans('ramon-chat.api.message_too_long', ['max' => $max]),
            ]);
        }
    }
}
