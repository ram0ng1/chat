/**
 * Protocol allowlist for anything that reaches an `src`, `href` or `window.open`.
 *
 * Attachment URLs are built by the server from the chat disk, so in practice they
 * are already `https://…` or `/assets/…`. That is an argument for the guard being
 * cheap, not for leaving it out: `src` and `href` accept `javascript:`, the value
 * travels through a JSON:API payload on its way here, and "it cannot currently be
 * hostile" is a property of today's server rather than of this code.
 *
 * Anything that is not plainly http(s) or a root-relative path resolves to an
 * empty string — a broken image is a better outcome than an executable one.
 */
export function safeFileUrl(raw: string | null | undefined): string {
  const value = (raw ?? '').trim();

  if (value === '') return '';

  // Root-relative, which is what the local disk produces. Excludes `//host`,
  // protocol-relative and therefore able to point anywhere.
  if (value.startsWith('/') && !value.startsWith('//')) return value;

  try {
    const parsed = new URL(value, window.location.origin);

    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : '';
  } catch {
    // Unparseable is not a URL, whatever else it might be.
    return '';
  }
}
