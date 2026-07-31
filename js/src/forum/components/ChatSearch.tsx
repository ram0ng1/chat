import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import Avatar from "flarum/common/components/Avatar";
import humanTime from "flarum/common/helpers/humanTime";
import type Mithril from "mithril";

import type Message from "../../common/models/Message";
import type ChatState from "../state/ChatState";
import { SearchResultsSkeleton } from "./Skeletons";
import { messagePreview } from "../utils/preview";
import { authorLink } from "../utils/bot";

export interface ChatSearchAttrs extends ComponentAttrs {
  state: ChatState;
  /** When set, the search is scoped to one channel instead of all of them. */
  channelId?: number | null;
}

/** Keystrokes are coalesced for this long before a request goes out. */
const DEBOUNCE = 300;

const PAGE_SIZE = 30;

/**
 * Message search, scoped to one channel or to everything the actor can read.
 *
 * Queries `filter[q]` on the message searcher, so results are already restricted
 * by the same visibility scope the stream uses — there is no separate permission
 * check to keep in step here.
 */
export default class ChatSearch extends Component<ChatSearchAttrs> {
  private query = "";
  private results: Message[] = [];
  private searching = false;
  /** Whether a query has run, to tell "no results" apart from "nothing typed". */
  private searched = false;
  private timer: number | null = null;
  /** Guards against an earlier, slower request overwriting a later one. */
  private sequence = 0;

  onremove(): void {
    if (this.timer !== null) window.clearTimeout(this.timer);
  }

  view(): Mithril.Children {
    const channel = this.attrs.channelId
      ? this.attrs.state.channel(this.attrs.channelId)
      : null;

    return (
      <div className="ChatSearch">
        <div className="ChatSearch-bar">
          <i
            className="ChatSearch-icon fas fa-magnifying-glass"
            aria-hidden="true"
          />

          <input
            className="ChatSearch-input"
            type="search"
            value={this.query}
            placeholder={app.translator.trans(
              "ramon-chat.forum.search.placeholder",
              {},
              true,
            )}
            aria-label={app.translator.trans(
              "ramon-chat.forum.search.title",
              {},
              true,
            )}
            oninput={(e: Event) =>
              this.onInput((e.target as HTMLInputElement).value)
            }
            onkeydown={(e: KeyboardEvent) => {
              // Enter searches immediately rather than waiting out the debounce.
              if (e.key === "Enter") this.run();
            }}
          />

          {this.query ? (
            <Button
              className="Button Button--icon Button--flat ChatSearch-clear"
              icon="fas fa-xmark"
              onclick={() => this.clear()}
            />
          ) : null}
        </div>

        {channel ? (
          <div className="ChatSearch-scope">
            {app.translator.trans("ramon-chat.forum.search.in_channel", {
              channel: channel.displayName(),
            })}
          </div>
        ) : null}

        {this.body()}
      </div>
    );
  }

  protected body(): Mithril.Children {
    if (this.searching && this.results.length === 0) {
      return SearchResultsSkeleton();
    }

    if (!this.searched) {
      return (
        <div className="ChatBrowse-empty">
          {app.translator.trans("ramon-chat.forum.search.prompt")}
        </div>
      );
    }

    if (this.results.length === 0) {
      return (
        <div className="ChatBrowse-empty">
          {app.translator.trans("ramon-chat.forum.search.empty")}
        </div>
      );
    }

    return (
      <div className="ChatSearch-results">
        {this.results.map((message) => this.result(message))}
      </div>
    );
  }

  protected result(message: Message): Mithril.Children {
    const channel = this.attrs.state.channel(message.channelId());
    const at = message.createdAt();

    return (
      <button
        type="button"
        className="ChatSearch-result"
        key={message.id()}
        onclick={() => this.open(message)}
      >
        <Avatar user={message.user()} className="Avatar" />

        <div className="ChatSearch-result-body">
          <div className="ChatSearch-result-meta">
            <span className="ChatSearch-result-author">
              {authorLink(message)}
            </span>
            {channel ? (
              <span className="ChatSearch-result-channel">
                {channel.displayName()}
              </span>
            ) : null}
            {at ? (
              <span className="ChatSearch-result-time">{humanTime(at)}</span>
            ) : null}
          </div>

          {/* Plain text, not contentHtml: a result row is a one-line excerpt, and
              rendered markup would drag block elements and images into it. */}
          <div className="ChatSearch-result-excerpt">
            {messagePreview(message)}
          </div>
        </div>
      </button>
    );
  }

  // ── Behaviour ──────────────────────────────────────────────────────────────

  protected onInput(value: string): void {
    this.query = value;

    if (this.timer !== null) window.clearTimeout(this.timer);

    if (value.trim() === "") {
      this.results = [];
      this.searched = false;

      return;
    }

    this.timer = window.setTimeout(() => this.run(), DEBOUNCE);
  }

  protected async run(): Promise<void> {
    if (this.timer !== null) {
      window.clearTimeout(this.timer);
      this.timer = null;
    }

    const term = this.query.trim();

    if (term === "") return;

    const mine = ++this.sequence;

    this.searching = true;
    m.redraw();

    try {
      const results = (await app.store.find("chat-messages", {
        filter: {
          q: term,
          ...(this.attrs.channelId ? { channel: this.attrs.channelId } : {}),
        },
        sort: "-id",
        page: { limit: PAGE_SIZE },
      })) as unknown as Message[];

      // A slower earlier request must not clobber a later one's results.
      if (mine !== this.sequence) return;

      this.results = Array.isArray(results) ? results : [];
      this.searched = true;
    } catch {
      if (mine !== this.sequence) return;

      this.results = [];
      this.searched = true;
    } finally {
      if (mine === this.sequence) this.searching = false;

      m.redraw();
    }
  }

  protected clear(): void {
    this.query = "";
    this.results = [];
    this.searched = false;

    if (this.timer !== null) window.clearTimeout(this.timer);

    m.redraw();
  }

  /**
   * Opens the result's channel.
   *
   * It does not jump to the message itself: the stream pages backwards from the
   * newest message, so landing on an old one would mean loading everything in
   * between. Better to open the conversation than to appear to hang.
   */
  protected open(message: Message): void {
    const channelId = message.channelId();

    if (!channelId) return;

    this.attrs.state.setActiveChannel(channelId);
    m.route.set(app.route("chat.channel", { id: channelId }));
  }
}
