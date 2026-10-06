import FormModal from "flarum/common/components/FormModal";
import type { IFormModalAttrs } from "flarum/common/components/FormModal";
import type Mithril from "mithril";
import type Message from "../../common/models/Message";
export interface FlagMessageModalAttrs extends IFormModalAttrs {
    message: Message;
}
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
    private reason;
    private detail;
    oninit(vnode: Mithril.Vnode<FlagMessageModalAttrs>): void;
    className(): string;
    title(): Mithril.Children;
    content(): Mithril.Children;
    /**
     * Nothing awaits this method, so a rejection here would surface as an unhandled
     * promise rejection rather than as feedback. Every failure path is handled inline.
     */
    onsubmit(e: SubmitEvent): void;
}
