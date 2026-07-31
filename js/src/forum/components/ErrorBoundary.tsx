import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
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
  private failed = false;

  view(vnode: Mithril.Vnode<ErrorBoundaryAttrs>): Mithril.Children {
    if (this.failed) {
      return (
        <div className="ChatErrorBoundary">
          <i className="fas fa-triangle-exclamation" aria-hidden="true" />
          <span>{app.translator.trans("ramon-chat.forum.error.render")}</span>

          <Button
            className="Button Button--flat"
            icon="fas fa-rotate"
            onclick={() => {
              this.failed = false;
              m.redraw();
            }}
          >
            {app.translator.trans("ramon-chat.forum.error.retry")}
          </Button>
        </div>
      );
    }

    try {
      return vnode.children;
    } catch (e) {
      this.failed = true;

      // Logged rather than swallowed. A boundary that hides the cause turns a
      // reproducible bug into "the chat sometimes shows an error".
      console.error(
        `[ramon-chat] render failed in ${this.attrs.area ?? "chat"}:`,
        e,
      );

      return null;
    }
  }
}
