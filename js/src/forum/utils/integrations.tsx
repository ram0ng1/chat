import type Mithril from "mithril";
import type User from "flarum/common/models/User";

/**
 * Components borrowed from sibling extensions, resolved at runtime.
 *
 * Every one of these is optional. A static `import … from 'ext:ramon-verified/…'`
 * would make that extension a hard requirement of this bundle: with it absent or
 * disabled the module would fail to resolve and the whole chat frontend would fail
 * to boot. Reading Flarum's export registry means the feature simply does not
 * appear, which is the correct behaviour for an integration.
 *
 * @see utils/stickers.tsx — the same approach for ramon/stickers.
 */

function fromRegistry(extension: string, path: string): any {
  const registry = (window as any)?.flarum?.reg;

  if (!registry) return null;

  try {
    // `checkModule`, not `get`: the latter is for modules you know are present.
    // It logs "No module found" on every miss and throws outright when
    // `flarum.debug` is on — so using it for an optional integration means a
    // console warning per render for anyone without the extension installed.
    const module =
      typeof registry.checkModule === "function"
        ? registry.checkModule(extension, path)
        : registry.get?.(extension, path);

    if (!module) return null;

    return module.default ?? module ?? null;
  } catch {
    return null;
  }
}

/**
 * The verified badge, drawn beside an author's name.
 *
 * Placed where ramon/verified places it on a post — in the header, right after the
 * name — so a verified member is marked the same way wherever they are talking.
 * Returns null when the extension is absent, when the actor is not verified, or
 * when the message has no author at all (the chat's bot posts as nobody).
 */
// `hasOne` returns `false` when the relationship was never loaded, so the caller's
// `message.user()` is `User | null | false` — accepting that here keeps the check
// in one place instead of at every call site.
export function verifiedBadge(
  user: User | null | undefined | false,
): Mithril.Children {
  if (!user) return null;

  // The attribute is added by ramon/verified's own model extender, so an install
  // without it has no such method rather than a false value.
  const isVerified = (user as any).isVerified;

  if (typeof isVerified !== "function" || !isVerified.call(user)) return null;

  const Badge = fromRegistry(
    "ramon-verified",
    "common/components/VerifiedBadge",
  );

  if (!Badge) return null;

  return m(Badge, { user, className: "VerifiedBadge--chat" });
}
