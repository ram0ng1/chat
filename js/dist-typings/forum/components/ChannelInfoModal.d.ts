import Modal from "flarum/common/components/Modal";
import type { IInternalModalAttrs } from "flarum/common/components/Modal";
import type User from "flarum/common/models/User";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
import { type SendKey } from "../utils/shortcuts";
export interface ChannelInfoModalAttrs extends IInternalModalAttrs {
    channel: Channel;
}
/**
 * Channel details: notification preferences, the member list, and the moderation
 * actions that change a channel's state.
 *
 * Distinct from ChannelFormModal, which edits what a channel *is* (name, category,
 * threading) and needs the edit permission. This is what any member may see and
 * adjust for themselves, with the state-changing actions gated per capability
 * flag — so a plain member gets a useful panel rather than a locked one.
 */
export default class ChannelInfoModal extends Modal<ChannelInfoModalAttrs> {
    private tab;
    private members;
    /** People invited and not yet answered. Served to managers only. */
    private invited;
    private loadingMembers;
    private loadedMembers;
    private memberFilter;
    private working;
    /** Stops listening for membership pushes; set while the modal is open. */
    private stopListening;
    /**
     * Which immediate action is running, or null.
     *
     * `working` answers "is anything in flight", which is what every control reads
     * to disable itself. A spinner is a narrower claim — it says *this* button
     * started something — and driving them all from the one flag meant changing
     * the notification level spun Close and Archive along with it.
     */
    private pending;
    className(): string;
    title(): Mithril.Children;
    content(): Mithril.Children;
    protected tabButton(tab: "settings" | "members", key: string): Mithril.Children;
    protected settings(): Mithril.Children;
    /**
     * How this member's own composer behaves.
     *
     * Its own section rather than a row under NOTIFICATIONS, and worth being
     * explicit about why it is in a per-channel panel at all: which key sends is a
     * setting for the person, not for the channel, and it applies everywhere. The
     * section sits here because this is the panel a member already opens to adjust
     * the chat for themselves — but the help line says "every channel" so nobody
     * reads the surrounding modal as the scope.
     *
     * Guests never reach this: the chat requires an account, and `savePreferences`
     * needs a user to save against.
     */
    protected composerSection(): Mithril.Children;
    /** The line describing the notification level currently selected. */
    protected notificationLevelHelp(channel: Channel): string;
    /**
     * Actions that change the channel for everyone. Each is drawn only when the
     * server said the actor may do it, so nothing here is present-and-rejected.
     */
    protected moderation(): Mithril.Children;
    protected memberTab(): Mithril.Children;
    /**
     * Who has been asked in and not answered, for whoever manages the list.
     *
     * Under the members rather than among them: an invitation is not a
     * membership, and a name in the member list that cannot be messaged would
     * be a lie. Each row can be withdrawn, which is the manager's half of the
     * exchange the invitee's accept and decline are the other half of.
     */
    protected invitedList(): Mithril.Children;
    /**
     * Withdraws an invitation. The invitee's notification goes with it, so
     * confirmed first: the person may be reading it at that very moment.
     */
    protected cancelInvite(user: User): Promise<void>;
    protected isOwner(user: User): boolean;
    protected isModerator(user: User): boolean;
    /** The owner's and channel moderators' labels, so the roles are visible to everyone. */
    protected memberBadge(user: User): Mithril.Children;
    /**
     * Promote, demote and remove — each drawn only for people the actor may
     * actually act on, so no button is a promise the server refuses to keep.
     * Nothing for yourself: leaving is what "Leave channel" is for, and the owner
     * is not something you can stop being from here.
     */
    protected memberControls(user: User): Mithril.Children;
    /**
     * Hands the channel's moderator role to a member, or takes it back.
     *
     * The response carries the channel with its participants, so pushing it
     * refreshes `moderatorIds` on the very model this modal is drawing from.
     */
    protected setModerator(user: User, moderator: boolean): Promise<void>;
    /**
     * Opens the people picker.
     *
     * Stacked on top of this modal rather than replacing it: the member list is
     * the context for the choice, and coming back to a closed dialog after adding
     * three people would mean reopening it to check they landed.
     *
     * This is how anyone gets into a private channel: it is not discoverable and
     * cannot be joined, so an existing member with `manageMembers` has to put you
     * there. Offered only when the server says the actor may — a moderator, or the
     * creator of a group conversation.
     */
    protected openAddMembers(): void;
    /**
     * Folds the picker's result into the pending list.
     *
     * Invited, not added: nobody is a member until they say yes, so the count
     * does not move and the names go under "invitations pending" rather than
     * into the member list.
     */
    protected onMembersInvited(users: User[]): void;
    /**
     * Removes someone else from the channel.
     *
     * Confirmed first: unlike adding, this one is not obviously undoable from the
     * other side — a private channel cannot be rejoined, so the person would have to
     * be added back by hand.
     */
    protected remove(user: User): Promise<void>;
    protected loadMembers(): Promise<void>;
    onremove(vnode: Mithril.VnodeDOM<ChannelInfoModalAttrs, this>): void;
    protected saveNotifications(level: number | null, muted: boolean | null): Promise<void>;
    /**
     * Stores the send-key choice on the user.
     *
     * Optimistic like `saveNotifications`, and for a sharper reason: the composer
     * reads the preference off the same record on every keystroke, so the change
     * has to be in place before the next one — waiting for the round trip would
     * mean the first Enter after choosing still did the old thing.
     *
     * The rollback is not the redundant belt-and-braces it looks like. `Model.save`
     * does snapshot and restore on failure, but `savePreferences` mutates the
     * preferences object *in place* before calling it, so the snapshot is taken
     * with the new value already in it and core's revert restores that. Undoing it
     * here is the only thing that stops a rejected save from leaving the select —
     * and the composer — agreeing with a server that never accepted it.
     */
    protected saveSendKey(value: SendKey): Promise<void>;
    protected setStatus(status: "open" | "closed"): Promise<void>;
    protected archive(): Promise<void>;
    protected destroy(): Promise<void>;
    /**
     * Runs one immediate state change.
     *
     * `action` names the button that owns the spinner for the duration; `working`
     * still gates every other control, so nothing else can be started meanwhile.
     */
    protected act(action: "status" | "archive", path: string, attributes: Record<string, unknown>): Promise<void>;
}
