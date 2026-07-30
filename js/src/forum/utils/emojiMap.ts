import * as source from "simple-emoji-map";

/**
 * The emoji map, in a module of our own.
 *
 * This wrapper exists solely so the lazy `import()` in emoji.ts targets a *local*
 * file. flarum-webpack-config's loader rewrites every dynamic import into one
 * carrying a `webpackChunkName` derived from the path resolved relative to the
 * importing file, and RegisterAsyncChunksPlugin then looks for a module at that
 * same path to register the chunk with `flarum.reg`.
 *
 * Importing the package by its bare name defeats both: the resolved path is
 * `src/forum/utils/simple-emoji-map`, which is not a module, so the plugin logged
 * "Could not find chunk" on every build and registered nothing. At runtime
 * `flarum.reg.loadChunk` then had no URL for the chunk, fell back to webpack's
 * default public path, and requested `/assets/forum/utils/simple-emoji-map.js` —
 * which 404s, leaving every shortcode outside the built-in set unresolved.
 *
 * With the import pointing here, the path resolves to a real file, the chunk is
 * registered, and the package still ends up in the lazy chunk rather than the main
 * bundle — this module is only ever reached through that dynamic import.
 */
const map = ((source as any).default ?? source) as Record<string, string[]>;

export default map;
