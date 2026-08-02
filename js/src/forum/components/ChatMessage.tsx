import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import username from "flarum/common/helpers/username";
import humanTime from "flarum/common/helpers/humanTime";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Message from "../../common/models/Message";
import type ChatState from "../state/ChatState";
import { displayEmoji } from "../utils/emoji";
import { isOnline } from "../utils/presence";
import { authorAvatar, authorLink } from "../utils/bot";
import { safeFileUrl } from "../utils/url";
import { verifiedBadge } from "../utils/integrations";
import RevisionsModal from "./RevisionsModal";
import FlagMessageModal from "./FlagMessageModal";
import ImageLightbox from "./ImageLightbox";
import { messagePreview } from "../../common/utils/preview";
import { refreshMessageCapabilities } from "../realtime";

export interface ChatMessageAttrs extends ComponentAttrs {
  message: Message;
  /** The row above, used to decide grouping. */
  previous?: Message | null;
  state: ChatState;
  onReply?: (message: Message) => void;
  onEdit?: (message: Message) => void;
  onOpenThread?: (message: Message) => void;
  /**
   * Suppresses the "N replies" strip. Set inside the thread panel itself, where
   * the root message would otherwise offer to open the thread you are already
   * reading — and, with no handler wired, do nothing when clicked.
   */
  hideThreadIndicator?: boolean;
}

/**
 * The one-click reaction on the hover bar.
 *
 * `+1` (👍) rather than a heart, so the quick reaction means the same thing here
 * as a Like does on a post — Flarum's own convention. Any other emoji is still
 * reachable through the picker, and hearts already stored on old messages keep
 * rendering as hearts.
 */
const LIKE_REACTION = "+1";

/**
 * One row in the message stream.
 *
 * Grouping, mention highlighting and tombstones are all decided from the model
 * rather than passed in, so a row rendered from a realtime push looks identical
 * to one rendered from a fetch.
 */
export default class ChatMessage extends Component<ChatMessageAttrs> {
  view(): Mithril.Children {
    const { message, previous, state } = this.attrs;

    // A pinned message never collapses into the run above it: grouping hides the
    // meta row, which is where the pin is stated.
    const grouped = message.isGroupedWith(previous) && !message.isPinned();
    const deleted = Boolean(message.isDeleted());
    const selected = state.selected.has(Number(message.id()));

    if (message.isSystem()) {
      return this.systemRow(message);
    }

    return (
      <div
        className={classList("ChatMessage", {
          "ChatMessage--grouped": grouped,
          "ChatMessage--pinned": !deleted && Boolean(message.isPinned()),
          "ChatMessage--mentioned": !deleted && message.mentionsActor(),
          "ChatMessage--selected": selected,
          // A bot post that stands for something elsewhere — currently only a new
          // discussion. The row stays an ordinary message: same avatar, same
          // timestamp, same reactions. Only its content block is drawn as a quoted
          // card, which is what distinguishes "here is a thing over there" from
          // somebody talking.
          "ChatMessage--announcement":
            !deleted && message.isBot() && Boolean(message.systemKey()),
          // Only meaningful on an ungrouped row, which is the one showing an
          // avatar for the halo to sit on.
          "ChatMessage--online": !grouped && isOnline(message.user() || null),
        })}
        data-id={message.id()}
        onclick={
          state.selecting
            ? () => state.toggleSelected(Number(message.id()))
            : undefined
        }
      >
        <div className="ChatMessage-gutter">
          {grouped ? this.shortTime(message) : authorAvatar(message)}
        </div>

        <div className="ChatMessage-body">
          {grouped ? null : (
            <div className="ChatMessage-meta">
              <span className="ChatMessage-author">{authorLink(message)}</span>

              {/* ramon/verified, when installed. Placed where that extension puts
                  it on a post — right after the name — so a verified member is
                  marked the same way wherever they are talking. */}
              {message.isBot() ? null : verifiedBadge(message.user())}

              {/* Stated, not implied. A message with a name and an avatar reads as a
                  person by default, and the one thing a reader needs to know here is
                  that nobody typed it — so the badge is part of the identity rather
                  than a decoration on top of it. */}
              {message.isBot() ? (
                <span className="ChatMessage-botTag">
                  {app.translator.trans("ramon-chat.forum.bot.tag")}
                </span>
              ) : null}
              {message.createdAt() ? (
                <span className="ChatMessage-time">
                  {humanTime(message.createdAt()!)}
                </span>
              ) : null}
              {/* A pin is channel-wide, so it is stated on the row itself and not
                  only in the pinned list — otherwise nobody scrolling past would
                  know why the message is highlighted. */}
              {!deleted && message.isPinned() ? (
                <span
                  className="ChatMessage-pinMark"
                  title={String(message.pinnedAt() ?? "")}
                >
                  <i className="fas fa-thumbtack" aria-hidden="true" />
                  {app.translator.trans("ramon-chat.forum.message.pinned")}
                </span>
              ) : null}
            </div>
          )}

          {this.replyPreview(message)}
          {deleted ? this.tombstone(message) : this.content(message)}
          {/* Attachments before reactions. A reaction is *about* the message, so
              it belongs after everything the message is made of — and an
              image-only message put the pill above a 300px picture, which read as
              a badge on the row rather than a response to the picture nobody had
              scrolled to yet. Stickers render through the same block. */}
          {deleted ? null : this.uploads(message)}
          {deleted ? null : this.reactions(message)}
          {deleted ? null : this.threadIndicator(message)}
        </div>

        {state.selecting ? null : this.actions(message)}
      </div>
    );
  }

