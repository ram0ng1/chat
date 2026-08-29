import app from "flarum/forum/app";
import type Mithril from "mithril";

/**
 * Optional integration with pianotell/flarum-ext-flamoji.
 *
 * Nothing here is imported from that extension: everything is resolved from the
 * export registry at runtime, so the chat frontend boots identically whether or
 * not Flamoji is installed. A static `import … from 'ext:pianotell-flamoji/…'`
 * would make it a hard dependency, which is the one thing this module exists to
 * avoid.
 *
 * ## What already works without any of this
 *
 * Message bodies need nothing. `Message` renders through core's shared
 * `Formatter`, which is exactly where Flamoji registers its Emoticon
 * replacements, so `:kappa:` in a chat message arrives as
 * `<span class="flamoji"><img …></span>` in `contentHtml` — and Flamoji's own
 * `span.flamoji img` rule is unscoped, so it is styled there too.
 *
 * ## What does not, and is what this module is for
 *
 * Three places render emoji *outside* the formatter, and each was blind to a
 * custom one:
 *
 * - the composer, which has no Flamoji button because it is a bare textarea and
 *   not Flarum's `TextEditor`, the only thing Flamoji extends;
 * - the `:` autocomplete, which searches the Unicode map only;
 * - reaction chips, which resolve a stored shortcode through that same map and
 *   fall back to printing `:kappa:` as literal text.
 */

/** The namespace Flamoji registers its modules under (the extension id). */
const EXTENSION = "pianotell-flamoji";

/** Where Flamoji's own picker reads the full set from. Unauthenticated. */
const ALL_ENDPOINT = "/flamojis/all";

export interface CustomEmoji {
  /** Bare shortcode, no colons — `kappa`. The key everything matches on. */
  name: string;
  /** The exact trigger to type, as stored — usually `:kappa:`. */
  insert: string;
  /** Absolute image URL. */
  url: string;
  /** Admin-set label, falling back to the name. */
  title: string;
}

/**
 * Reads a module out of Flarum's export registry.
 *
 * `checkModule` is preferred over `get` because it reports a missing module as
 * `false` rather than throwing, which is the normal case here: Flamoji not being
 * installed is not an error.
 */
function registryModule(path: string): any {
  const registry = (window as any)?.flarum?.reg;

  if (!registry) return null;

  try {
    return typeof registry.checkModule === "function"
      ? registry.checkModule(EXTENSION, path)
      : registry.get?.(EXTENSION, path);
  } catch {
    return null;
  }
}

/**
 * Whether Flamoji is installed and enabled.
 *
 * Keyed on its picker button component rather than on a forum attribute. Both
 * would work, but the button is what the composer actually needs — testing for
 * the thing being used keeps the check honest if Flamoji ever reshuffles its
 * settings.
 *
 * Unlike ramon/stickers' picker, this component is registered in Flamoji's main
 * bundle at boot, so the lookup is reliable from the first render rather than
 * only after somebody has opened it once.
 */
export function flamojiAvailable(): boolean {
  return Boolean(registryModule("forum/components/FlamojiPickerButton"));
}

/**
 * Absolute URL for a stored path.
 *
 * Mirrors `ConfigureTextFormatter::__invoke` on the server, which anchors the
 * same `^https?://` test and otherwise treats the value as forum-relative. The
 * two have to agree or the picker offers an image the message body cannot show.
 */
