import type Message from "../models/Message";
/**
 * A one-line, plain-text summary of a message.
 *
 * Used by every place that shows a message inside something else: the pinned bar,
 * the reply context above the composer, the quoted line on a reply, the thread
 * indicator, search results and the bookmark list.
 *
 * All of them used `content()`, which is the *source*. A bot announcement is
 * written in Markdown, so the pinned bar read `**[an test](/d/2943)**` — the
 * markup, not the message. Reading from the rendered HTML instead means a link
 * shows its label and emphasis shows its words, which is what a preview is for.
 *
 * Plain text rather than the HTML itself, deliberately: these are single-line
 * strips, and dropping block elements into them would break the layout. It also
 * keeps them safe by construction — nothing here is ever passed to `m.trust`.
 */
export declare function messagePreview(message: Message | null | undefined, limit?: number): string;
export default messagePreview;