  /**
   * Deleted rows keep their place in the stream. Removing them would silently
   * reflow the conversation and make replies to them incoherent.
   */
  protected tombstone(message: Message): Mithril.Children {
    // Decided by who removed it, never by whether the text was withheld. Those
    // are different questions: a message its author deleted is withheld from
    // everyone else as well, so keying off redaction announced every ordinary
    // self-deletion to the channel as a moderator removal.
    if (!message.isModeratorDeleted()) {
      return (
        <div className="ChatMessage-tombstone">
          <span>
            {app.translator.trans("ramon-chat.forum.message.deleted")}
          </span>

          {this.purgeButton(message)}
        </div>
      );
    }

    // `hasOne` yields false when the relationship was not included — a realtime
    // push carries no relations, so the unnamed wording is the fallback rather
    // than a separate case.
    const moderator = message.deletedBy() || null;

    // Naming them is the point: "removed by a moderator" leaves the author with
    // no idea who to ask.
    return (
      <div className="ChatMessage-tombstone">
        <span>
          {moderator
            ? app.translator.trans(
                "ramon-chat.forum.message.deleted_by_named",
                {
                  username: username(moderator),
                },
              )
            : app.translator.trans(
                "ramon-chat.forum.message.deleted_by_moderator",
              )}
        </span>

        {this.purgeButton(message)}
      </div>
    );
  }

  /**
   * The picture an announced discussion opened with, drawn at the head of the
   * card the way a link preview leads with a favicon.
   *
   * Only for bot announcements: an ordinary message showing its own attachments
   * already has the uploads row, and a second copy of the same image at the top
   * would be noise.
   *
   * The URL is filtered again here even though the server already accepted only
   * http(s). It lands in an `src`, the payload travels through JSON:API to get
   * here, and "the server checked" is a property of today's server.
   */
  protected announcementIcon(message: Message): Mithril.Children {
    if (!message.isBot() || !message.systemKey()) return null;

    const data = message.systemData() as { image?: string } | null;
    const url = safeFileUrl(data?.image);

    if (!url) return null;

    return (
      <img
        className="ChatMessage-announcementIcon"
        src={url}
        alt=""
        loading="lazy"
        // A picture that fails to load must not leave a broken-image glyph in
        // the middle of the card; removing the node is cleaner than styling it.
        onerror={(e: Event) => (e.target as HTMLElement)?.remove()}
      />
    );
  }

