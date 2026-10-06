import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
/**
 * Fetches the drawer panel, which lives in the lazy interface chunk. Called on
 * hover of the header button, on open, and when restoring a drawer left open;
 * concurrent requests collapse into one.
 */
export declare function loadDrawerPanel(): Promise<void>;
/**
 * The drawer shell that stays in the initial bundle.
 *
 * Mounted once at the app root, as before, but without loading the conversation:
 * closed it draws nothing, and opened before the chunk arrives it shows the
 * drawer box with a spinner. The real panel (ChatDrawerPanel) takes over as soon
 * as the chunk resolves; the morph from the button goes to whichever appears
 * first.
 */
export default class ChatDrawer extends Component<ComponentAttrs> {
    /** Whether the placeholder box was on screen at the last render. */
    private placeholderShown;
    oncreate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void;
    onupdate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void;
    /**
     * While the chunk has not arrived, the placeholder box is what grows from the
     * button; it consumes the origin, and the panel that replaces it enters without
     * repeating the animation.
     */
    protected morphPlaceholder(dom: Element | null): void;
    view(): Mithril.Children;
    /**
     * Opens the drawer, loading the channel list on first open.
     *
     * The redraw comes before the load: waiting for the list left the drawer
     * invisible for a full round trip, which feels like a slow button. The sidebar
     * draws the skeleton in the meantime.
     */
    static open(): Promise<void>;
}
