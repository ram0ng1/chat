/**
 * Attributes for a button that shows only an icon.
 *
 * The same text doubles as the hover tooltip and as the accessible name:
 * `title` alone is not read as a name by assistive technology, so core's
 * Button warns about it, and a screen reader announces a bare "Button".
 */
export default function iconLabel(text: string): {
  title: string;
  "aria-label": string;
} {
  return { title: text, "aria-label": text };
}
