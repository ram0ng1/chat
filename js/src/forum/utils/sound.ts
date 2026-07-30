import app from "flarum/forum/app";

/**
 * The notification sound.
 *
 * Two decisions worth stating, because both are about not being obnoxious:
 *
 *  - Nothing plays while the message's own channel is on screen and focused. If
 *    you are looking at the conversation, you already saw it arrive; a chime for a
 *    message you are watching land is noise.
 *  - Plays are rate-limited. A burst of five messages arriving together should be
 *    one sound, not five overlapping ones.
 *
 * The admin picks the file; the user can silence it entirely from their own
 * settings, and that preference wins.
 */

/** Minimum gap between two plays. */
const THROTTLE_MS = 2000;

let lastPlayed = 0;
let element: HTMLAudioElement | null = null;
let loadedFor: string | null = null;

/**
 * The file the admin chose, or null when sound is off — either forum-wide or for
 * this user.
 */
function chosen(): string | null {
  // The user's own switch takes precedence over the forum default. Absent means
  // "follow the forum", so only an explicit `false` silences it.
  if (app.session.user?.preferences()?.["ramon-chat.sound"] === false)
    return null;

  const setting = app.forum.attribute<string>("ramon-chat.notificationSound");

  if (!setting || setting === "none") return null;

  return setting;
}

/**
 * Resolves the published URL for a sound.
 *
 * The files live in the extension's `assets/` directory, which `flarum
 * assets:publish` copies to `assets/extensions/ramon-chat/`. Built from
 * `assetsBaseUrl` rather than hardcoded, so a forum serving assets from a CDN or a
 * subdirectory still finds them.
 */
function url(name: string): string {
  const base = app.forum.attribute<string>("assetsBaseUrl") ?? "/assets";

  return `${base}/extensions/ramon-chat/sounds/${name}.mp3`;
}

export function playNotificationSound(): void {
  const name = chosen();

  if (!name) return;

  const now = Date.now();

  if (now - lastPlayed < THROTTLE_MS) return;

  lastPlayed = now;

  try {
    // One element, reused. Creating an Audio per message leaks them, and the
    // browser will not garbage-collect one that is mid-play.
    if (!element || loadedFor !== name) {
      element = new Audio(url(name));
      element.preload = "auto";
      loadedFor = name;
    }

    element.currentTime = 0;

    // Autoplay is refused until the user has interacted with the page. That is a
    // browser policy, not an error worth surfacing — the sound simply does not
    // play until they have clicked something, which by then they have.
    void element.play().catch(() => {});
  } catch {
    // A malformed URL or a missing file must never break message delivery.
  }
}

/**
 * Plays the given sound once, ignoring the throttle and the user's preference.
 * For the admin preview, where the point *is* to hear it.
 */
export function previewSound(name: string): void {
  if (!name || name === "none") return;

  try {
    const audio = new Audio(url(name));

    void audio.play().catch(() => {});
  } catch {
    // As above.
  }
}
