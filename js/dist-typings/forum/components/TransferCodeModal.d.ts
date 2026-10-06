import FormModal from "flarum/common/components/FormModal";
import type { IFormModalAttrs } from "flarum/common/components/FormModal";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
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
    private code;
    oninit(vnode: Mithril.Vnode<TransferCodeModalAttrs>): void;
    className(): string;
    title(): Mithril.Children;
    content(): Mithril.Children;
    onready(): void;
    onsubmit(e: SubmitEvent): void;
}
