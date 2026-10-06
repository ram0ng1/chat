import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
export interface ErrorBoundaryAttrs extends ComponentAttrs {
    /** Named in the console so a report says which part failed. */
    area?: string;
}
/**
 * Keeps one broken component from taking the page with it.
 *
 * Mithril has no `componentDidCatch`: an exception thrown from any `view()`
 * escapes the whole render, and every node the framework was in the middle of
 * patching is left half-attached. That is what a `Cannot read properties of
 * undefined` in a sidebar button looked like — a cascade of `onbeforeupdate`,
 * `onbeforeremove` and `removeChild` errors as Mithril tried to walk a tree that
 * no longer matched the DOM, and a forum that stopped responding to navigation.
 *
 * The chat is a large subtree mounted over the rest of the forum. A bug in it
 * should cost the chat, not the page it is drawn on.
 *
 * This catches render errors only — not those from event handlers or from an
 * async callback, which do not pass through `view()`. Those still need their own
 * handling where they happen.
 */
export default class ErrorBoundary extends Component<ErrorBoundaryAttrs> {
    private failed;
    view(vnode: Mithril.Vnode<ErrorBoundaryAttrs>): Mithril.Children;
}
