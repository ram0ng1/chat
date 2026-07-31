import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Icon from "flarum/common/components/Icon";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import ChatDrawer from "./ChatDrawer";
import chatState from "../state/chat";
import { chatTitle, chatIcon } from "../utils/branding";
import { shouldUseChatDrawer } from "../utils/surface";

/**
 * The chat entry point in the header — Discourse's speech bubble next to search.
 *
 * The markup is a copy of core's `HeaderDropdown.getButtonContent()`, which is what
 * `flarum/messages` renders through `DialogsDropdown`: icon, then bubble, then
 * label, on a `.Button.Button--flat`. Matching it exactly is what makes the control
 * behave in both places it is drawn — as a round icon in the desktop header, and as
 * a named row inside the drawer on a phone — without a parallel set of rules.
 *
 * It is a plain `<button>` rather than a `HeaderDropdown` subclass because there is
 * no menu: the chat opens its own drawer or its page. Subclassing would drag in
 * `Dropdown-menu` markup and a toggle state that nothing here uses.
 */
export default class ChatNavButton<
  CustomAttrs extends ComponentAttrs = ComponentAttrs,
> extends Component<CustomAttrs> {
  view(): Mithril.Children {
    const mentions = this.unreadMentions();
    const unread = this.unreadChannels();
    const icon = chatIcon();
    const label = chatTitle();

    // A count, the way core's notification bell shows one — not a dot. A dot says
    // "something happened" and leaves you to open the chat to find out how much,
    // which is the one question the header is there to answer.
    //
    // Mentions take precedence over ambient channel traffic, and the `new` class
    // below is what distinguishes them: core repaints `.HeaderDropdownBubble`
    // with the header colour under `.new`. That is the same signal the bell uses
    // for an unread notification, so the two read alike.
    const count = mentions > 0 ? mentions : unread;
    const digits = count > 99 ? "99+" : String(count);

    return (
      <button
        type="button"
        className={classList("Button Button--flat ChatNavButton", {
          // Core styles `.new` on a header button to signal fresh activity.
          new: mentions > 0,
          hasIcon: icon !== null,
        })}
        title={label}
        aria-label={this.ariaLabel(mentions, unread > 0, label)}
        onclick={() => this.open()}
      >
        {icon ? <Icon name={icon} className="Button-icon" /> : null}

        {/* `HeaderDropdownBubble` alongside `Bubble`, exactly as core emits it.
            The first supplies the colours and — critically — the phone offset;
            the second the size, radius and absolute placement. Positioning it
            ourselves was the bug behind the grey slab in the drawer: core anchors
            the bubble with `left: 18px`, our override added `right: 2px` without
            clearing `left`, and an absolutely positioned box given both stretches
            to fill the distance between them. On a 36px header button that is
            invisible; on a full-width drawer row it is the whole row. */}
        {count > 0 ? (
          <span
            className="Bubble HeaderDropdownBubble"
            data-digits={digits.length}
            aria-hidden="true"
          >
            {digits}
          </span>
        ) : null}

        {/* Always emitted, never blanked when there is an icon — core's
            getButtonContent() does the same. On a phone the header moves into the
            drawer and its controls are laid out as named rows; a button that
            renders no text there is an anonymous icon sitting between "Flagged
            Posts" and "Messages". Hiding the label is the stylesheet's job, and it
            only does so from @tablet-up. */}
        <span className="Button-label">
          <span className="Button-labelText">{label}</span>
        </span>
      </button>
    );
  }

  /**
   * Opens the drawer or the full-screen page, following the user's preference.
   *
   * Nothing closes Flarum's drawer on the way out: `ChatPage` extends core's
   * `Page`, whose `oninit` calls `app.drawer.hide()`. Doing it again here would
   * start the hide animation twice.
   */
  open(): void {
    if (shouldUseChatDrawer()) {
      ChatDrawer.open();

      return;
    }

    m.route.set(app.route("chat.index"));
  }

  /**
   * Read through ChatState rather than off the user record directly.
   *
   * The serialised attribute is a snapshot from page render; the summary prefers
   * the loaded channel list when there is one and falls back to that attribute
   * otherwise — and realtime keeps the attribute moving. Reading the attribute
   * here meant the dot only ever appeared after a reload, which is precisely when
   * it is least useful.
   */
  protected unreadChannels(): number {
    return chatState.unreadSummary().messages;
  }

  protected unreadMentions(): number {
    return chatState.unreadSummary().mentions;
  }

  /**
   * The bubble is aria-hidden, so this is the only thing a screen reader gets —
   * and it has to say which kind of unread it is, because the two carry different
   * urgency and the colour that distinguishes them is not announced.
   */
  protected ariaLabel(
    mentions: number,
    hasUnread: boolean,
    label: string,
  ): string {
    if (mentions > 0) {
      return app.translator.trans(
        "ramon-chat.forum.nav.unread_mentions",
        { count: mentions },
        true,
      );
    }

    if (hasUnread) {
      return app.translator.trans(
        "ramon-chat.forum.nav.unread_messages",
        { count: this.unreadChannels() },
        true,
      );
    }

    return label;
  }
}
