import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import ChatDrawer from './ChatDrawer';
import { chatTitle, chatIcon } from '../utils/branding';

/**
 * The chat entry point in the header — Discourse's speech bubble next to search.
 *
 * Renders a bare `<button>` carrying core's own header-button classes rather than
 * wrapping a `Button` in a positioned `<div>`. The header lays its controls out
 * with flex and targets `.Button` directly; an extra wrapper element gets stretched
 * and the icon ends up squashed into an oval.
 *
 * The unread badge uses core's `.Bubble` for the same reason: it is the markup
 * NotificationsDropdown emits, so it inherits the right size, offset and contrast
 * instead of needing a parallel set of rules to keep in sync.
 */
export default class ChatNavButton<
  CustomAttrs extends ComponentAttrs = ComponentAttrs
> extends Component<CustomAttrs> {
  view(): Mithril.Children {
    const mentions = this.unreadMentions();
    const unread = this.unreadChannels();
    const icon = chatIcon();
    const label = chatTitle();

    return (
      <button
        type="button"
        className={classList('Button Button--flat ChatNavButton', {
          // Core styles `.new` on a header button to signal fresh activity.
          new: mentions > 0,
          hasIcon: icon !== null,
        })}
        title={label}
        aria-label={this.ariaLabel(mentions, unread > 0, label)}
        onclick={() => this.open()}
      >
        {icon ? <Icon name={icon} className="Button-icon" /> : null}

        {mentions > 0 ? (
          <span className="Bubble" data-digits={String(mentions).length} aria-hidden="true">
            {mentions > 99 ? '99+' : mentions}
          </span>
        ) : unread > 0 ? (
          // Ambient unreads get a dot, not a number: a count for ordinary channel
          // traffic is noise in a busy chat.
          <span className="ChatNavButton-dot" aria-hidden="true" />
        ) : null}

        <span className="Button-label">
          <span className="Button-labelText">{icon ? '' : label}</span>
        </span>
      </button>
    );
  }

  /**
   * Opens the drawer or the full-screen page, following the user's preference.
   *
   * On narrow viewports the drawer goes full-bleed anyway, so the page is used
   * instead — a "drawer" that covers the whole screen but keeps drawer chrome is
   * worse than the page it is imitating.
   */
  open(): void {
    const preferDrawer = app.session.user?.preferences()?.['ramon-chat.openInDrawer'] !== false;

    if (preferDrawer && window.innerWidth > 767) {
      ChatDrawer.open();

      return;
    }

    m.route.set(app.route('chat.index'));
  }

  protected unreadChannels(): number {
    return Number(app.session.user?.attribute<number>('chatUnreadChannelsCount') ?? 0);
  }

  protected unreadMentions(): number {
    return Number(app.session.user?.attribute<number>('chatUnreadMentionsCount') ?? 0);
  }

  protected ariaLabel(mentions: number, hasUnread: boolean, label: string): string {
    if (mentions > 0) {
      return app.translator.trans('ramon-chat.forum.nav.unread_channels', { count: mentions }, true);
    }

    if (hasUnread) {
      return app.translator.trans('ramon-chat.forum.nav.unread_channels', { count: this.unreadChannels() }, true);
    }

    return label;
  }
}
