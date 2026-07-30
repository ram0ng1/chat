import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import humanTime from 'flarum/common/helpers/humanTime';
import username from 'flarum/common/helpers/username';
import type Mithril from 'mithril';

import type Message from '../../common/models/Message';
import type Upload from '../../common/models/Upload';

export interface ImageLightboxAttrs extends ComponentAttrs {
  /** Every image in the message, so the viewer can move between them. */
  uploads: Upload[];
  /** Which one was clicked. */
  index: number;
  message: Message;
  onClose: () => void;
}

/**
 * Full-screen image viewer, opened by clicking an image in the stream.
 *
 * A plain `target="_blank"` link used to be the whole feature, which threw the
 * reader out of the conversation to look at a picture and made them find their
 * way back. This keeps them where they are.
 *
 * Deliberately not a Flarum `Modal`: the modal manager centres a white dialog
 * with a title bar and a close button, and constrains its width. What an image
 * viewer wants is the opposite — the picture as large as the viewport allows, on
 * a dark backdrop, with the chrome out of the way.
 */
export default class ImageLightbox extends Component<ImageLightboxAttrs> {
  private index = 0;
  private keyListener?: (e: KeyboardEvent) => void;

  oninit(vnode: Mithril.Vnode<ImageLightboxAttrs>): void {
    super.oninit(vnode);

    this.index = this.attrs.index;
  }

  oncreate(vnode: Mithril.VnodeDOM<ImageLightboxAttrs>): void {
    super.oncreate(vnode);

    this.keyListener = (e: KeyboardEvent) => this.onKey(e);
    document.addEventListener('keydown', this.keyListener);

    // The page behind must not scroll while the viewer is open, or a scroll
    // gesture aimed at the image moves the conversation instead.
    document.body.style.overflow = 'hidden';

    (vnode.dom as HTMLElement).focus();
  }

  onremove(): void {
    if (this.keyListener) document.removeEventListener('keydown', this.keyListener);

    document.body.style.overflow = '';
  }

  view(): Mithril.Children {
    const { uploads, message, onClose } = this.attrs;
    const upload = uploads[this.index];

    if (!upload) return null;

    const at = message.createdAt();

    return (
      <div
        className="ChatLightbox"
        tabindex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={upload.fileName() ?? ''}
        onclick={(e: Event) => {
          // Only the backdrop closes. Clicking the picture itself is what someone
          // does to look at it more closely, not to dismiss it.
          if (e.target === e.currentTarget) onClose();
        }}
      >
        <div className="ChatLightbox-bar">
          <div className="ChatLightbox-meta">
            <span className="ChatLightbox-author">{username(message.user())}</span>
            {at ? <span className="ChatLightbox-time">{humanTime(at)}</span> : null}
            <span className="ChatLightbox-name">{upload.fileName()}</span>
          </div>

          <div className="ChatLightbox-actions">
            {uploads.length > 1 ? (
              <span className="ChatLightbox-count">
                {this.index + 1} / {uploads.length}
              </span>
            ) : null}

            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-arrow-up-right-from-square"
              title={app.translator.trans('ramon-chat.forum.lightbox.open_original', {}, true)}
              onclick={() => window.open(upload.url() ?? '', '_blank', 'noopener,noreferrer')}
            />

            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-xmark"
              title={app.translator.trans('ramon-chat.forum.lightbox.close', {}, true)}
              onclick={onClose}
            />
          </div>
        </div>

        {uploads.length > 1 ? (
          <Button
            className="Button Button--icon Button--flat ChatLightbox-nav ChatLightbox-nav--prev"
            icon="fas fa-chevron-left"
            title={app.translator.trans('ramon-chat.forum.lightbox.previous', {}, true)}
            onclick={(e: Event) => {
              e.stopPropagation();
              this.step(-1);
            }}
          />
        ) : null}

        <img
          className="ChatLightbox-image"
          src={upload.url() ?? ''}
          alt={upload.fileName() ?? ''}
          onclick={(e: Event) => e.stopPropagation()}
        />

        {uploads.length > 1 ? (
          <Button
            className="Button Button--icon Button--flat ChatLightbox-nav ChatLightbox-nav--next"
            icon="fas fa-chevron-right"
            title={app.translator.trans('ramon-chat.forum.lightbox.next', {}, true)}
            onclick={(e: Event) => {
              e.stopPropagation();
              this.step(1);
            }}
          />
        ) : null}
      </div>
    );
  }

  protected onKey(e: KeyboardEvent): void {
    if (e.key === 'Escape') {
      this.attrs.onClose();

      return;
    }

    if (e.key === 'ArrowLeft') this.step(-1);
    if (e.key === 'ArrowRight') this.step(1);
  }

  /** Wraps, so the arrows never dead-end on the first or last image. */
  protected step(by: number): void {
    const total = this.attrs.uploads.length;

    if (total < 2) return;

    this.index = (this.index + by + total) % total;
    m.redraw();
  }
}
