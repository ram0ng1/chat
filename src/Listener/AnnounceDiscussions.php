<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Post\Event\Posted;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Message;

/**
 * Posts a note in the channel when a discussion is started in its bound category.
 *
 * The counterpart to JoinChannelsOnReply: that one grows the channel's membership
 * from the category, this one carries the category's activity into the channel, so
 * a room bound to #support actually hears when someone opens a support thread.
 *
 * ## Why only the first post
 *
 * `Posted` fires for every reply. Only the discussion's opening post announces a
 * *new discussion*; without that check the channel would receive a line for every
 * message in the forum, which is the failure mode this feature is most likely to
 * have.
 *
 * ## Who it is posted as
 *
 * By default the chat's own bot: an ordinary message with no human author, drawn
 * with the name and avatar set in admin. An earlier version posted grey system
 * narration instead, which was wrong for this — a system line has nowhere to go,
 * and the whole point of an announcement is the link.
 *
 * An admin can instead nominate a real account to announce as, in which case this
 * posts a completely ordinary message from that user and no bot exists in the
 * conversation at all.
 */
class AnnounceDiscussions
{
    public function __construct(
        protected Events $events,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(Posted $event): void
    {
        $post = $event->post;

        // `number` is 1 only for the post that opened the discussion.
        if ((int) ($post->number ?? 0) !== 1) {
            return;
        }

        $discussion = $post->discussion;

        if ($discussion === null) {
            return;
        }

        $tagIds = $this->tagIdsFor($discussion);

        if ($tagIds === []) {
            return;
        }

        $channels = Channel::query()
            ->where('post_discussions', true)
            ->where('type', Channel::TYPE_CATEGORY)
            ->where('status', Channel::STATUS_OPEN)
            ->whereNull('deleted_at')
            ->whereIn('tag_id', $tagIds)
            ->get();

        foreach ($channels as $channel) {
            if (! $channel->acceptsMessages()) {
                continue;
            }

            $excerpt = $this->excerpt($post);

            $body = $this->body($discussion, $excerpt);

            // An announcer chosen in admin makes this an ordinary message from an
            // ordinary account: a real avatar, a real profile link, and no BOT tag,
            // because there is no bot involved. The tag is a property of the
            // authorless message type, not a label bolted on — so choosing a user
            // removes it by construction rather than by another setting to forget.
            $announcer = $this->announcer();

            $message = $announcer !== null
                ? Message::build($channel, $announcer, $body)
                : Message::buildBot(
                    $channel,
                    'discussion_started',
                    $body,
                    [
                        'title' => $discussion->title,
                        // The id, not a rendered URL: a URL baked in now would
                        // outlive a change of forum address.
                        'discussionId' => (int) $discussion->id,
                        'username' => $event->actor?->display_name,
                        'userId'   => $event->actor?->id !== null ? (int) $event->actor->id : null,

                        // Snapshotted rather than read live. An announcement is a
                        // record of what was posted, so editing the opening post
                        // later should not rewrite history in the channel — and
                        // reading it live would mean a query per announcement on
                        // every scroll of the stream.
                        'excerpt'  => $excerpt,
                    ]
                );

            $message->save();

            $channel->refreshMetadata()->save();

            // Broadcast and unread-count fan-out ride on the same event ordinary
            // messages use, so the announcement behaves like any other arrival.
            $this->events->dispatch(new MessageWasSent($message, $event->actor));
        }
    }

    /**
     * The account announcements are posted as, or null to use the bot.
     *
     * Resolved on every announcement rather than cached: the setting can change
     * between one discussion and the next, and a stale announcer would keep posting
     * as somebody the admin has already replaced.
     *
     * A configured id that no longer resolves — the account was deleted — falls back
     * to the bot rather than dropping the announcement. Losing the attribution is a
     * smaller failure than losing the notice entirely.
     */
    protected function announcer(): ?User
    {
        $id = (int) $this->settings->get('ramon-chat.bot_user_id');

        if ($id <= 0) {
            return null;
        }

        return User::query()->find($id);
    }

    /**
     * The prose the announcement carries.
     *
     * Markdown, so it goes through the same formatter every other message uses and
     * comes out as an ordinary message — a bold link and a paragraph, not a special
     * layout the stream has to know about. The URL is relative so it stays valid if
     * the forum moves, and the title is escaped: a discussion titled with brackets
     * and parentheses would otherwise close the link early and let the rest of the
     * title become markup.
     */
    protected function body(object $discussion, ?string $excerpt): string
    {
        $title = str_replace(['[', ']'], ['\\[', '\\]'], (string) $discussion->title);

        $body = '**['.$title.'](/d/'.(int) $discussion->id.')**';

        if ($excerpt !== null && $excerpt !== '') {
            $body .= "\n\n".$excerpt;
        }

        return $body;
    }

    /**
     * A short plain-text opening of the post, for the announcement card.
     *
     * Built from the raw content rather than the rendered HTML: formatting needs a
     * request context this listener does not have, and the card wants text anyway.
     *
     * ## Why this is more than a couple of regexes
     *
     * Whatever comes out of here is appended to a markdown body and then rendered.
     * Anything left half-stripped is not inert text — it is live markup a second
     * time around. Two shapes proved that:
     *
     *  - `[![CI](shield.svg)](target)`, an image inside a link. A single pass with
     *    `[^\]]*` cannot reach past the inner `]`, so it consumed the first half
     *    and left `![CI](target)` behind — which the formatter then rendered as an
     *    image. Collapsing has to repeat until the text stops changing.
     *  - A post written in HTML. Markdown stripping never touched `<img src=…>`,
     *    so the tag survived into the announcement and drew a picture there.
     *
     * So: strip, repeat, and then neutralise what remains rather than trusting the
     * strip to have been complete.
     */
    protected function excerpt(object $post): ?string
    {
        $content = trim((string) ($post->content ?? ''));

        if ($content === '') {
            return null;
        }

        // Quotes first, and while they are still line-shaped. Stripping the
        // leading ">" as punctuation later would leave the quoted words behind and
        // pass off someone else's sentence as this post's opening.
        $content = preg_replace('/\[quote[^\]]*\].*?\[\/quote\]/is', ' ', $content) ?? $content;
        $content = preg_replace('/<blockquote.*?<\/blockquote>/is', ' ', $content) ?? $content;
        $content = preg_replace('/^[ 	]*>[^
]*$/m', ' ', $content) ?? $content;

        // Fenced and inline code: the contents are rarely a useful opening line and
        // frequently contain the very characters this is trying to remove.
        $content = preg_replace('/```.*?```/s', ' ', $content) ?? $content;
        $content = preg_replace('/`[^`]*`/', ' ', $content) ?? $content;

        // Links and images, innermost first, until nothing changes. Bounded so a
        // pathological post cannot spin here.
        for ($pass = 0; $pass < 6; $pass++) {
            $collapsed = preg_replace('/!?\[([^\[\]]*)\]\([^)\s]*(?:\s+[^)]*)?\)/', '$1', $content) ?? $content;

            if ($collapsed === $content) {
                break;
            }

            $content = $collapsed;
        }

        // HTML and BBCode. A post is source text, not rendered output, so both can
        // be present verbatim — and an `<img>` that survives here is drawn in the
        // channel.
        $content = preg_replace('/<[^>]*>/', ' ', $content) ?? $content;
        $content = preg_replace('/\[\/?[a-z*][^\]]*\]/i', ' ', $content) ?? $content;

        // Emphasis, headings, list bullets and horizontal rules.
        $content = preg_replace('/^[ 	]*(?:[-*+]|\d+\.)\s+/m', ' ', $content) ?? $content;
        $content = preg_replace('/[`*_~]+/', '', $content) ?? $content;
        $content = preg_replace('/^\s*#+\s*/m', ' ', $content) ?? $content;

        // What is left is meant to be text. These are the characters that could
        // still start markup once this is appended to the message body, and by now
        // none of them belongs to anything.
        $content = str_replace(['[', ']', '<', '>', '`'], '', $content);

        $content = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);

        if ($content === '') {
            return null;
        }

        return mb_strlen($content) > 180
            ? mb_substr($content, 0, 180).'…'
            : $content;
    }

    /**
     * @see JoinChannelsOnReply::tagIdsFor() — same reasoning, including why
     * `method_exists` cannot be used on the dynamically registered relation.
     *
     * @return int[]
     */
    protected function tagIdsFor(object $discussion): array
    {
        try {
            $tags = $discussion->tags;
        } catch (\Throwable $e) {
            return [];
        }

        if ($tags === null) {
            return [];
        }

        $ids = [];

        foreach ($tags as $tag) {
            $ids[] = (int) $tag->id;

            if ($tag->parent_id !== null) {
                $ids[] = (int) $tag->parent_id;
            }
        }

        return array_values(array_unique($ids));
    }
}
