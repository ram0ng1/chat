import app from 'flarum/forum/app';

/**
 * Admin-configurable label and icon for the chat.
 *
 * Both the header button and the drawer header read from here, so renaming the
 * feature (to "Lounge", "Radio", whatever) or swapping its icon is one setting
 * rather than a translation override plus a CSS hack.
 */

/** The chat's display name. Falls back to the translated default when unset. */
export function chatTitle(): string {
  const custom = (app.forum.attribute<string>('ramon-chat.title') ?? '').trim();

  if (custom !== '') return custom;

  return app.translator.trans('ramon-chat.forum.nav.chat', {}, true);
}

/**
 * The Font Awesome class for the chat icon, or null when the admin has turned the
 * icon off.
 *
 * Returning null rather than an empty string keeps the call sites honest: they
 * have to decide what to render without an icon, instead of emitting an `<i>` with
 * no class that collapses to a stray gap.
 */
export function chatIcon(): string | null {
  if (app.forum.attribute<boolean>('ramon-chat.showIcon') === false) return null;

  const icon = (app.forum.attribute<string>('ramon-chat.icon') ?? '').trim();

  return icon === '' ? 'fas fa-comments' : icon;
}
