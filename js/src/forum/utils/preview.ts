import type Message from '../../common/models/Message';

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
export function messagePreview(message: Message | null | undefined, limit = 200): string {
  if (!message) return '';

  const html = message.contentHtml();

  const text = html ? stripHtml(html) : stripMarkdown(message.content() ?? '');

  return text.length > limit ? `${text.slice(0, limit)}…` : text;
}

/**
 * Tags out, text in.
 *
 * Parsed rather than regex-stripped: the browser's own parser handles entities,
 * attributes containing `>`, and malformed markup correctly, and none of it is
 * inserted into the document — `textContent` never executes anything.
 */
function stripHtml(html: string): string {
  const doc = new DOMParser().parseFromString(html, 'text/html');

  // Block boundaries become spaces, or "**Title**\n\nBody" would read as
  // "TitleBody" with the two run together.
  doc.body.querySelectorAll('br, p, div, li, blockquote, h1, h2, h3, h4, h5, h6').forEach((el) => {
    el.append(' ');
  });

  return collapse(doc.body.textContent ?? '');
}

/**
 * The fallback, for a message whose HTML has not arrived yet — an optimistic
 * local copy, or a realtime payload that omitted it.
 *
 * Only the constructs that would otherwise show as punctuation: link syntax,
 * emphasis, headings, quote markers.
 */
function stripMarkdown(source: string): string {
  let text = source;

  text = text.replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1');
  text = text.replace(/^[ \t]*>[ \t]?/gm, '');
  text = text.replace(/^#{1,6}[ \t]*/gm, '');
  text = text.replace(/[*_~`]+/g, '');

  return collapse(text);
}

function collapse(text: string): string {
  return text.replace(/\s+/g, ' ').trim();
}

export default messagePreview;
