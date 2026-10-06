import app from "flarum/admin/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";

export interface BrandingPreviewAttrs extends ComponentAttrs {
  title: string;
  icon: string;
  showIcon: boolean;
}

/**
 * How the chat's name and icon will read in the forum, drawn from the values
 * still being edited, so a typo in a Font Awesome class shows as a missing icon
 * before it is saved rather than after.
 *
 * The two places mirror the forum's own fallbacks (see forum/utils/branding):
 * an empty title reads "Chat", an empty icon reads `fas fa-comments`, and with
 * the icon off the header button carries the name instead.
 */
export class BrandingPreview extends Component<BrandingPreviewAttrs> {
  view(): Mithril.Children {
    const title = this.attrs.title.trim() || "Chat";
    const icon = this.attrs.showIcon
      ? this.attrs.icon.trim() || "fas fa-comments"
      : null;

    return (
      <figure className="ChatPreview ChatPreview--branding">
        <figcaption className="ChatPreview-caption">
          <i className="fas fa-eye" aria-hidden="true" />
          {app.translator.trans("ramon-chat.admin.layout.preview_label")}
        </figcaption>

        <div className="ChatPreview-stage">
          <div className="ChatPreview-item">
            <span className="ChatPreview-itemLabel">
              {app.translator.trans("ramon-chat.admin.layout.preview_header")}
            </span>
            <span
              className={
                "ChatPreview-headerButton" +
                (icon ? "" : " ChatPreview-headerButton--text")
              }
              title={title}
            >
              {icon ? <i className={icon} aria-hidden="true" /> : title}
              <span className="ChatPreview-badge" aria-hidden="true">
                3
              </span>
            </span>
          </div>

          <div className="ChatPreview-item ChatPreview-item--grow">
            <span className="ChatPreview-itemLabel">
              {app.translator.trans("ramon-chat.admin.layout.preview_drawer")}
            </span>
            <span className="ChatPreview-drawer">
              {icon ? <i className={icon} aria-hidden="true" /> : null}
              <span className="ChatPreview-drawerTitle">{title}</span>
              <i
                className="fas fa-chevron-down ChatPreview-drawerChevron"
                aria-hidden="true"
              />
            </span>
          </div>
        </div>
      </figure>
    );
  }
}

export interface MessagePreviewAttrs extends ComponentAttrs {
  name: string;
  avatarUrl: string | null;
  /** True when a real account announces: no bot label next to the name. */
  isUser: boolean;
}

/**
 * One announcement as it lands in a channel: the avatar, the name, the bot
 * label when the bot posts, and a sample line. The avatar is drawn at the size
 * the stream uses, so a busy picture that will not read small shows here.
 */
export class MessagePreview extends Component<MessagePreviewAttrs> {
  view(): Mithril.Children {
    const name = this.attrs.name.trim() || "Bot";

    return (
      <figure className="ChatPreview ChatPreview--message">
        <figcaption className="ChatPreview-caption">
          <i className="fas fa-eye" aria-hidden="true" />
          {app.translator.trans("ramon-chat.admin.layout.preview_label")}
        </figcaption>

        <div className="ChatPreview-stage">
          <div className="ChatPreview-message">
            <span className="ChatPreview-avatar" aria-hidden="true">
              {this.attrs.avatarUrl ? (
                <img src={this.attrs.avatarUrl} alt="" />
              ) : (
                name.charAt(0).toUpperCase()
              )}
            </span>

            <div className="ChatPreview-messageBody">
              <div className="ChatPreview-messageMeta">
                <strong>{name}</strong>
                {this.attrs.isUser ? null : (
                  <span className="ChatPreview-botTag">
                    {app.translator.trans(
                      "ramon-chat.admin.layout.preview_bot_tag",
                    )}
                  </span>
                )}
                <span className="ChatPreview-time">12:04</span>
              </div>
              <p className="ChatPreview-bubble">
                <i className="fas fa-bullhorn" aria-hidden="true" />
                {app.translator.trans(
                  "ramon-chat.admin.layout.preview_message_sample",
                )}
              </p>
            </div>
          </div>
        </div>
      </figure>
    );
  }
}
