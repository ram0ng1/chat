import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import humanTime from "flarum/common/helpers/humanTime";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Thread from "../../common/models/Thread";
import type ChatState from "../state/ChatState";
import { ThreadsSkeleton } from "./Skeletons";
import iconLabel from "../utils/iconLabel";

export interface ThreadsListAttrs extends ComponentAttrs {
  state: ChatState;
  /**
   * One channel's threads, every one of them, rather than the actor's own
   * across the chat. From the channel header and the drawer's menu.
   */
  channelId?: number | null;
  /** Drawn over the conversation in the drawer, with its own header. */
  embedded?: boolean;
  /** Dismisses the pane. Required when embedded; ignored otherwise. */
  onClose?: () => void;
}

/**
 * Threads: the actor's own across every channel, or all of one channel's.
 *
 * The counterpart to the thread panel: the panel reads one thread, this is how
 * you find it again afterwards. Selecting a row opens the panel over its own
 * channel: on the page by routing, in the drawer by setting the thread on the
 * state, since routing from the drawer closes it.
 */
export default class ThreadsList extends Component<ThreadsListAttrs> {
  private threads: Thread[] = [];
  private loading = true;

  oninit(vnode: Mithril.Vnode<ThreadsListAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<ThreadsListAttrs, this>): void {
    const previous = this.attrs?.channelId ?? null;

    super.onbeforeupdate(vnode);

    // Same component, another channel: the page keeps one mounted instance
    // across chat routes (see ChatPageResolver), so the scope can change under it.
    if ((vnode.attrs.channelId ?? null) !== previous) {
      this.loading = true;
      this.load();
    }
  }

  view(): Mithril.Children {
    return (
      <div
        className={classList("ChatThreadsList", {
          "ChatThreadsList--embedded": this.attrs.embedded,
        })}
      >
        {this.header()}
        {this.body()}
      </div>
    );
  }

  /**
   * A title, and in the drawer a way back to the conversation. The page's
   * sections carry their own title in the toolbar, so only the scoped list
   * names itself there.
   */
  protected header(): Mithril.Children {
    const scoped = Boolean(this.attrs.channelId);

    if (!this.attrs.embedded && !scoped) return null;

    const channel = scoped
      ? this.attrs.state.channel(Number(this.attrs.channelId))
      : null;

    // The panel shell the thread, pinned and search panes use, so every pane
    // that can sit in the drawer's overlay slot is dismissed the same way.
    return (
      <div className="ChatThreadPanel-header">
        <i className="fas fa-code-branch" aria-hidden="true" />

        <span className="ChatThreadPanel-title">
          {app.translator.trans("ramon-chat.forum.channel.threads")}
          {channel ? (
            <span className="ChatThreadsList-scope">
              {channel.displayName()}
            </span>
          ) : null}
        </span>

        {this.attrs.embedded ? (
          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-xmark"
            {...iconLabel(
              app.translator.trans("ramon-chat.forum.search.close", {}, true),
            )}
            onclick={this.attrs.onClose}
          />
        ) : null}
      </div>
    );
  }

  protected body(): Mithril.Children {
    if (this.loading) return ThreadsSkeleton();

    if (this.threads.length === 0) {
      return (
        <div className="ChatBrowse-empty">
          {app.translator.trans(
            this.attrs.channelId
              ? "ramon-chat.forum.thread.no_threads_in_channel"
              : "ramon-chat.forum.thread.no_threads",
          )}
        </div>
      );
    }

    return (
      <div className="ChatThreadsList-rows">
        {this.threads.map((thread) => this.row(thread))}
      </div>
    );
  }

  protected row(thread: Thread): Mithril.Children {
    const channel = this.attrs.state.channel(thread.channelId());
    const at = thread.lastMessageAt();

    return (
      <button
        type="button"
        key={thread.id()}
        className={classList("ChatThreadsList-row", {
          "ChatThreadsList-row--unread": thread.hasUnread(),
        })}
        onclick={() => this.open(thread)}
      >
        <i
          className="ChatThreadsList-icon fas fa-comments"
          aria-hidden="true"
        />

        <div className="ChatThreadsList-body">
          <div className="ChatThreadsList-title">{thread.displayTitle()}</div>

          <div className="ChatThreadsList-meta">
            {/* The channel's name only when the list spans channels; inside
                one channel every row would repeat the header. */}
            {channel && !this.attrs.channelId ? (
              <span>{channel.displayName()}</span>
            ) : null}
            <span>
              {app.translator.trans("ramon-chat.forum.thread.replies", {
                count: thread.repliesCount(),
              })}
            </span>
            {at ? <span>{humanTime(at)}</span> : null}
          </div>
        </div>

        {thread.hasUnread() ? (
          <span className="ChatThreadsList-badge">{thread.unreadCount()}</span>
        ) : null}
      </button>
    );
  }

  protected async load(): Promise<void> {
    const channelId = this.attrs.channelId ?? null;

    try {
      const results = (await app.store.find("chat-threads", {
        filter: channelId ? { channel: channelId } : { participating: true },
        sort: "-lastMessageAt",
        page: { limit: 50 },
      })) as unknown as Thread[];

      // A scope that changed while the request was out is not this answer's.
      if ((this.attrs.channelId ?? null) !== channelId) return;

      this.threads = Array.isArray(results) ? results : [];
    } catch {
      this.threads = [];
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  protected open(thread: Thread): void {
    const channelId = thread.channelId();

    if (!channelId) return;

    this.attrs.state.setActiveChannel(channelId);

    // In the drawer the thread panel takes this pane's slot; the state is the
    // route there. Closing the list first, or the two would both claim it.
    if (this.attrs.embedded) {
      this.attrs.state.showThreads = false;
      this.attrs.state.activeThreadId = Number(thread.id());
      m.redraw();

      return;
    }

    this.attrs.state.activeThreadId = Number(thread.id());

    m.route.set(
      app.route("chat.thread", { id: channelId, threadId: thread.id() }),
    );
  }
}
