import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
import type ChatState from "../state/ChatState";
import type { Suggestion } from "./ChatAutocomplete";
export interface ChatComposerAttrs extends ComponentAttrs {
    channel: Channel;
    state: ChatState;
    threadId?: number | null;
    /** Called after a successful send, so the stream can scroll to the bottom. */
    onSent?: () => void;
}
export default class ChatComposer extends Component<ChatComposerAttrs> {
    private textarea;
    private joining;
    private unarchiving;
    private sending;
    /** Seconds left before this channel will accept another message from us. */
    private cooldown;
    private cooldownTimer;
    /** Last `slowModeRemaining` seen, so a change can be told from a redraw. */
    private lastServerCooldown;
    /** Last `slowModeSeconds` seen. -1 so the first sync always runs. */
    private lastSlowModeWindow;
    private uploading;
    /** Id of the reply/edit target the cursor was last moved for. */
    private focusedContext;
    private suggestions;
    private activeSuggestion;
    /** The `@foo` / `:smi` fragment being completed, as [start, end) in the value. */
    private trigger;
    private mentionTimer;
    /** Discards a slower earlier user search whose results arrived out of order. */
    private mentionSequence;
    onremove(): void;
    oninit(vnode: Mithril.Vnode<ChatComposerAttrs>): void;
    /**
     * Reconciles the local countdown with the channel's slow-mode rule.
     *
     * Both directions matter, and they are not symmetrical.
     *
     * Turning slow mode *on* is the server's word to adopt: `slowModeRemaining`
     * says how long this actor has left, and an already-open composer has to pick
     * it up or the new rule binds them only after they navigate away and back.
     * It is read on a *change* rather than every draw — it is a snapshot from the
     * last channel read, not a ticking value, so comparing it each time would
     * restart the countdown from the same stale number and it would never reach
     * zero.
     *
     * Turning slow mode *off* has to release whoever is mid-wait. The local
     * countdown exists only because of the window it was started from, so it
     * cannot outlive that window: with no window there is nothing left to wait
     * for, and a narrowed one caps what is left. This is the half that was
     * missing — a "never shorten" guard meant to protect the local countdown from
     * a stale snapshot also kept enforcing a rule that had just been withdrawn.
     *
     * Narrowing is capped rather than recomputed: the broadcast moves the window
     * immediately while the per-actor refetch is still in flight, so `remaining`
     * is briefly stale. Capping at the new window is never more permissive than
     * the server, and the next send resyncs it exactly.
     */
    protected syncSlowMode(): void;
    oncreate(vnode: Mithril.VnodeDOM<ChatComposerAttrs>): void;
    onupdate(vnode: Mithril.VnodeDOM<ChatComposerAttrs>): void;
    /**
     * Puts the cursor in the input when a reply or edit is staged.
     *
     * Handled here rather than at each call site because a reply can be started from
     * three places — the channel's message row, the thread panel, and arrow-up — and
     * only the composer knows where its own textarea is. Clicking Reply and then
     * having to click the box before typing is a small thing that happens on every
     * single reply.
     *
     * Fires only on a *change* of target: `onupdate` runs on every redraw, including
     * one per incoming message, and focusing on each of those would seize the cursor
     * from someone reading, or scroll a phone's keyboard open unprompted.
     */
    protected focusOnNewContext(): void;
    view(): Mithril.Children;
    /**
     * Why you cannot type here.
     *
     * The reasons are checked most-specific first, because they are not equivalent:
     * "this channel is closed" told someone in a perfectly open announcement channel
     * the wrong thing entirely, and left them with no idea that reading was all that
     * was ever on offer.
     */
    /**
     * What stands in for the composer during a slow-mode wait.
     *
     * Shaped like `frozen()` because it means the same thing to the reader — the
     * channel is not taking a message from you right now — and an hourglass rather
     * than a padlock because nothing is locked and nothing went wrong: the wait
     * ends on its own, and the count says when.
     */
    protected slowed(): Mithril.Children;
    protected frozen(channel: Channel): Mithril.Children;
    /**
     * The archived notice: where the transcript went, and the way back out of
     * the archive for whoever may take it.
     */
    protected archivedNotice(channel: Channel): Mithril.Children;
    /**
     * Takes the channel out of the archive. It comes back closed, so the notice
     * turns into the closed one and the composer stays away until the channel
     * is reopened.
     */
    protected unarchive(channel: Channel): Promise<void>;
    /**
     * Joins the channel so the composer can appear.
     *
     * The join answers with the channel itself, so `canPostMessage` is the
     * server's answer rather than an assumption: a join that succeeded for a
     * channel that has since been closed still leaves the composer hidden, and
     * one that succeeded for an open channel draws it without a second request.
     */
    protected join(channel: Channel): Promise<void>;
    /**
     * Answers the invitation the bar above is showing.
     *
     * Accepting is a join that goes through the invite route, and lands the same
     * way: the returned record carries `canPostMessage`, so the composer takes
     * the bar's place on the next draw.
     */
    protected answerInvitation(channel: Channel, accept: boolean): Promise<void>;
    /**
     * This composer's own reply/edit target.
     *
     * The channel and an open thread each render a composer over the same state, so
     * the context is looked up by scope — a thread edit must not put the channel
     * composer into edit mode.
     */
    protected replyingTo(): import("..").Message | null;
    /** Whether the staged reply was started as "reply in thread". */
    protected branching(): boolean;
    protected editing(): import("..").Message | null;
    protected placeholder(channel: Channel): string;
    /** The send button's tooltip, naming the keystroke that actually sends. */
    protected sendHint(): string;
    /** Reply / edit context strip above the input. */
    protected contextBar(): Mithril.Children;
    protected pendingAttachments(): Mithril.Children;
    protected onInput(e: Event): void;
    /**
     * Looks for an `@name` or `:emoji` fragment immediately before the caret.
     *
     * Anchored on a word boundary so an email address or a `http://` does not open
     * the list, and closed as soon as the fragment stops matching — the trigger has
     * to disappear on its own rather than needing an explicit dismissal.
     */
    protected detectTrigger(el: HTMLTextAreaElement): void;
    protected suggestUsers(term: string): void;
    protected applySuggestion(suggestion: Suggestion): void;
    protected closeAutocomplete(): void;
    /** Whether the list is open and should own the arrow keys, Enter and Escape. */
    protected autocompleteOpen(): boolean;
    protected onKeyDown(e: KeyboardEvent): void;
    /** Grows the textarea with its content, up to the CSS max-height. */
    protected resize(): void;
    protected startEditing(message: any): void;
    protected cancelContext(): void;
    /**
     * Opens the sticker picker, or closes it if this button opened it.
     *
     * The chosen shortcode goes in at the caret like any other typed text, so it is
     * still editable and still part of the draft — the message is only rendered
     * into a sticker by the formatter, on send.
     */
    protected toggleStickers(e: Event): void;
    /**
     * Inserts text where the caret is, rather than appending.
     *
     * The composer keeps its value in the draft rather than in the DOM, so both
     * have to be updated: the textarea for the caret position the user can see, and
     * the draft for what is actually sent.
     */
    protected insertAtCursor(text: string): void;
    /**
     * Starts (or refreshes) the slow-mode countdown.
     *
     * Driven by a one-second interval rather than computed on each redraw: nothing
     * else would cause a redraw while the user waits, so the number would sit
     * frozen at whatever it read when they last typed.
     *
     * The server is the authority. This only mirrors what it already told us, so a
     * reload — or a second tab — still shows the wait rather than offering a send
     * that is refused.
     */
    protected startCooldown(seconds: number): void;
    protected stopCooldown(): void;
    protected submit(): Promise<void>;
    protected pickFiles(): void;
    protected onFilesPicked(e: Event): Promise<void>;
    protected removeUpload(id: number): void;
}
