import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import type Mithril from "mithril";

import { playOnHover } from "../utils/stickers";

export interface StickerPickerAttrs extends ComponentAttrs {
  onInsert: (shortcode: string) => void;
  onClose: () => void;
}

/**
 * The chat's own sticker picker.
 *
 * ramon/stickers ships one, and reusing it was the first attempt — but it is
 * `require`d lazily inside that extension's own open handler, so it registers
 * itself in Flarum's export registry only *after* somebody has opened it from a
 * discussion composer. Until then the lookup correctly reports it as absent, and
 * the chat's button never appeared. Nothing here can force that module to load.
 *
 * So this reads the same data through the API instead: `stickers` is a JSON:API
 * resource, and the extension registers its model in the store at boot, which is
 * a signal that is true from the first render.
 *
 * Inserting is a shortcode, not markup: the formatter turns `:name:` into the
 * sticker on send, exactly as it does for a message typed by hand.
 */
export default class StickerPicker extends Component<StickerPickerAttrs> {
  private stickers: any[] = [];
  private loading = true;
  private filter = "";
  private outsideListener?: (e: MouseEvent) => void;

  oninit(vnode: Mithril.Vnode<StickerPickerAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  oncreate(vnode: Mithril.VnodeDOM<StickerPickerAttrs>): void {
    super.oncreate(vnode);

    // Deferred by a frame: the click that opened the picker is still propagating,
    // and binding synchronously would close it again immediately.
    requestAnimationFrame(() => {
      this.outsideListener = (e: MouseEvent) => {
        if (!vnode.dom.contains(e.target as Node)) this.attrs.onClose();
      };

      document.addEventListener("click", this.outsideListener);
    });

    (
      vnode.dom.querySelector(
        ".ChatStickerPicker-search",
      ) as HTMLInputElement | null
    )?.focus();

    playOnHover(vnode.dom as HTMLElement);
  }

  /**
   * Hydrate after every render, not only on create: the grid is rebuilt whenever
   * the filter changes, and the new nodes arrive unhydrated.
   */
  onupdate(vnode: Mithril.VnodeDOM<StickerPickerAttrs>): void {
    super.onupdate(vnode);

    playOnHover(vnode.dom as HTMLElement);
  }

  onremove(): void {
    if (this.outsideListener)
      document.removeEventListener("click", this.outsideListener);
  }

  view(): Mithril.Children {
    const term = this.filter.trim().toLowerCase();

    const shown = term
      ? this.stickers.filter((sticker) =>
          `${sticker.title() ?? ""} ${sticker.textToReplace() ?? ""} ${sticker.categoryName() ?? ""}`
            .toLowerCase()
            .includes(term),
        )
      : this.stickers;

    return (
      <div
        className="ChatStickerPicker"
        onkeydown={(e: KeyboardEvent) => this.onKey(e)}
      >
        <input
          className="FormControl ChatStickerPicker-search"
          type="search"
          placeholder={app.translator.trans(
            "ramon-chat.forum.composer.sticker_search",
            {},
            true,
          )}
          value={this.filter}
          oninput={(e: Event) => {
            this.filter = (e.target as HTMLInputElement).value;
          }}
        />

        {this.loading ? (
          <div className="ChatStickerPicker-loading">
            <LoadingIndicator display="inline" size="small" />
          </div>
        ) : shown.length === 0 ? (
          <div className="ChatStickerPicker-empty">
            {app.translator.trans("ramon-chat.forum.composer.sticker_none")}
          </div>
        ) : (
          <div className="ChatStickerPicker-grid">
            {shown.map((sticker) => (
              <button
                type="button"
                key={sticker.id()}
                className="ChatStickerPicker-item"
                title={sticker.title() ?? sticker.textToReplace()}
                onclick={() =>
                  this.attrs.onInsert(sticker.textToReplace() ?? "")
                }
              >
                {this.thumbnail(sticker)}
              </button>
            ))}
          </div>
        )}
      </div>
    );
  }

  /**
   * The thumbnail, in the markup ramon/stickers itself emits.
   *
   * A `.tgs` is gzip-compressed Lottie and a `.json` is Lottie; neither renders in
   * an `<img>`, and the first version of this drew their names instead — which on
   * a library of animated stickers meant a grid of words.
   *
   * Rather than reimplementing those renderers, this emits the exact markup the
   * extension's formatter produces, and its MutationObserver on `document.body`
   * picks them up and animates them. That observer already exists to hydrate
   * stickers inside posts; it does not care where the nodes came from.
   *
   * The path is resolved the same way too: a relative one is served from the forum
   * root, which is what their PHP does before writing the attribute.
   */
  protected thumbnail(sticker: any): Mithril.Children {
    const path = this.absolute(String(sticker.path() ?? ""));
    const title = sticker.title() ?? "";
    const lower = path.toLowerCase();

    if (lower.endsWith(".tgs")) {
      return (
        <span className="Sticker Sticker--tgs" data-tgs={path} title={title} />
      );
    }

    if (lower.endsWith(".json")) {
      return (
        <span
          className="Sticker Sticker--lottie"
          data-lottie={path}
          title={title}
        />
      );
    }

    return (
      <span className="Sticker">
        <img className="sticker" src={path} alt={title} loading="lazy" />
      </span>
    );
  }

  protected absolute(path: string): string {
    if (/^https?:\/\//i.test(path)) return path;

    const base = String(app.forum.attribute("baseUrl") ?? "").replace(
      /\/$/,
      "",
    );

    return base + (path.startsWith("/") ? path : `/${path}`);
  }

  protected onKey(e: KeyboardEvent): void {
    if (e.key === "Escape") {
      e.stopPropagation();
      this.attrs.onClose();
    }
  }

  protected async load(): Promise<void> {
    try {
      const results = await app.store.find<any[]>("stickers", {
        page: { limit: 200 },
      });

      this.stickers = Array.isArray(results) ? results : [];
    } catch {
      this.stickers = [];
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
}
