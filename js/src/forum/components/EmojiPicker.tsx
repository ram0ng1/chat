import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import { searchEmoji, resolveEmoji, loadEmojiMap, emojiMapReady, type EmojiSuggestion } from '../utils/emoji';

export interface EmojiPickerAttrs extends ComponentAttrs {
  /** Current value: a shortcode or a Unicode emoji. */
  value: string | null;
  onchange: (value: string | null) => void;
  disabled?: boolean;
}

/**
 * A searchable emoji picker.
 *
 * The discussion composer's picker is an autocomplete triggered by typing `:`
 * mid-sentence, which is the right interaction for prose but wrong for a field
 * whose entire value is one emoji. Here the field *is* the search box: type to
 * filter, click or press Enter to choose.
 *
 * Stores Unicode rather than a shortcode. A stored `❤️` renders everywhere with no
 * map lookup and survives the extension being disabled, whereas a stored `heart`
 * is meaningless without this table.
 */
export default class EmojiPicker extends Component<EmojiPickerAttrs> {
  private open = false;
  private query = '';
  private highlighted = 0;

  oninit(vnode: Mithril.Vnode<EmojiPickerAttrs>): void {
    super.oninit(vnode);

    // Warm the chunk so the grid is populated by the time it is opened.
    loadEmojiMap();
  }

  view(): Mithril.Children {
    const { value, disabled } = this.attrs;
    const selected = resolveEmoji(value);
    const results = this.open ? searchEmoji(this.query) : [];

    return (
      <div className={classList('EmojiPicker', { 'EmojiPicker--open': this.open })}>
        <div className="EmojiPicker-control">
          <span className="EmojiPicker-preview" aria-hidden="true">
            {selected ?? <i className="far fa-face-smile" />}
          </span>

          <input
            className="FormControl EmojiPicker-input"
            type="text"
            value={this.open ? this.query : (selected ?? '')}
            placeholder={app.translator.trans('ramon-chat.forum.emoji_picker.placeholder', {}, true)}
            disabled={disabled}
            onfocus={() => this.openPicker()}
            oninput={(e: Event) => this.onInput(e)}
            onkeydown={(e: KeyboardEvent) => this.onKeyDown(e)}
            aria-expanded={this.open ? 'true' : 'false'}
            aria-autocomplete="list"
          />

          {selected ? (
            <Button
              className="Button Button--icon Button--link EmojiPicker-clear"
              icon="fas fa-times"
              title={app.translator.trans('ramon-chat.forum.emoji_picker.clear', {}, true)}
              disabled={disabled}
              onclick={() => this.choose(null)}
            />
          ) : null}
        </div>

        {this.open ? (
          <div className="EmojiPicker-dropdown">
            {!emojiMapReady() && results.length === 0 ? (
              <LoadingIndicator display="inline" size="small" />
            ) : results.length === 0 ? (
              <div className="EmojiPicker-empty">
                {app.translator.trans('ramon-chat.forum.emoji_picker.empty')}
              </div>
            ) : (
              <div className="EmojiPicker-grid">
                {results.map((item, index) => (
                  <button
                    key={item.name}
                    type="button"
                    className={classList('EmojiPicker-option', {
                      'EmojiPicker-option--active': index === this.highlighted,
                    })}
                    title={`:${item.name}:`}
                    // mousedown, not click: the input's blur would close the
                    // dropdown before a click could land on it.
                    onmousedown={(e: Event) => {
                      e.preventDefault();
                      this.choose(item.unicode);
                    }}
                  >
                    {item.unicode}
                  </button>
                ))}
              </div>
            )}
          </div>
        ) : null}
      </div>
    );
  }

  oncreate(vnode: Mithril.VnodeDOM<EmojiPickerAttrs>): void {
    super.oncreate(vnode);

    // Close on any click outside. Bound on the document because the dropdown can
    // be dismissed by interacting with anything else in the modal.
    this.outsideHandler = (e: MouseEvent) => {
      if (!this.open) return;
      if (this.element.contains(e.target as Node)) return;

      this.open = false;
      m.redraw();
    };

    document.addEventListener('mousedown', this.outsideHandler);
  }

  onremove(vnode: Mithril.VnodeDOM<EmojiPickerAttrs>): void {
    super.onremove(vnode);

    if (this.outsideHandler) {
      document.removeEventListener('mousedown', this.outsideHandler);
    }
  }

  private outsideHandler?: (e: MouseEvent) => void;

  protected openPicker(): void {
    this.open = true;
    this.query = '';
    this.highlighted = 0;
    m.redraw();
  }

  protected onInput(e: Event): void {
    this.query = (e.target as HTMLInputElement).value;
    this.open = true;
    this.highlighted = 0;
    m.redraw();
  }

  protected onKeyDown(e: KeyboardEvent): void {
    const results = searchEmoji(this.query);

    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        this.highlighted = Math.min(this.highlighted + 1, results.length - 1);
        break;

      case 'ArrowUp':
        e.preventDefault();
        this.highlighted = Math.max(this.highlighted - 1, 0);
        break;

      case 'Enter': {
        e.preventDefault();

        // Enter with an exact shortcode typed and nothing highlighted should still
        // resolve — the user may have pasted `:tada:` and pressed Enter.
        const pick = results[this.highlighted] ?? null;
        this.choose(pick ? pick.unicode : resolveEmoji(this.query));
        break;
      }

      case 'Escape':
        e.preventDefault();
        this.open = false;
        break;

      default:
        return;
    }

    m.redraw();
  }

  protected choose(value: string | null): void {
    this.open = false;
    this.query = '';
    this.attrs.onchange(value);
    m.redraw();
  }
}
