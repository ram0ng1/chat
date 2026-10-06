import app from "flarum/forum/app";
import FormModal from "flarum/common/components/FormModal";
import type { IFormModalAttrs } from "flarum/common/components/FormModal";
import Button from "flarum/common/components/Button";
import Stream from "flarum/common/utils/Stream";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import { confirmTransfer } from "../utils/transfers";
import { invitationErrorText } from "../utils/invitations";

export interface TransferCodeModalAttrs extends IFormModalAttrs {
  channel: Channel;
  /** Display name of the member the channel is being offered to. */
  recipientName: string;
  /** Called with the channel as the server answered once the code is accepted. */
  onConfirmed?: (channel: Channel | null) => void;
}

/**
 * The second step of handing a channel over: the owner types the 6-digit code
 * mailed to them.
 *
 * The code is held only in this field until it is posted once; a wrong one
 * leaves the dialog open with the server's answer (how many attempts remain),
 * and the fifth wrong one ends the transfer on the server.
 *
 * Extends FormModal so Enter submits.
 */
export default class TransferCodeModal extends FormModal<TransferCodeModalAttrs> {
  private code!: Stream<string>;

  oninit(vnode: Mithril.Vnode<TransferCodeModalAttrs>): void {
    super.oninit(vnode);

    this.code = Stream("");
  }

  className(): string {
    return "ChatModal ChatTransferCodeModal Modal--small";
  }

  title(): Mithril.Children {
    return app.translator.trans("ramon-chat.forum.info.transfer_title");
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body">
        <div className="Form">
          <p className="helpText">
            {app.translator.trans("ramon-chat.forum.info.transfer_intro", {
              username: this.attrs.recipientName,
            })}
          </p>

          <div className="Form-group">
            <label for="chat-transfer-code">
              {app.translator.trans(
                "ramon-chat.forum.info.transfer_code_label",
              )}
            </label>

            <input
              id="chat-transfer-code"
              className="FormControl ChatTransferCodeModal-code"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength={6}
              pattern="[0-9]{6}"
              disabled={this.loading}
              value={this.code()}
              oninput={(e: Event) =>
                this.code(
                  (e.target as HTMLInputElement).value
                    .replace(/\D+/g, "")
                    .slice(0, 6),
                )
              }
            />
          </div>

          <div className="Form-group">
            <Button
              className="Button Button--primary Button--block"
              type="submit"
              loading={this.loading}
              disabled={this.code().length !== 6}
            >
              {app.translator.trans("ramon-chat.forum.info.transfer_submit")}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onready(): void {
    (
      this.element.querySelector(
        "#chat-transfer-code",
      ) as HTMLInputElement | null
    )?.focus();
  }

  onsubmit(e: SubmitEvent): void {
    e.preventDefault();

    if (this.loading || this.code().length !== 6) return;

    this.loading = true;

    confirmTransfer(this.attrs.channel.id() as string, this.code())
      .then((channel) => {
        this.hide();

        app.alerts.show(
          { type: "success" },
          app.translator.trans("ramon-chat.forum.info.transfer_offered", {
            username: this.attrs.recipientName,
          }),
        );

        this.attrs.onConfirmed?.(channel);
      })
      .catch((error: any) => {
        this.loading = false;
        this.code("");

        app.alerts.show(
          { type: "error" },
          invitationErrorText(error, "ramon-chat.forum.info.transfer_failed"),
        );

        // The transfer may have ended on the server (fifth wrong code, or
        // expired); the caller re-reads the members tab either way.
        this.attrs.onConfirmed?.(null);

        m.redraw();
      });
  }
}
