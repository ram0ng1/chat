<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit;

use Illuminate\Database\Eloquent\Collection;
use Ramon\Chat\Message;
use Ramon\Chat\MessageRevision;

/**
 * Regression for the post-quote crash.
 *
 * fof/move-posts registers render and unparse callbacks that do
 * `$context->mentionsPosts->find($id)` on whatever model the formatter hands
 * them. A chat message with a pasted `@"User"#p123` quote made that a fatal
 * `find()` on null, on every load of the channel page that contained it and on
 * the send request itself. These exercise exactly what those callbacks touch.
 */
class ResolvesMentionedPostsTest extends QueryTestCase
{
    private const QUOTE = '<r><POSTMENTION discussionid="7" displayname="Some User" id="123" number="4">@"Some User"#p123</POSTMENTION> agreed</r>';

    protected function message(?string $parsed): Message
    {
        $message = new Message();
        $message->setRawAttributes([
            'id'         => 1,
            'channel_id' => 1,
            'type'       => Message::TYPE_TEXT,
            'content'    => $parsed,
        ], true);

        return $message;
    }

    public function test_mentions_posts_is_an_empty_collection_when_nothing_is_mentioned(): void
    {
        $posts = $this->message('<t>hello</t>')->mentionsPosts;

        $this->assertInstanceOf(Collection::class, $posts);
        $this->assertTrue($posts->isEmpty());
        $this->assertNull($posts->find(123), 'the exact call fof/move-posts makes must not throw');
    }

    public function test_mentions_posts_survives_empty_content(): void
    {
        $this->assertTrue($this->message(null)->mentionsPosts->isEmpty());
        $this->assertTrue($this->message('')->mentionsPosts->isEmpty());
    }

    public function test_mentioned_post_ids_are_read_from_the_postmention_tags(): void
    {
        $xml = '<r><POSTMENTION id="123">a</POSTMENTION> <POSTMENTION id="9">b</POSTMENTION> <POSTMENTION id="123">c</POSTMENTION></r>';

        $this->assertSame([123, 9], $this->message($xml)->mentionedPostIds());
    }

    public function test_mentioned_post_ids_ignores_user_mentions(): void
    {
        $xml = '<r><USERMENTION id="5">@user</USERMENTION> hi</r>';

        $this->assertSame([], $this->message($xml)->mentionedPostIds());
    }

    public function test_the_fallback_is_escaped_plain_text_built_without_the_formatter(): void
    {
        $html = $this->message(self::QUOTE)->fallbackContentHtml();

        $this->assertSame('@&quot;Some User&quot;#p123 agreed', $html);
    }

    public function test_the_fallback_escapes_markup_and_keeps_line_breaks(): void
    {
        $html = $this->message("<t>&lt;b&gt;x&lt;/b&gt;<br/>\ny</t>")->fallbackContentHtml();

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringContainsString('<br />', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function test_the_fallback_is_empty_for_empty_content(): void
    {
        $this->assertSame('', $this->message(null)->fallbackContentHtml());
    }

    public function test_revisions_carry_the_same_shim(): void
    {
        $revision = new MessageRevision();
        $revision->setRawAttributes(['id' => 1, 'message_id' => 1, 'content' => '<t>old</t>'], true);

        $this->assertTrue($revision->mentionsPosts->isEmpty());
        $this->assertSame('old', $revision->fallbackContentHtml());
    }
}
