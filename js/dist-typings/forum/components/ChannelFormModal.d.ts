import FormModal from "flarum/common/components/FormModal";
import type { IFormModalAttrs } from "flarum/common/components/FormModal";
import Stream from "flarum/common/utils/Stream";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
export interface ChannelFormModalAttrs extends IFormModalAttrs {
    /** Omit to create a channel; pass one to edit it. */
    channel?: Channel;
    /** Called with the saved channel. */
    onSaved?: (channel: Channel) => void;
}
/**
 * Creates or edits a category channel.
 *
 * One component for both because the field set is identical — Discourse shows the
 * same form for "New channel" and the channel's settings tab. Only the title, the
 * submit label and whether the record already exists differ.
 *
 * Extends FormModal, not Modal: `Modal.wrapper()` returns a bare fragment, so a
 * `type="submit"` button inside it has no form and onsubmit never fires.
 */
export default class ChannelFormModal extends FormModal<ChannelFormModalAttrs> {
    private name;
    private description;
    private emoji;
    private uploadingImage;
    /**
     * Which immediate action is running, or null.
     *
     * Distinct from `loading`, and both are needed. `loading` is core's form-wide
     * gate — it answers "is anything in flight", which is what every field and
     * every button reads to disable itself, and that part was right.
     *
     * What it cannot answer is *which* action is running, and a spinner is a claim
     * about one control rather than about the form. Driving four buttons from the
     * one flag meant pressing Save spun Close, Archive and Delete along with it,
     * as though the dialog had started four things at once.
     */
    private pending;
    /**
     * A picture chosen on the create form, held until the channel exists.
     *
     * The upload route is addressed to a channel id, so on create there is nothing
     * to send it to yet. Keeping the file here and attaching it right after the
     * save is what lets the picture be part of creating a channel rather than a
     * second trip through the settings modal.
     */
    private pendingImage;
    /**
     * `URL.createObjectURL` handle for the preview of {@link pendingImage}.
     *
     * Tracked separately because it has to be revoked — the browser keeps the blob
     * alive for the lifetime of the document otherwise, and picking three pictures
     * before saving would leak all three.
     */
    private pendingImageUrl;
    /**
     * Which of the two icon fields is in use.
     *
     * A channel has one mark, not two: `channelIcon` already resolves picture over
     * emoji, so a channel carrying both silently ignores one of them and the form
     * showed no sign of which. The switch makes that choice the thing being edited,
     * and {@link onsubmit} enforces it in the data — the losing field is cleared
     * rather than left behind to reappear if the winner is ever removed.
     */
    private useImage;
    private tagId;
    private threading;
    private slowMode;
    /** A string, like `slowMode`: it backs a <select>. "" means "follow the forum". */
    private maxMessageLength;
    /** Whether the custom number field is showing instead of a preset. */
    private customLength;
    private autoJoin;
    private allowChannelWide;
    private autoJoinOnReply;
    private isPrivate;
    private postPermission;
    private postDiscussions;
    oninit(vnode: Mithril.Vnode<ChannelFormModalAttrs>): void;
    /**
     * Releases the staged picture's object URL.
     *
     * Closing the modal without saving is the common path — the file was never
     * uploaded, so nothing on the server needs cleaning up, but the blob handle
     * would outlive the component.
     */
    onremove(vnode: Mithril.VnodeDOM<ChannelFormModalAttrs, this>): void;
    protected isEditing(): boolean;
    className(): string;
    title(): Mithril.Children;
    /**
     * Replaces core's centred header + body pair with a header / body / footer
     * dialog.
     *
     * The form was a single 375px column of eighteen stacked fields, which is the
     * shape core's `inner()` produces and the reason it read as a questionnaire:
     * the one required field, the name, sat at the same weight and in the same
     * rhythm as an admin-only toggle six scrolls below it. Everything else here —
     * the two columns, the grouping, the pinned submit — depends on owning this
     * wrapper, so it is overridden rather than worked around from `content()`.
     */
    protected inner(): Mithril.Children;
    /**
     * Icon, title and one line of orientation.
     *
     * The icon mirrors the emoji or picture currently chosen, so the field that is
     * furthest from the header still shows its effect without scrolling back.
     */
    protected header(): Mithril.Children;
    content(): Mithril.Children;
    /** Name, description and the channel's icon. */
    protected identitySection(): Mithril.Children;
    /**
     * The channel's mark: an emoji, or a picture instead of one.
     *
     * One slot holding whichever the switch selects, rather than both fields on
     * screen at once. Two always-visible fields for a value that can only be one
     * of them invites setting both and then wondering why only the picture shows.
     */
    protected iconField(): Mithril.Children;
    /**
     * One half of the switch. A span, not a button: the whole control is the
     * button, so a click on either side flips it.
     */
    protected iconKindSide(picture: boolean, icon: string): Mithril.Children;
    /**
     * Switches between the two icon fields.
     *
     * Turning the picture off drops a file staged for a channel that does not
     * exist yet: it is never going to be uploaded now, and holding it would
     * attach it anyway if the switch were flipped back on after the save. A
     * picture already on the server is left alone until the form is submitted, so
     * the change stays cancellable — see {@link onsubmit}.
     */
    protected chooseIconKind(picture: boolean): void;
    /** Who can see the channel, who can write in it, and what it inherits. */
    protected accessSection(): Mithril.Children;
    /** How the channel behaves once it exists. */
    protected behaviourSection(): Mithril.Children;
    /**
     * A titled group of fields, dropped entirely when it has nothing to show —
     * an empty bordered box with a heading is worse than no box.
     */
    protected section(titleKey: string, children: Mithril.Children[], className?: string): Mithril.Children;
    /**
     * One switch row: control, label and the sentence explaining what it changes.
     *
     * A `Switch` rather than a bare checkbox because these are settings that take
     * effect on save, not items being ticked off a list — and because the help
     * text needs to sit under the label, which a checkbox's inline layout cannot
     * do without a hardcoded indent matching the box's width.
     */
    protected toggle(stream: Stream<boolean>, labelKey: string, help?: Mithril.Children): Mithril.Children;
    /**
     * The submit row, pinned below the scrolling body.
     *
     * It used to be the last of eighteen stacked fields, which meant that on a
     * short viewport the only way to find out the form could be submitted was to
     * scroll past every optional setting.
     */
    protected footer(): Mithril.Children;
    /**
     * A picture for the channel, instead of its emoji.
     *
     * Offered on both forms, but the two behave differently underneath. Editing
     * uploads immediately, because the channel id the route needs already exists.
     * Creating cannot: the id is only minted by the save. There the file is held
     * in {@link pendingImage} and attached once the record comes back — see
     * {@link attachPendingImage}.
     *
     * The alternative, hiding the field until the channel exists, is what this
     * replaced: it read as "channels cannot have pictures" and sent people back
     * through the settings modal for something they had already decided.
     *
     * No label of its own: it fills the icon slot, which {@link iconField} has
     * already labelled.
     */
    protected image(): Mithril.Children;
    /** The picture standing in for the channel: staged on create, saved on edit. */
    protected iconImageUrl(): string | null;
    /**
     * Routes the picked file: straight to the server when the channel exists,
     * into {@link pendingImage} when it does not.
     */
    protected chooseImage(e: Event): void;
    /** Drops the picture: a DELETE when saved, a local discard when staged. */
    protected removeImage(): void;
    private discardPendingImage;
    /**
     * Sends the staged picture to the channel that was just created.
     *
     * A failure here does NOT undo the channel — it exists, it is in the sidebar,
     * and the only thing missing is the picture. Saying so and letting the user
     * add it from the settings modal is honest; rolling back a channel they asked
     * for because a JPEG was too large would not be.
     */
    private attachPendingImage;
    protected uploadImage(e: Event): Promise<void>;
    protected clearImage(): Promise<void>;
    protected visibility(): Mithril.Children;
    /** One visibility card. Still a radio underneath — keyboard and screen readers get the group semantics for free. */
    protected choice(value: boolean, icon: string, labelKey: string, helpKey: string): Mithril.Children;
    /**
     * Who may post.
     *
     * A separate question from visibility: "who can see this" and "who can write in
     * it" are independent, and a private channel only moderators post in is a
     * perfectly ordinary announcement channel for an invited audience.
     */
    protected posting(): Mithril.Children;
    /**
     * Close and archive, on an existing channel only.
     *
     * Neither means anything for a channel that does not exist yet, and both act
     * immediately rather than on submit — they are not settings being edited, they
     * are state changes, and mixing them into the form's save would make an
     * unsaved-and-abandoned form able to archive something.
     */
    protected lifecycle(): Mithril.Children;
    /**
     * Deletes the channel and closes the dialog.
     *
     * Its own path rather than `act()`: that one posts to an endpoint and then
     * hands the saved channel to `onSaved`, and neither makes sense once the
     * record is gone. The callers that pass `onSaved` on the *edit* path use it to
     * refresh a list, which is exactly what should happen here too — the create
     * path cannot reach this code, because a channel that does not exist yet has
     * no `canDelete()` to be true.
     */
    protected destroy(): Promise<void>;
    protected setStatus(status: "open" | "closed"): Promise<void>;
    protected archive(): Promise<void>;
    /**
     * Runs one immediate state change.
     *
     * `action` names the button that owns the spinner for the duration; `loading`
     * still gates the rest of the form, so nothing else can be started meanwhile.
     */
    protected act(action: "status" | "archive", path: string, attributes: Record<string, unknown>): Promise<void>;
    /**
     * Category picker, rendered only when flarum/tags is present. A tag-bound
     * channel inherits that tag's permissions, which is how a restricted category
     * yields a restricted channel.
     */
    protected tagOptions(): Mithril.Children;
    /**
     * Picks the bound category, or clears it.
     *
     * Clearing also switches off "announce new discussions": that toggle is only
     * rendered while a category is chosen, so leaving it set would submit a value
     * the reader can no longer see or reach — and the channel would start
     * announcing the moment someone bound a category to it later.
     */
    protected chooseTag(id: string): void;
    /** The category currently chosen in the picker, if any. */
    protected selectedTag(): any | null;
    /**
     * Nothing awaits this method, so a rejection here would surface as an unhandled
     * promise rejection rather than as feedback. Every failure path is handled inline.
     */
    /**
     * Slow mode: how long each person waits between messages here.
     *
     * A fixed list rather than a free number field. The useful values are few and
     * far apart — five seconds calms a room, five minutes changes what the room is
     * for — and a text box invites 7s, which is nobody's intention.
     *
     * Moderators are exempt, and the help text says so: someone enabling this needs
     * to know it will not throttle them out of their own moderation. That sentence
     * only appears once a wait is actually set — with slow mode off there is no
     * exemption to explain, and describing one implies a limit that is not there.
     */
    protected slowModeOptions(): Mithril.Children;
    /**
     * How long a message may be here.
     *
     * Presets plus a way out of them. The list covers what most channels want and
     * keeps the common case to one click; "Custom" opens a number field for the
     * rooms that need something the list does not have. The first option is not a
     * number at all — it keeps the channel following the forum-wide setting, so
     * raising that later still raises this channel with it.
     */
    protected messageLengthOptions(): Mithril.Children;
    /** The sentence under the field, describing the option actually selected. */
    protected messageLengthHelp(forumDefault: number): Mithril.Children;
    /** Switches between a preset, the forum default, and the custom field. */
    protected chooseMessageLength(picked: string): void;
    /**
     * The custom value as it will be stored: clamped to the range the API
     * accepts, or null when the field is empty or not a number.
     */
    protected clampedMessageLength(): number | null;
    onsubmit(e: SubmitEvent): void;
}
