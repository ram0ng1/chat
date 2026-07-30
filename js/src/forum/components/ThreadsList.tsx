import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import humanTime from "flarum/common/helpers/humanTime";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Thread from "../../common/models/Thread";
import type ChatState from "../state/ChatState";
import { ThreadsSkeleton } from "./Skeletons";

export interface ThreadsListAttrs extends ComponentAttrs {
  state: ChatState;
}

/**
 * The threads the actor takes part in, across every channel.
 *
 * The counterpart to the thread panel: the panel reads one thread, this is how
 * you find it again afterwards. Selecting a row routes to the panel over its own
 * channel.
 */
export default class ThreadsList extends Component<ThreadsListAttrs> {
  private threads: Thread[] = [];
  private loading = true;

  oninit(vnode: Mithril.Vnode<ThreadsListAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  view(): Mithril.Children {
    if (this.loading) {
      return <div className="ChatThreadsList">{ThreadsSkeleton()}</div>;
    }

    if (this.threads.length === 0) {
      return (
        <div className="ChatThreadsList">
          <div className="ChatBrowse-empty">
            {app.translator.trans("ramon-chat.forum.thread.no_threads")}
          </div>
        </div>
      );
    }

    return (
      <div className="ChatThreadsList">
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
            {channel ? <span>{channel.displayName()}</span> : null}
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
    try {
      const results = (await app.store.find("chat-threads", {
        filter: { participating: true },
        sort: "-lastMessageAt",
        page: { limit: 50 },
      })) as unknown as Thread[];

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
    this.attrs.state.activeThreadId = Number(thread.id());

    m.route.set(
      app.route("chat.thread", { id: channelId, threadId: thread.id() }),
    );
  }
}
