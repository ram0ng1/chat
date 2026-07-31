import ChatState from "./ChatState";

/**
 * The process-wide chat state.
 *
 * A singleton rather than per-component state so the drawer, the full-screen page
 * and the realtime handlers all observe the same channels, streams and read
 * markers. Two instances would drift the moment either one marked a message read.
 *
 * Bound to a named const before exporting. Flarum's `autoExportLoader` rewrites
 * the default export into a `flarum.reg.add()` call by scanning the source for
 * the default-export keyword, so the exported value has to be a plain identifier
 * — a constructor call in that position (and even the phrase appearing in a
 * comment) makes the loader emit invalid code.
 */
const chatState = new ChatState();

export default chatState;
