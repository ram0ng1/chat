import type Mithril from "mithril";
import type User from "flarum/common/models/User";
/**
 * The verified badge, drawn beside an author's name.
 *
 * Placed where ramon/verified places it on a post — in the header, right after the
 * name — so a verified member is marked the same way wherever they are talking.
 * Returns null when the extension is absent, when the actor is not verified, or
 * when the message has no author at all (the chat's bot posts as nobody).
 */
export declare function verifiedBadge(user: User | null | undefined | false): Mithril.Children;
