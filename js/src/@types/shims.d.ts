/**
 * `simple-emoji-map` ships no typings. The same shim flarum/emoji uses.
 *
 * The module's shape (unicode → shortcode names) is asserted at the point of use
 * in `forum/utils/emoji.ts`, which also guards against a malformed entry.
 */
declare module "simple-emoji-map";