  /**
   * Removes the row for good.
   *
   * Offered on the tombstone rather than in the hover bar: a channel that has
   * collected tombstones is where this is wanted, and putting an irreversible
   * action next to "reply" and "react" is asking for a mis-click.
   *
   * The confirmation names what is lost, because "are you sure?" does not.
   */
  protected purgeButton(message: Message): Mithril.Children {
    if (!message.canForceDelete()) return null;

    return (
      <Button
        className="Button Button--flat Button--icon ChatMessage-purge"
        icon="fas fa-eraser"
        title={app.translator.trans("ramon-chat.forum.message.purge", {}, true)}
        onclick={() => this.purge(message)}
      />
    );
  }

  protected async purge(message: Message): Promise<void> {
    if (
      !confirm(
        app.translator.trans(
          "ramon-chat.forum.message.purge_confirm",
          {},
          true,
        ),
      )
    ) {
      return;
    }

    try {
      await app.request({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-messages/${message.id()}/purge`,
      });

      this.attrs.state.removeMessage(
        Number(message.channelId()),
        Number(message.id()),
      );
    } catch {
      app.alerts.show(
        { type: "error" },
        app.translator.trans("ramon-chat.forum.message.purge_failed"),
      );
    } finally {
      m.redraw();
    }
  }

  protected content(message: Message): Mithril.Children {
    const html = message.contentHtml();

    return (
      <div className="ChatMessage-content">
        {this.announcementIcon(message)}
        {/* `contentHtml` is the server's own render: Message uses Flarum's
            `HasFormattedContent`, so this string came out of the s9e/TextFormatter
            pipeline that also produces post bodies. Trusting it here is the same
            move core makes to render a post, and the alternative — re-parsing
            formatter output into a vnode tree — would have to reimplement the
            renderer to gain nothing. The raw `content` is never trusted; it is
            rendered as a text node on the branch below. */}
        {/* nosemgrep: github.semgrep.flarum-v2-m-trust */}
        {html ? m.trust(html) : message.content()}
        {/* The "edited" marker is the affordance for the history — the same place
            core puts it on a post. A button, not a span, so it is reachable by
            keyboard and reads as interactive. */}
        {message.isEdited() ? (
          <button
            type="button"
            className="ChatMessage-editedMark"
            title={app.translator.trans(
              "ramon-chat.forum.revisions.title",
              {},
              true,
            )}
            onclick={(e: Event) => {
              e.stopPropagation();
              app.modal.show(RevisionsModal, { message });
            }}
          >
            ({app.translator.trans("ramon-chat.forum.message.edited")})
          </button>
        ) : null}
      </div>
    );
  }

  protected systemRow(message: Message): Mithril.Children {
    const key = message.systemKey();

    return (
      <div className="ChatMessage ChatMessage--system">
        <div className="ChatMessage-gutter" />
        <div className="ChatMessage-body">
          <div className="ChatMessage-tombstone">
            {key
              ? app.translator.trans(
                  `ramon-chat.forum.message.system.${key}`,
                  message.systemData() ?? {},
                )
              : null}
          </div>
        </div>
      </div>
    );
  }

  /**
   * Opens the image viewer over the page.
   *
   * Mounted on a node appended to the body rather than rendered inside the row:
   * the message stream is `overflow: auto`, and a full-screen overlay inside it
   * would be clipped to the scroller.
   */
  protected openLightbox(images: any[], index: number): void {
    const mount = document.createElement("div");
    document.body.appendChild(mount);

    const close = () => {
      m.mount(mount, null);
      mount.remove();
    };

    m.mount(mount, {
      view: () =>
        m(ImageLightbox, {
          uploads: images,
          index: Math.max(0, index),
          message: this.attrs.message,
          onClose: close,
        }),
    });
  }

  protected shortTime(message: Message): Mithril.Children {
    const at = message.createdAt();

    if (!at) return null;

    return (
      <span>
        {at.getHours().toString().padStart(2, "0")}:
        {at.getMinutes().toString().padStart(2, "0")}
      </span>
    );
  }

  protected replyPreview(message: Message): Mithril.Children {
    const target = message.replyTo();

    if (!target) return null;

    return (
      <div className="ChatMessage-replyTo">
        <i className="fas fa-reply" aria-hidden="true" />
        <span>{authorLink(target)}</span>
        <span className="ChatMessage-replyTo-content">
          {messagePreview(target, 120)}
        </span>
      </div>
    );
  }

  protected reactions(message: Message): Mithril.Children {
    const summary = message.reactionSummary() ?? {};
    const emojis = Object.keys(summary);

    if (emojis.length === 0) return null;

    return (
      <div className="ChatReactions">
        {emojis.map((emoji) => {
          const entry = summary[emoji];

          return (
            <button
              key={emoji}
              type="button"
              className={classList("ChatReactions-chip", {
                "ChatReactions-chip--active": entry.reacted,
              })}
              disabled={!message.canReact()}
              onclick={(e: Event) => {
                e.stopPropagation();
                this.react(message, emoji);
              }}
            >
              {/* The like is drawn as the thumbs-up glyph, not 👍: it is the same
                  affordance flarum/likes uses on a post, and a font icon inherits
                  the chip's colour where an emoji stays fixed. Every other
                  reaction is a real emoji and renders as one. */}
              {emoji === LIKE_REACTION ? (
                <i
                  className="ChatReactions-icon fas fa-thumbs-up"
                  aria-hidden="true"
                />
              ) : (
                <span className="ChatReactions-emoji">
                  {displayEmoji(emoji)}
                </span>
              )}
              <span>{entry.count}</span>
            </button>
          );
        })}
      </div>
    );
  }

  protected uploads(message: Message): Mithril.Children {
    // `hasMany` yields `false` when unloaded, and its entries are optional when a
    // related record has not been pushed to the store yet.
    const related = message.uploads();

    if (!related) return null;

    const uploads = related.filter(
      (upload): upload is NonNullable<typeof upload> => Boolean(upload),
    );

    if (uploads.length === 0) return null;

    // The viewer steps between images, so it needs the images alone — a file
    // attachment in the middle would otherwise be a gap in the sequence.
    const images = uploads.filter((upload) => upload.isImage());

    return (
      <div className="ChatUploads">
        {uploads.map((upload, position) =>
          upload.isImage() ? (
            // A button, not a link: this opens a viewer over the conversation
            // rather than navigating away. It used to be `target="_blank"`, which
            // threw the reader out to look at a picture and left them to find
            // their way back.
            <button
              type="button"
              key={upload.id()}
              className="ChatUploads-imageButton"
              onclick={(e: Event) => {
                e.stopPropagation();
                this.openLightbox(images, images.indexOf(upload));
              }}
              aria-label={upload.fileName() ?? ""}
            >
              <img
                className="ChatUploads-image"
                src={safeFileUrl(upload.url())}
                alt={upload.fileName()}
                // Intrinsic size from the stored dimensions, so the row does not
                // reflow as the image loads.
                width={upload.width() ?? undefined}
                height={upload.height() ?? undefined}
                loading="lazy"
              />
            </button>
          ) : (
            <a
              key={upload.id()}
              className="ChatUploads-file"
              href={safeFileUrl(upload.url())}
              target="_blank"
              rel="noopener noreferrer"
            >
              <i className="fas fa-paperclip" aria-hidden="true" />
              <span className="ChatUploads-file-name">{upload.fileName()}</span>
              <span className="ChatUploads-file-size">
                {upload.humanSize()}
              </span>
            </a>
          ),
        )}
      </div>
    );
  }

  protected threadIndicator(message: Message): Mithril.Children {
    if (this.attrs.hideThreadIndicator) return null;

    const thread = message.thread();

    // Only the thread's root carries the indicator; replies live inside the panel.
    if (!thread || thread.originalMessageId() !== Number(message.id()))
      return null;

    const last = thread.lastMessage();

    return (
      <button
        type="button"
        className="ChatThreadIndicator"
        onclick={(e: Event) => {
          e.stopPropagation();
          this.attrs.onOpenThread?.(message);
        }}
      >
        <i className="fas fa-comments" aria-hidden="true" />
        <span className="ChatThreadIndicator-count">
          {app.translator.trans("ramon-chat.forum.thread.replies", {
            count: thread.repliesCount(),
          })}
        </span>
        {last ? (
          <span className="ChatThreadIndicator-preview">
            {messagePreview(last, 80)}
          </span>
        ) : null}
      </button>
    );
  }

  protected actions(message: Message): Mithril.Children {
    const items: Mithril.Children[] = [];

    if (message.canReact()) {
      const liked = Boolean(
        message.reactionSummary()?.[LIKE_REACTION]?.reacted,
      );

      items.push(
        <Button
          className={classList("ChatMessage-action", {
            "ChatMessage-action--active": liked,
          })}
          // Filled while liked, outlined otherwise — the same read as flarum/likes.
          icon={liked ? "fas fa-thumbs-up" : "far fa-thumbs-up"}
          title={app.translator.trans(
            liked
              ? "ramon-chat.forum.message.unlike"
              : "ramon-chat.forum.message.like",
            {},
            true,
          )}
          onclick={() => this.react(message, LIKE_REACTION)}
        />,
      );
    }

    if (message.canCreateThread()) {
      items.push(
        <Button
          className="ChatMessage-action"
          icon="fas fa-comments"
          title={app.translator.trans(
            "ramon-chat.forum.message.reply_in_thread",
            {},
            true,
          )}
          onclick={() => this.attrs.onOpenThread?.(message)}
        />,
      );
    }

    if (message.canReply()) {
      items.push(
        <Button
          className="ChatMessage-action"
          icon="fas fa-reply"
          title={app.translator.trans(
            "ramon-chat.forum.message.reply",
            {},
            true,
          )}
          onclick={() => this.attrs.onReply?.(message)}
        />,
      );
    }

    if (message.canEdit()) {
      items.push(
        <Button
          className="ChatMessage-action"
          icon="fas fa-pencil"
          title={app.translator.trans(
            "ramon-chat.forum.message.edit",
            {},
            true,
          )}
          onclick={() => this.attrs.onEdit?.(message)}
        />,
      );
    }

    if (app.session.user) {
      items.push(
        <Button
          className="ChatMessage-action"
          icon={message.isBookmarked() ? "fas fa-bookmark" : "far fa-bookmark"}
          title={app.translator.trans(
            message.isBookmarked()
              ? "ramon-chat.forum.message.remove_bookmark"
              : "ramon-chat.forum.message.bookmark",
            {},
            true,
          )}
          onclick={() => this.bookmark(message)}
        />,
      );
    }

    // Entering selection mode. Offered on every readable row, because quoting a
    // conversation elsewhere is not a moderator-only act — only *moving* is, and
    // that button is gated separately inside the selection bar.
    items.push(
      <Button
        className="ChatMessage-action"
        icon="fas fa-list-check"
        title={app.translator.trans(
          "ramon-chat.forum.message.select",
          {},
          true,
        )}
        onclick={() => {
          const state = this.attrs.state;

          state.selecting = true;
          state.selected.add(Number(message.id()));
          m.redraw();
        }}
      />,
    );

    if (message.canPin()) {
      const pinned = Boolean(message.isPinned());

      items.push(
        <Button
          className={classList("ChatMessage-action", {
            "ChatMessage-action--active": pinned,
          })}
          // The struck-through pin on an already-pinned message: the icon shows
          // what the click does, not what the message currently is. Colour alone
          // said "this is pinned" and left the reader to guess that pressing it
          // again undoes that — and the accent is invisible to anyone who cannot
          // separate it from the resting colour.
          icon={pinned ? "fas fa-thumbtack-slash" : "fas fa-thumbtack"}
          title={app.translator.trans(
            pinned
              ? "ramon-chat.forum.message.unpin"
              : "ramon-chat.forum.message.pin",
            {},
            true,
          )}
          onclick={() => this.pin(message)}
        />,
      );
    }

    // Reporting. Last in the row and never the default action, because it is the
    // one here that involves another person's time: it opens a queue item a
    // moderator has to read and decide on.
    if (message.canFlag()) {
      const reported = Boolean(message.isFlagged());

      items.push(
        <Button
          className={classList("ChatMessage-action", {
            "ChatMessage-action--active": reported,
          })}
          icon="fas fa-flag"
          title={app.translator.trans(
            reported
              ? "ramon-chat.forum.message.flagged"
              : "ramon-chat.forum.message.flag",
            {},
            true,
          )}
          onclick={() => app.modal.show(FlagMessageModal, { message })}
        />,
      );
    }

    if (message.canDelete()) {
      items.push(
        <Button
          className="ChatMessage-action"
          icon="fas fa-trash"
          title={app.translator.trans(
            "ramon-chat.forum.message.delete",
            {},
            true,
          )}
          onclick={() => this.delete(message)}
        />,
      );
    }

    if (items.length === 0) return null;

    return <div className="ChatMessage-actions">{items}</div>;
  }

  // ── Actions ────────────────────────────────────────────────────────────────

  protected async react(message: Message, emoji: string): Promise<void> {
    // Optimistic toggle: reactions are the highest-frequency interaction and a
    // round-trip before feedback feels broken.
    const summary = { ...(message.reactionSummary() ?? {}) };
    const entry = summary[emoji] ?? { count: 0, reacted: false };

    summary[emoji] = {
      count: entry.count + (entry.reacted ? -1 : 1),
      reacted: !entry.reacted,
    };

    if (summary[emoji].count <= 0) delete summary[emoji];

    message.pushAttributes({ reactionSummary: summary });
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-messages/${message.id()}/react`,
        body: { data: { attributes: { emoji } } },
      });

      app.store.pushPayload(payload);
    } catch (e) {
      // Put the server's version back rather than guessing at a rollback.
      message.pushAttributes({ reactionSummary: message.reactionSummary() });
    } finally {
      m.redraw();
    }
  }

