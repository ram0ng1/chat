import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import Dropdown from "flarum/common/components/Dropdown";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import type ChatState from "../state/ChatState";

export interface ChatSelectionBarAttrs extends ComponentAttrs {
  channel: Channel;
  state: ChatState;
}

interface TranscriptResponse {
  data: {
    attributes: {
      format: string;
      content: string;
      count: number;
      omitted: number;
    };
  };
}

/**
 * The bar shown while messages are selected: quote into a discussion, copy, move.
 *
 * Rendering the transcript is the server's job — TranscriptController re-checks
 * every id against `whereVisibleTo`, so a selection that names a message the actor
 * cannot read comes back without it, and the count of what was dropped is reported
 * rather than silently swallowed.
 */
export default class ChatSelectionBar extends Component<ChatSelectionBarAttrs> {
  private working = false;

  view(): Mithril.Children {
    const { state } = this.attrs;
    const count = state.selected.size;

    return (
      <div className="ChatSelectionBar">
        <span className="ChatSelectionBar-count">
          {app.translator.trans("ramon-chat.forum.selection.count", { count })}
        </span>

        <div className="ChatSelectionBar-actions">
          <Button
            className="Button Button--text"
            icon="fas fa-quote-left"
            disabled={count === 0 || this.working}
            onclick={() => this.quote()}
          >
            {app.translator.trans(
              "ramon-chat.forum.message.quote_in_discussion",
            )}
          </Button>

          <Button
            className="Button Button--text"
            icon="fas fa-copy"
            disabled={count === 0 || this.working}
            onclick={() => this.copy()}
          >
            {app.translator.trans("ramon-chat.forum.message.copy_text")}
          </Button>

          {this.moveControls(count)}

          <Button
            className="Button Button--text"
            icon="fas fa-xmark"
            onclick={() => this.cancel()}
          >
            {app.translator.trans("ramon-chat.forum.selection.cancel")}
          </Button>
        </div>
      </div>
    );
  }

  /**
   * Moving needs `ramon-chat.moderate`, and the destination has to be somewhere the
   * actor may post — a closed or archived channel is refused server-side, so those
   * are not offered.
   */
  protected moveControls(count: number): Mithril.Children {
    const { channel, state } = this.attrs;

    if (!app.forum.attribute<boolean>("canModerateChat")) return null;

    const targets = state.channels.filter(
      (candidate) =>
        candidate.id() !== channel.id() &&
        candidate.canPostMessage() &&
        !candidate.archivedAt(),
    );

    if (targets.length === 0) return null;

    const blocked = count === 0 || this.working;

    // One control rather than a <select> plus a Move button. The pair cost two
    // slots in a bar that has to fit a drawer, and the button sat disabled until
    // a target was picked — dead UI for the whole time the menu was closed.
    // Picking a channel here is the action; there is nothing left to confirm.
    //
    // The menu is positioned in components.less rather than through
    // `menuClassName`: core strips its own placement classes on every open and
    // recomputes them against the window, which is the wrong box for a bar
    // pinned to the bottom of a drawer.
    return (
      <Dropdown
        className="ChatSelectionBar-move"
        buttonClassName="Button Button--text"
        icon="fas fa-right-left"
        label={app.translator.trans("ramon-chat.forum.selection.move_to")}
        buttonAttrs={blocked ? { disabled: "true" } : {}}
      >
        {targets.map((target) => (
          <Button
            key={target.id()}
            icon="fas fa-hashtag"
            onclick={() => this.move(String(target.id()))}
          >
            {target.displayName()}
          </Button>
        ))}
      </Dropdown>
    );
  }

  // ── Actions ────────────────────────────────────────────────────────────────

  protected async transcript(
    format: "markup" | "plain",
  ): Promise<TranscriptResponse["data"]["attributes"] | null> {
    this.working = true;
    m.redraw();

    try {
      const payload = await app.request<TranscriptResponse>({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat/transcript`,
        body: {
          data: {
            attributes: { messageIds: [...this.attrs.state.selected], format },
          },
        },
      });

      const attributes = payload.data.attributes;

      if (attributes.omitted > 0) {
        app.alerts.show(
          { type: "warning" },
          app.translator.trans("ramon-chat.forum.selection.omitted", {
            count: attributes.omitted,
          }),
        );
      }

      return attributes;
    } catch (e: any) {
      app.alerts.show(
        { type: "error" },
        e?.response?.errors?.[0]?.detail ??
          app.translator.trans("ramon-chat.forum.selection.failed"),
      );

      return null;
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected async quote(): Promise<void> {
    const rendered = await this.transcript("markup");

    if (!rendered) return;

    const DiscussionComposer = (
      await import("flarum/forum/components/DiscussionComposer")
    ).default;

    // Seeded through `originalContent`, not by writing `fields.content` after
    // load(). ComposerBody.oninit runs when show() mounts the body, and its first
    // act is `fields.content(attrs.originalContent || '')` — so anything written
    // beforehand is overwritten with the empty string, which is why the composer
    // opened blank. Passing it as an attr is the same route EditPostComposer takes
    // to pre-fill a post's text, and it also means closing straight away does not
    // ask to discard: nothing has been typed yet.
    await app.composer.load(
      () => Promise.resolve({ default: DiscussionComposer }),
      {
        user: app.session.user,
        originalContent: rendered.content,
      },
    );

    await app.composer.show();

    this.cancel();
  }

  protected async copy(): Promise<void> {
    const rendered = await this.transcript("plain");

    if (!rendered) return;

    try {
      await navigator.clipboard.writeText(rendered.content);

      app.alerts.show(
        { type: "success" },
        app.translator.trans("ramon-chat.forum.selection.copied"),
      );
      this.cancel();
    } catch {
      // Clipboard access is refused outside a secure context and in some
      // embedded browsers. Say so rather than appearing to have copied.
      app.alerts.show(
        { type: "error" },
        app.translator.trans("ramon-chat.forum.selection.copy_failed"),
      );
    }
  }

  protected async move(target: string): Promise<void> {
    const { state, channel } = this.attrs;
    const ids = [...state.selected];

    this.working = true;
    m.redraw();

    try {
      await app.request({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat/messages/move`,
        body: {
          data: {
            attributes: { messageIds: ids, channelId: Number(target) },
          },
        },
      });

      // The messages are no longer in this channel; drop them from the window
      // rather than refetching the whole page.
      for (const id of ids) {
        state.removeMessage(Number(channel.id()), id);
      }

      app.alerts.show(
        { type: "success" },
        app.translator.trans("ramon-chat.forum.selection.moved", {
          count: ids.length,
        }),
      );

      this.cancel();
    } catch (e: any) {
      app.alerts.show(
        { type: "error" },
        e?.response?.errors?.[0]?.detail ??
          app.translator.trans("ramon-chat.forum.selection.failed"),
      );
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected cancel(): void {
    const { state } = this.attrs;

    state.selecting = false;
    state.selected.clear();
    m.redraw();
  }
}
