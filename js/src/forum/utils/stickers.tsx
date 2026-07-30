import app from 'flarum/forum/app';
import type Mithril from 'mithril';

import StickerPicker from '../components/StickerPicker';

/**
 * Optional integration with ramon/stickers.
 *
 * Nothing here is imported from that extension: the chat reads its data through
 * the API and draws its own panel. A static `import … from 'ext:ramon-stickers/…'`
 * would make it a hard dependency, and the whole chat frontend would fail to boot
 * without it.
 *
 * The rest of the integration needs nothing at all — chat content runs through
 * core's shared formatter, which is where stickers registers its replacements,
 * and its MutationObserver hydrates animated stickers wherever they land.
 */

/**
 * Whether ramon/stickers is installed and has data to offer.
 *
 * Read from the store's model registry, not from the export registry. Their
 * `StickerPicker` component is `require`d lazily inside their own open handler,
 * so it registers itself only *after* somebody has opened it from a discussion
 * composer — until then a registry lookup correctly reports it as absent, and the
 * chat's button never appeared. Nothing here can force that module to load.
 *
 * The model, by contrast, is registered in their initializer, which runs at boot.
 */
/** The namespace ramon/stickers registers its modules under. */
const EXTENSION = 'ramon-stickers';

export function stickersAvailable(): boolean {
  // Two conditions, and both matter: the extension has to be installed, and this
  // actor has to be allowed to send stickers. Drawing the button for someone the
  // server would refuse is the same mistake the paperclip made before it was
  // gated on `ramon-chat.upload`.
  if (! app.forum.attribute<boolean>('canSendChatStickers')) return false;

  return Boolean((app.store as any)?.models?.stickers);
}

/**
 * Opens the picker over the page, inserting the chosen shortcode.
 *
 * Mounted on a node appended to the body: the composer clips its overflow, and a
 * panel rendered inside it would be cut off at the first row.
 *
 * @param trigger The button that opened it, used to position the panel.
 * @param onInsert Receives the shortcode, e.g. `:wave:`.
 */
export function openStickerPicker(trigger: HTMLElement | null, onInsert: (text: string) => void): void {
  close();

  const mount = document.createElement('div');
  mount.className = 'ChatStickerPicker-wrapper';
  document.body.appendChild(mount);

  openMount = mount;

  if (trigger) {
    const box = trigger.getBoundingClientRect();

    // Above the button, right-aligned to it, which is where there is room in a
    // composer pinned to the bottom of the panel.
    mount.style.position = 'fixed';
    mount.style.bottom = `${window.innerHeight - box.top + 8}px`;
    mount.style.right = `${Math.max(8, window.innerWidth - box.right)}px`;
  }

  m.mount(mount, {
    view: (): Mithril.Children =>
      m(StickerPicker, {
        onInsert: (text: string) => {
          onInsert(text);
          close();
        },
        onClose: () => close(),
      }),
  });
}

/** At most one at a time, so a second click closes rather than stacking. */
let openMount: HTMLElement | null = null;

export function close(): void {
  if (!openMount) return;

  m.mount(openMount, null);
  openMount.remove();
  openMount = null;
}

/**
 * Hydrates the animated stickers inside a node, paused until hovered.
 *
 * The extension's own MutationObserver would do this eventually, but with the
 * options its admin setting dictates — and with `hover_play` off that means every
 * sticker in the grid animating at once: a wall of motion, and a lot of work for
 * one panel.
 *
 * A grid is not a message. In the stream the setting governs and this is not
 * called; here it is forced, because a picker exists to be scanned.
 *
 * Their renderers skip nodes already marked as initialised, so running first
 * means the observer leaves these alone rather than re-hydrating them without the
 * option. Unlike their picker component, these two modules are registered at
 * boot, so they are actually resolvable.
 */
export function playOnHover(node: HTMLElement): void {
  const registry = (window as any)?.flarum?.reg;

  if (!registry) return;

  const renderers: Array<[string, string]> = [
    ['common/utils/renderLottie', 'initLottieInRoot'],
    ['common/utils/renderTgs', 'initTgsInRoot'],
  ];

  for (const [path, fn] of renderers) {
    try {
      const module =
        typeof registry.checkModule === 'function'
          ? registry.checkModule(EXTENSION, path)
          : registry.get?.(EXTENSION, path);

      module?.[fn]?.(node, { hoverPlay: true });
    } catch {
      // An absent or changed renderer is not worth breaking the panel over; the
      // stickers just show their first frame.
    }
  }
}

export function isStickerPickerOpen(): boolean {
  return openMount !== null;
}

/**
 * The same shape stickers draws on the discussion composer.
 *
 * An inline SVG rather than a Font Awesome class, because that is what the other
 * button is — and because the class this first used, `far fa-face-smile-beam`,
 * belongs to a style the forum does not necessarily bundle. The button rendered;
 * its glyph was simply blank, which looks identical to the feature being missing.
 *
 * The path is duplicated rather than imported: importing it would make stickers a
 * build-time dependency, which is the one thing this module exists to avoid.
 */
const STICKER_ICON_PATH =
  'M20,11.5 L20,7.5 C20,5.56700338 18.4329966,4 16.5,4 L7.5,4 C5.56700338,4 4,5.56700338 4,7.5 ' +
  'L4,16.5 C4,18.4329966 5.56700338,20 7.5,20 L12.5,20 C13.3284271,20 14,19.3284271 14,18.5 ' +
  'L14,16.5 C14,14.5670034 15.5670034,13 17.5,13 L18.5,13 C19.3284271,13 20,12.3284271 20,11.5 Z ' +
  'M19.9266247,13.5532532 C19.522053,13.8348821 19.0303092,14 18.5,14 L17.5,14 ' +
  'C16.1192881,14 15,15.1192881 15,16.5 L15,18.5 C15,18.9222858 14.8952995,19.3201175 14.7104416,19.668952 ' +
  'C17.4490113,18.8255402 19.5186665,16.4560464 19.9266247,13.5532532 Z';

export function stickerIcon(): Mithril.Children {
  return m(
    'svg',
    {
      fill: 'currentColor',
      viewBox: '0 0 24 24',
      xmlns: 'http://www.w3.org/2000/svg',
      // 18px, not 16: this shape is a rounded square with a lot of empty margin
      // inside its 24-unit viewBox, so at the paperclip's size it reads smaller
      // than the paperclip does.
      style: { display: 'block', width: '18px', height: '18px' },
      'aria-hidden': 'true',
    },
    m('path', { d: STICKER_ICON_PATH })
  );
}

/** Only meaningful while the extension is present. */
export function stickerLabel(): string {
  return app.translator.trans('ramon-chat.forum.composer.sticker', {}, true);
}
