import Modal from "flarum/common/components/Modal";
import type { IInternalModalAttrs } from "flarum/common/components/Modal";
import type Mithril from "mithril";
export interface MessageTooLongModalAttrs extends IInternalModalAttrs {
    /** Length of what the sender tried to post. */
    length: number;
    /** The cap that applies in this channel. */
    max: number;
}
/**
 * Says the message is too long, and by exactly how much.
 *
 * A modal rather than an inline note because the send has just been refused:
 * the composer keeps the text, nothing was lost, and the reader needs to know
 * that before they retype it. The count is the actionable part — "shorten by
 * 412" is a instruction, "too long" is a complaint.
 *
 * The alternative this replaces was worse than either: `maxlength` on the
 * textarea, which let the browser silently drop the tail of anything pasted.
 */
export default class MessageTooLongModal extends Modal<MessageTooLongModalAttrs> {
    className(): string;
    title(): Mithril.Children;
    content(): Mithril.Children;
}
