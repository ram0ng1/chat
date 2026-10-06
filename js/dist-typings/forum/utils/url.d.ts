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
export declare function safeFileUrl(raw: string | null | undefined): string;