function absolutise(path: string): string {
  if (/^https?:\/\//i.test(path)) return path;

  const base = String(app.forum.attribute("baseUrl") ?? "").replace(/\/+$/, "");

  return base + (path.startsWith("/") ? path : "/" + path);
}

/** Strips the delimiters from `:name:`, matching `emoji.ts`'s `normalise`. */
function bareName(trigger: string): string {
  return trigger.trim().replace(/^:+|:+$/g, "");
}

let all: CustomEmoji[] = [];
let byName: Map<string, CustomEmoji> = new Map();
let loaded = false;
let loading = false;

/**
 * Pulls the emoji records out of whatever `/flamojis/all` returned.
 *
 * That endpoint is not a JSON:API document. It returns the models straight from
 * `Emoji::…->get()->all()`, so they serialise as *numerically keyed properties*
 * alongside the `jsonapi` version key, with the columns at the top level rather
 * than under `attributes`:
 *
 *     { "0": { "id": 3, "text_to_replace": ":kappa:", "path": "…" },
 *       "jsonapi": { "version": "1.1" } }
 *
 * Flamoji's own picker filters for numeric keys for exactly this reason. The
 * `data` branch is insurance, not observed behaviour: if the endpoint is ever
 * normalised into a proper document, this keeps reading it instead of silently
 * going empty.
 */
function extractRecords(response: any): Array<Record<string, unknown>> {
  if (!response || typeof response !== "object") return [];

  if (Array.isArray(response.data)) {
    return response.data.map((entry: any) => ({
      ...(entry?.attributes ?? {}),
      ...entry,
    }));
  }

  return Object.keys(response)
    .filter((key) => key !== "" && !isNaN(Number(key)))
    .map((key) => response[key])
    .filter((entry) => entry && typeof entry === "object");
}

/**
 * Fetches the custom-emoji set once per page.
 *
 * The same unpaginated endpoint Flamoji's picker uses, hit directly rather than
 * through the store: these records are read-only here, and pushing them into
 * `app.store` would mean depending on Flamoji having registered its `flamojis`
 * model — one more thing to be absent.
 *
 * Redraws on completion so anything already rendered from an empty set (a
 * reaction chip showing `:kappa:`, an autocomplete list without customs)
 * upgrades in place.
 */
export function loadCustomEmoji(): void {
  if (loaded || loading || !flamojiAvailable()) return;

  loading = true;

  app
    .request<any>({
      method: "GET",
      url: app.forum.attribute("apiUrl") + ALL_ENDPOINT,
    })
    .then((response) => {
      const records = extractRecords(response);
      const next: CustomEmoji[] = [];
      const index = new Map<string, CustomEmoji>();

      for (const record of records) {
        const trigger = String(record.text_to_replace ?? "").trim();
        const path = String(record.path ?? "").trim();

        // The server skips rows missing either half when configuring the
        // formatter, so a picker that offered them would insert a trigger that
        // renders as nothing.
        if (trigger === "" || path === "") continue;

        const name = bareName(trigger);

        if (name === "") continue;

        const emoji: CustomEmoji = {
          name,
          insert: trigger,
          url: absolutise(path),
          title: String(record.title ?? "").trim() || name,
        };

        next.push(emoji);

        // Indexed lowercase, and looked up the same way: the reaction endpoint
        // matches shortcodes case-insensitively, so `:Kappa:` and `:kappa:` have
        // to resolve to one image rather than one image and one literal string.
        //
        // First wins, so a duplicate trigger cannot displace the row the
        // formatter itself would have used.
        const key = name.toLowerCase();

        if (!index.has(key)) index.set(key, emoji);
      }

      all = next;
      byName = index;
      loaded = true;

      m.redraw();
    })
    .catch(() => {
      // An unreachable endpoint leaves the chat exactly as it is without
      // Flamoji: Unicode emoji everywhere, custom ones as their shortcode.
      loaded = true;
    })
    .finally(() => {
      loading = false;
    });
}

/** The custom emoji for a bare shortcode, or null. */
export function customEmoji(
  shortcode: string | null | undefined,
): CustomEmoji | null {
  if (!shortcode) return null;

  loadCustomEmoji();

  return byName.get(bareName(shortcode).toLowerCase()) ?? null;
}

/**
 * Searches custom emoji by shortcode, for the composer's `:` autocomplete.
 *
 * Ranked the same way `searchEmoji` ranks the Unicode set — prefix before
 * substring — so a merged list is ordered consistently rather than by which
 * source it came from.
 */
export function searchCustomEmoji(query: string, limit = 8): CustomEmoji[] {
  loadCustomEmoji();

  const term = bareName(query).toLowerCase();

  if (term === "") return all.slice(0, limit);

  const prefix: CustomEmoji[] = [];
  const contains: CustomEmoji[] = [];

  for (const emoji of all) {
    const at = emoji.name.toLowerCase().indexOf(term);

    if (at === 0) prefix.push(emoji);
    else if (at > 0) contains.push(emoji);

    if (prefix.length >= limit) break;
  }

  return [...prefix, ...contains].slice(0, limit);
}

/** The `<img>` a custom emoji renders as, sized by our own LESS. */
export function customEmojiImage(
  emoji: CustomEmoji,
  className = "ChatFlamoji",
): Mithril.Children {
  return m("img", {
    className,
    src: emoji.url,
    alt: emoji.title,
    title: `:${emoji.name}:`,
    loading: "lazy",
  });
}

/**
 * The containers a chat composer can live in.
 *
 * The picker must stay inside whichever one it is, not merely inside the
 * viewport — the drawer is a floating panel a few hundred pixels wide sitting
 * over the forum, so "on screen" and "on the panel" are very different places.
 */
const PANEL_SELECTOR = ".ChatDrawer, .ChatPage, .ChatThreadPanel";

/**
 * Places the picker for a chat composer.
 *
 * Replaces Flamoji's own geometry, which centres the popup horizontally on the
 * button. That is right for the discussion composer, which spans the content
 * column — there is room either side of the button. In the drawer there is not:
 * the button sits at the right end of a narrow panel, so a centred 400px popup
 * hangs off the panel and over the message list behind it.
 *
 * Right-aligned to the button and floating above it instead, which is where the
 * room is in a composer pinned to the bottom of a panel — and the same placement
 * the sticker picker already uses, so the two tools open in the same place.
 *
 * Keeps Flamoji's contracts: no-ops until the element has been measured (emoji
 * mart fills its Shadow DOM asynchronously, so the first call after mount sees
 * zeroes), and clamps to the viewport last so the popup is never off-screen.
 */
function positionInChat(el: HTMLElement, button: HTMLElement): void {
  const btn = button.getBoundingClientRect();
  const box = el.getBoundingClientRect();

  if (!box.width || !box.height) return;

  const margin = 6;
  const pad = 8;

  const panel = button.closest(PANEL_SELECTOR) as HTMLElement | null;
  const bounds = panel?.getBoundingClientRect() ?? null;

  let left = btn.right - box.width;
  let top = btn.top - margin - box.height;

  if (bounds) {
    // A popup wider than the panel cannot be contained by it, so centre it on
    // the panel rather than pinning it to one edge and letting the whole
    // overflow fall on the other.
    left =
      box.width <= bounds.width - pad * 2
        ? Math.min(
            Math.max(left, bounds.left + pad),
            bounds.right - box.width - pad,
          )
        : bounds.left + (bounds.width - box.width) / 2;
  }

  // The viewport wins over the panel: a popup pushed off-screen to honour a
  // panel that is itself partly off-screen would be unusable.
  left = Math.min(Math.max(left, pad), window.innerWidth - box.width - pad);
  top = Math.min(Math.max(top, pad), window.innerHeight - box.height - pad);

  el.style.left = `${Math.round(left)}px`;
  el.style.top = `${Math.round(top)}px`;
}

/** Cached so the class is built once rather than on every composer render. */
let buttonComponent: any = null;

/**
 * Flamoji's own toolbar button, re-hosted in the chat composer.
 *
 * `FlamojiPickerButton` owns the entire picker lifecycle — lazy-loading
 * emoji-mart, portaling the popup to `document.body`, positioning, teardown —
 * and talks to whatever hosts it through a two-method interface:
 * `getButtonElement()` for the anchor and `insertText()` for the insertion.
 * Only the second is composer-specific, so subclassing to override it reuses
 * that machinery whole instead of reimplementing a picker the forum already has.
 *
 * The subclass is built lazily rather than at module scope: the registry is
 * populated during Flamoji's own initializer, and reading it while this module
 * is being evaluated would capture `undefined` on some load orders.
 */
function pickerButton(): any {
  if (buttonComponent) return buttonComponent;

  const Base = registryModule("forum/components/FlamojiPickerButton");

  if (!Base) return null;

  buttonComponent = class ChatFlamojiPickerButton extends Base {
    static initAttrs(attrs: any): void {
      super.initAttrs(attrs);

      // `TextEditorButton.initAttrs` assigns the class list outright, so this
      // has to append afterwards. `Button-flamoji` must survive: it is what
      // `getButtonElement()` looks for, and losing it would leave the picker
      // with nothing to position against.
      attrs.className = `${attrs.className} ChatComposer-tool`;
    }

    oninit(vnode: Mithril.Vnode): void {
      super.oninit(vnode);

      // `position()` is the one seam both placements go through — the picker
      // itself, and the loading placeholder that stands in while it downloads —
      // so overriding it here keeps the two from opening in different spots.
      //
      // Patched on the instance rather than passed in, because the controller
      // takes no placement option: it resolves its own fallback anchor by
      // looking for `.ComposerBody`/`.TextEditor` above the button, and finds
      // neither here. Guarded so a renamed internal degrades to Flamoji's own
      // positioning rather than throwing.
      const controller = (this as any).controller;

      if (typeof controller?.position !== "function") return;

      controller.position = (el: HTMLElement | null) => {
        const anchor = this.getButtonElement();

        if (el && anchor) positionInChat(el, anchor);
      };
    }

    /** Where the picker's output goes, instead of a `TextEditor`'s cursor. */
    insertText(text: string): void {
      this.attrs.onInsert?.(text);
    }
  };

  return buttonComponent;
}

/**
 * The composer's emoji button, or nothing when Flamoji is absent.
 *
 * @param onInsert Receives the chosen emoji — a shortcode for a custom one, the
 *   literal glyph for a standard one, per the admin's `picker_set`.
 * @param disabled Mirrors the other tools while a send is in flight.
 */
export function flamojiPickerButton(
  onInsert: (text: string) => void,
  disabled = false,
): Mithril.Children {
  const Button = pickerButton();

  if (!Button) return null;

  return m(Button, {
    // Flamoji reads `composer.editor` only from its own `insertText`, which the
    // subclass replaces, so there is no composer state to hand over.
    composer: null,
    onInsert,
    disabled,
    title: app.translator.trans("ramon-chat.forum.composer.emoji", {}, true),
  });
}
