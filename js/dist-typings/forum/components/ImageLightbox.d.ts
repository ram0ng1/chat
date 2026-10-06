import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Message from "../../common/models/Message";
import type Upload from "../../common/models/Upload";
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
    private index;
    private keyListener?;
    oninit(vnode: Mithril.Vnode<ImageLightboxAttrs>): void;
    oncreate(vnode: Mithril.VnodeDOM<ImageLightboxAttrs>): void;
    onremove(): void;
    view(): Mithril.Children;
    protected onKey(e: KeyboardEvent): void;
    /** Wraps, so the arrows never dead-end on the first or last image. */
    protected step(by: number): void;
}