  protected async bookmark(message: Message): Promise<void> {
    const was = Boolean(message.isBookmarked());

    message.pushAttributes({ isBookmarked: !was });
    m.redraw();

    try {
      await app.request({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-messages/${message.id()}/bookmark`,
        body: { data: { attributes: {} } },
      });
    } catch {
      message.pushAttributes({ isBookmarked: was });
    } finally {
      m.redraw();
    }
  }

  /**
   * Toggles the pin.
   *
   * Optimistic like the reaction toggle, and rolled back from the server's own
   * response rather than from a guess — a failed pin must not leave the row
   * claiming to be pinned to the one person who clicked.
   */
  protected async pin(message: Message): Promise<void> {
    const was = Boolean(message.isPinned());

    message.pushAttributes({ isPinned: !was });
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-messages/${message.id()}/pin`,
        body: { data: { attributes: {} } },
      });

      app.store.pushPayload(payload);
    } catch (e: any) {
      message.pushAttributes({ isPinned: was });

      app.alerts.show(
        { type: "error" },
        e?.response?.errors?.[0]?.detail ??
          app.translator.trans("ramon-chat.forum.message.pin_failed"),
      );
    } finally {
      m.redraw();
    }
  }

  protected async delete(message: Message): Promise<void> {
    if (
      !confirm(
        app.translator.trans(
          "ramon-chat.forum.message.delete_confirm",
          {},
          true,
        ),
      )
    )
      return;

    try {
      await app.request({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-messages/${message.id()}/delete`,
      });

      message.pushAttributes({
        isDeleted: true,
        content: null,
        contentHtml: null,
        // Stated rather than left to the next fetch. Deleting is optimistic, so
        // until then this row renders from whatever the client last set — and a
        // tombstone that guesses is how "removed by a moderator" ended up on
        // messages people had deleted themselves.
        isModeratorDeleted:
          Number((message.user() || null)?.id() ?? 0) !==
          Number(app.session.user?.id() ?? 0),
      });

      // What the optimistic push above cannot state: `canForceDelete` and its
      // siblings are the server's answer for this actor, and deleting is what
      // makes purging possible in the first place — the flag was false when the
      // row was last read and nothing here can honestly flip it.
      //
      // Not covered by the realtime handler that does the same job: the
      // message-changed broadcast excludes the actor, so the one person who just
      // moderated is the one it never reaches. That is why the control appeared
      // only after a reload.
      refreshMessageCapabilities(Number(message.id()));
    } catch (e) {
      app.alerts.show(
        { type: "error" },
        app.translator.trans("ramon-chat.forum.message.delete_failed"),
      );
    } finally {
      m.redraw();
    }
  }
}
