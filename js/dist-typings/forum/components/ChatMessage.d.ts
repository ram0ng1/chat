import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Message from "../../common/models/Message";
import type ChatState from "../state/ChatState";
export interface ChatMessageAttrs extends ComponentAttrs {
    message: Message;
    /** The row above, used to decide grouping. */
    previous?: Message | null;
    state: ChatState;
    onReply?: (message: Message) => void;
    onEdit?: (message: Message) => void;
    onOpenThread?: (message: Message) => void;
    /**
     * Suppresses the "N replies" strip. Set inside the thread panel itself, where
     * the root message would otherwise offer to open the thread you are already
     * reading — and, with no handler wired, do nothing when clicked.
     */
    hideThreadIndicator?: boolean;
}
/**
 * One row in the message stream.
 *
 * Grouping, mention highlighting and tombstones are all decided from the model
 * rather than passed in, so a row rendered from a realtime push looks identical
 * to one rendered from a fetch.
 */
export default class ChatMessage extends Component<ChatMessageAttrs> {
    view(): Mithril.Children;
    /**
     * Deleted rows keep their place in the stream. Removing them would silently
     * reflow the conversation and make replies to them incoherent.
     */
    protected tombstone(message: Message): Mithril.Children;
    /**
     * The picture an announced discussion opened with, drawn at the head of the
     * card the way a link preview leads with a favicon.
     *
     * Only for bot announcements: an ordinary message showing its own attachments
     * already has the uploads row, and a second copy of the same image at the top
     * would be noise.
     *
     * The URL is filtered again here even though the server already accepted only
     * http(s). It lands in an `src`, the payload travels through JSON:API to get
     * here, and "the server checked" is a property of today's server.
     */
    protected announcementIcon(message: Message): Mithril.Children;
    /**
     * Removes the row for good.
     *
     * Offered on the tombstone rather than in the hover bar: a channel that has
     * collected tombstones is where this is wanted, and putting an irreversible
     * action next to "reply" and "react" is asking for a mis-click.
     *
     * The confirmation names what is lost, because "are you sure?" does not.
     */
    protected purgeButton(message: Message): Mithril.Children;
    protected purge(message: Message): Promise<void>;
    protected content(message: Message): Mithril.Children;
    /**
     * Renders the "edited" marker. `editedAt` can be absent on a row the socket
     * patched before the API answered, so the bare word is the fallback rather
     * than a marker that says "edited Invalid Date".
     */
    protected editedMark(message: Message): Mithril.Children;
    protected systemRow(message: Message): Mithril.Children;
    /**
     * Opens the image viewer over the page.
     *
     * Mounted on a node appended to the body rather than rendered inside the row:
     * the message stream is `overflow: auto`, and a full-screen overlay inside it
     * would be clipped to the scroller.
     */
    protected openLightbox(images: any[], index: number): Promise<void>;
    /**
     * The author's avatar with their group badges on it.
     *
     * `user.badges()` is core's own list — the same call `PostUser` makes — so a
     * group's icon, colour and tooltip come from wherever the admin set them, and
     * an extension that adds to `User.prototype.badges` shows up here for free
     * rather than having to know the chat exists.
     *
     * The wrapper is only added when there is something to draw: it exists to
     * position the badges over the avatar, and an empty one on every row would put
     * a positioning context in the gutter for nothing.
     */
    protected avatar(message: Message): Mithril.Children;
    protected shortTime(message: Message): Mithril.Children;
    /**
     * The preview of the replied-to message, above the body.
     *
     * Clicking it jumps to the original: that is what the quote already suggests,
     * and without it the reader has to scroll hunting for a 120-character excerpt.
     * When the original is outside the loaded window the click says so instead of
     * doing nothing, so the silence does not look like a broken button.
     *
     * The element is a `button` rather than the former `div`: only then is it
     * reachable by keyboard and announced as actionable. The author's name is still
     * a link to the profile, so a click on it is let through instead of becoming a
     * jump.
     */
    protected replyPreview(message: Message): Mithril.Children;
    protected reactions(message: Message): Mithril.Children;
    protected uploads(message: Message): Mithril.Children;
    protected threadIndicator(message: Message): Mithril.Children;
    protected actions(message: Message): Mithril.Children;
    protected react(message: Message, emoji: string): Promise<void>;
    protected bookmark(message: Message): Promise<void>;
    /**
     * Toggles the pin.
     *
     * Optimistic like the reaction toggle, and rolled back from the server's own
     * response rather than from a guess — a failed pin must not leave the row
     * claiming to be pinned to the one person who clicked.
     */
    protected pin(message: Message): Promise<void>;
    protected delete(message: Message): Promise<void>;
}
