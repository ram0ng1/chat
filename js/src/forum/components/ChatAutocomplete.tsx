import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Avatar from "flarum/common/components/Avatar";
import classList from "flarum/common/utils/classList";
import type User from "flarum/common/models/User";
import type Mithril from "mithril";

/**
 * One suggestion. `insert` is what replaces the typed fragment, `label` is what
 * the row shows — they differ for an emoji, where the row shows the glyph and the
 * insert is the shortcode.
 */
export interface Suggestion {
  key: string;
  insert: string;
  label: string;
  hint?: string | null;
  user?: User | null;
  emoji?: string | null;
  icon?: string | null;
}

export interface ChatAutocompleteAttrs extends ComponentAttrs {
  suggestions: Suggestion[];
  activeIndex: number;
  onSelect: (suggestion: Suggestion) => void;
  onHover: (index: number) => void;
}

/**
 * The suggestion list above the composer.
 *
 * Deliberately presentational: which trigger opened it, what matched and how the
 * insertion is spliced into the textarea all live in ChatComposer, because they
 * depend on the caret. This only draws rows and reports clicks.
 */
export default class ChatAutocomplete extends Component<ChatAutocompleteAttrs> {
  onupdate(vnode: Mithril.VnodeDOM<ChatAutocompleteAttrs>): void {
    // `view()` returns null whenever there is nothing to suggest — which is most
    // of the time — and a component that rendered nothing has no `vnode.dom`.
    // Without this guard every redraw of the composer threw
    // "Cannot read properties of undefined (reading 'querySelector')".
    if (!vnode.dom) return;

    // Keep the keyboard-selected row in view when arrowing past the fold.
    const active = vnode.dom.querySelector(".ChatAutocomplete-item--active");

    (active as HTMLElement | null)?.scrollIntoView({ block: "nearest" });
  }

  view(): Mithril.Children {
    const { suggestions, activeIndex, onSelect, onHover } = this.attrs;

    if (suggestions.length === 0) return null;

    return (
      <div className="ChatAutocomplete" role="listbox">
        {suggestions.map((suggestion, index) => (
          <button
            key={suggestion.key}
            type="button"
            role="option"
            aria-selected={index === activeIndex}
            className={classList("ChatAutocomplete-item", {
              "ChatAutocomplete-item--active": index === activeIndex,
            })}
            // mousedown, not click: the textarea loses focus on mousedown and a
            // blur handler would have closed the list before click ever fired.
            onmousedown={(e: MouseEvent) => {
              e.preventDefault();
              onSelect(suggestion);
            }}
            onmouseenter={() => onHover(index)}
          >
            {suggestion.user ? (
              <Avatar user={suggestion.user} className="Avatar" />
            ) : null}
            {suggestion.emoji ? <span>{suggestion.emoji}</span> : null}
            {suggestion.icon ? (
              <i className={suggestion.icon} aria-hidden="true" />
            ) : null}

            <span>{suggestion.label}</span>

            {suggestion.hint ? (
              <span className="ChatAutocomplete-item-hint">
                {suggestion.hint}
              </span>
            ) : null}
          </button>
        ))}
      </div>
    );
  }
}
