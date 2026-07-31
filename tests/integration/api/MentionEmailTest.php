<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\LocaleManager;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\View\Factory;
use Ramon\Chat\Message;
use Ramon\Chat\Notification\ChatMentionBlueprint;

/**
 * The rendered body of the mention email.
 *
 * The template used to hand its locale string to the post formatter, which is the
 * pipeline for user content, not for our own chrome. The core Escaper printed every
 * raw tag as text, Autolink turned the bare href into a link of its own, and only
 * the `**bold**` survived — and only on forums with flarum/markdown enabled. The
 * result reached inboxes as literal `<p>` and `<a href="...">`.
 *
 * These assertions pin the shape of the output rather than the exact wording, so a
 * copy change does not break them but a regression in the mechanism does.
 */
class MentionEmailTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt for "too-obscure", the same hash `normalUser()` carries. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'Ramon', 'password' => self::PASSWORD_HASH, 'email' => 'ramon@machine.local', 'is_email_confirmed' => 1],
                // The display name is the one user-controlled value that lands in an
                // HTML context, so one account carries a hostile one.
                ['id' => 4, 'username' => '<script>alert(1)</script>', 'password' => self::PASSWORD_HASH, 'email' => 'xss@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                [
                    'id'         => 1,
                    'type'       => 'category',
                    'name'       => 'Geral',
                    'slug'       => 'geral',
                    'status'     => 'open',
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ],
            ],
            'chat_messages' => [
                $this->message(1, 3),
                $this->message(2, 4),
            ],
        ]);
    }

    private function message(int $id, int $userId): array
    {
        return [
            'id'         => $id,
            'channel_id' => 1,
            'user_id'    => $userId,
            'number'     => $id,
            'type'       => 'text',
            'content'    => '<t>oi</t>',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * Renders the HTML view the way NotificationMailer does, including the shared
     * view data the layout depends on.
     */
    private function renderHtml(int $messageId): string
    {
        $container = $this->app()->getContainer();

        // `Extend\Locales` hooks `$container->resolving(LocaleManager::class, …)`, and
        // the harness resolves the manager before extension extenders run, so the
        // callback never fires and every ramon-chat key would render as its own id.
        // Loading the catalogue by hand is what lets these assertions see real copy.
        $locales = $container->make(LocaleManager::class);
        $locales->clearCache();
        $locales->addTranslations('en', dirname(__DIR__, 3).'/locale/en.yml');

        $blueprint = new ChatMentionBlueprint(Message::query()->findOrFail($messageId));
        $user = User::query()->findOrFail(2);
        $url = $container->make(UrlGenerator::class);
        $settings = $container->make(SettingsRepositoryInterface::class);
        $translator = $container->make(TranslatorInterface::class);

        $data = [
            'blueprint'       => $blueprint,
            'user'            => $user,
            'unsubscribeLink' => $url->to('forum')->base().'/unsubscribe',
            'settingsLink'    => $url->to('forum')->base().'/settings',
            'type'            => ChatMentionBlueprint::getType(),
            'forumTitle'      => $settings->get('forum_title'),
            'username'        => $user->display_name,
            'userEmail'       => $user->email,
            'title'           => $translator->trans('core.email.notification.default_title'),
        ];

        /** @var Factory $view */
        $view = $container->make(Factory::class);
        $view->share($data);

        return $view->make('ramon-chat::emails.html.mentioned', $data)->render();
    }

    public function test_the_body_is_real_html_not_escaped_markup(): void
    {
        $html = $this->renderHtml(1);

        $this->assertStringNotContainsString('&lt;p&gt;', $html, 'paragraph tags reached the inbox as text');
        $this->assertStringNotContainsString('&lt;a href', $html, 'the anchor reached the inbox as text');
        $this->assertStringNotContainsString('**', $html, 'Markdown emphasis was never converted');
    }

    public function test_the_author_and_channel_are_emphasised(): void
    {
        $html = $this->renderHtml(1);

        $this->assertStringContainsString('<strong>Ramon</strong>', $html);
        $this->assertStringContainsString('<strong>Geral</strong>', $html);
    }

    public function test_the_call_to_action_is_a_real_link_to_the_channel(): void
    {
        $html = $this->renderHtml(1);

        $this->assertMatchesRegularExpression(
            '#<a href="[^"]*/chat/c/1">\s*Open the channel\s*</a>#',
            $html,
            'the action should be an anchor whose text is the label, not a bare autolinked URL'
        );
    }

    public function test_a_hostile_display_name_is_escaped(): void
    {
        $html = $this->renderHtml(2);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
