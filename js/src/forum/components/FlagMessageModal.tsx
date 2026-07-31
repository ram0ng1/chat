import app from "flarum/forum/app";
import FormModal from "flarum/common/components/FormModal";
import type { IFormModalAttrs } from "flarum/common/components/FormModal";
import Button from "flarum/common/components/Button";
import Stream from "flarum/common/utils/Stream";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Message from "../../common/models/Message";
import { messagePreview } from "../../common/utils/preview";
import { authorName } from "../utils/bot";

export interface FlagMessageModalAttrs extends IFormModalAttrs {
  message: Message;
}

/**
 * The reasons the server accepts, in the order they are offered.
 *
 * Mirrors `MessageFlag::REASONS`. A reason the server would reject must never
 * appear here — the request would fail with a validation error the reporter
 * cannot act on.
 *
 * `other` is last and is the one that requires an explanation, because the reason
 * alone says nothing to whoever picks the report up.
 */
const REASONS = [
  "spam",
  "inappropriate",
  "harassment",
  "off_topic",
  "other",
] as const;

type Reason = (typeof REASONS)[number];

/**
 * Reports a message to the moderators.
 *
 * A modal rather than a one-click action: the report lands in a queue a person has
 * to read, and a reason picked deliberately is worth more to them than a bare
 * count. It is also the last chance to reconsider, which a single button is not.
 *
 * Extends FormModal, not Modal: `Modal.wrapper()` returns a bare fragment, so a
 * `type="submit"` button inside it has no form and onsubmit never fires.
 */
export default class FlagMessageModal extends FormModal<FlagMessageModalAttrs> {
  private reason!: Stream<Reason>;
  private detail!: Stream<string>;

  oninit(vnode: Mithril.Vnode<FlagMessageModalAttrs>): void {
    super.oninit(vnode);

    this.reason = Stream<Reason>("spam");
    this.detail = Stream("");
  }

  className(): string {
    return "ChatModal ChatFlagModal Modal--small";
  }

  title(): Mithril.Children {
    return app.translator.trans("ramon-chat.forum.flag.title");
  }

  content(): Mithril.Children {
    const message = this.attrs.message;

    return (
      <div className="Modal-body">
        <div className="Form">
          {/* The message being reported, as plain text. Rendering its HTML here
              would put the reported content — which may be exactly what is wrong
              with it — into the reporter's own dialog. */}
          <div className="ChatFlagModal-target">
            <div className="ChatFlagModal-target-author">
              {authorName(message)}
            </div>
            <div className="ChatFlagModal-target-excerpt">
              {messagePreview(message, 200)}
            </div>
          </div>

          <div className="Form-group">
            <label>
              {app.translator.trans("ramon-chat.forum.flag.reason")}
            </label>

            <div className="ChatFlagModal-reasons">
              {REASONS.map((reason) => (
                <button
                  key={reason}
                  type="button"
                  className={classList("ChatFlagModal-reason", {
                    "ChatFlagModal-reason--active": this.reason() === reason,
                  })}
                  disabled={this.loading}
                  onclick={() => this.reason(reason)}
                >
                  {app.translator.trans(
                    `ramon-chat.forum.flag.reasons.${reason}`,
                  )}
                </button>
              ))}
            </div>
          </div>

          <div className="Form-group">
            <label>
              {app.translator.trans(
                this.reason() === "other"
                  ? "ramon-chat.forum.flag.detail_required"
                  : "ramon-chat.forum.flag.detail",
              )}
            </label>

            <textarea
              className="FormControl"
              rows={3}
              maxlength={1000}
              bidi={this.detail}
              disabled={this.loading}
              placeholder={app.translator.trans(
                "ramon-chat.forum.flag.detail_placeholder",
                {},
                true,
              )}
            />
          </div>

          <div className="Form-group">
            <Button
              className="Button Button--primary Button--block"
              type="submit"
              loading={this.loading}
              disabled={
                this.reason() === "other" && this.detail().trim() === ""
              }
            >
              {app.translator.trans("ramon-chat.forum.flag.submit")}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  /**
   * Nothing awaits this method, so a rejection here would surface as an unhandled
   * promise rejection rather than as feedback. Every failure path is handled inline.
   */
  onsubmit(e: SubmitEvent): void {
    e.preventDefault();

    if (this.loading) return;

    const detail = this.detail().trim();

    // Checked here as well as on the button and on the server. The button being
    // disabled is a courtesy; a form submits on Enter regardless.
    if (this.reason() === "other" && detail === "") return;

    this.loading = true;

    app
      .request({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-message-flags`,
        body: {
          data: {
            attributes: {
              messageId: Number(this.attrs.message.id()),
              reason: this.reason(),
              detail: detail || null,
            },
          },
        },
      })
      .then(() => {
        // Locally, so the button reads "reported" without waiting for the next
        // fetch of the stream.
        this.attrs.message.pushAttributes({ isFlagged: true });

        this.hide();

        app.alerts.show(
          { type: "success" },
          app.translator.trans("ramon-chat.forum.flag.sent"),
        );
      })
      .catch((e: any) => {
        this.loading = false;

        app.alerts.show(
          { type: "error" },
          e?.response?.errors?.[0]?.detail ??
            app.translator.trans("ramon-chat.forum.flag.failed"),
        );

        m.redraw();
      });
  }
}
