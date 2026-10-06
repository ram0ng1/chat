import FormModal from "flarum/common/components/FormModal";
import type { IFormModalAttrs } from "flarum/common/components/FormModal";
import type User from "flarum/common/models/User";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
export interface AddMembersModalAttrs extends IFormModalAttrs {
    channel: Channel;
    /** Already in the channel: listed, but not offered. */
    members: User[];
    /** Already asked and not answered: listed, but not offered again. */
    invited: User[];
    /** Called with the people the server was asked to invite. */
    onAdded: (users: User[]) => void;
}
/**
 * Picks people to invite into a channel.
 *
 * A token field: the chosen people sit inside the search box as chips, so the
 * question "who am I about to invite" is answered where the typing happens.
 * Before anything is typed the box offers the people most recently seen on the
 * forum, so the dialog never opens onto an empty grey square; typing narrows
 * that to a search. People who are already in, or already asked, still appear
 * in the results, marked and unselectable, so their absence from the offer is
 * explained rather than silent.
 *
 * The keyboard works the way a token field is expected to: arrows move along
 * the list, Enter picks, Backspace on an empty box takes the last chip back,
 * Escape clears the query.
 */
export default class AddMembersModal extends FormModal<AddMembersModalAttrs> {
    private query;
    private results;
    private suggestions;
    private selected;
    private searching;
    private loadingSuggestions;
    private highlighted;
    private timer;
    /**
     * Guards against an earlier search resolving after a later one.
     *
     * Typing "ram" issues a request for "ra" and one for "ram"; without this the
     * slower of the two wins and the list contradicts the field.
     */
    private sequence;
    oninit(vnode: Mithril.Vnode<AddMembersModalAttrs, this>): void;
    onremove(vnode: Mithril.VnodeDOM<AddMembersModalAttrs, this>): void;
    className(): string;
    title(): Mithril.Children;
    content(): Mithril.Children;
    /**
     * The offer: suggestions until two letters are typed, then the search.
     */
    protected list(): Mithril.Children;
    /**
     * Rows for a list that mixes people who can be picked with people who
     * cannot. The highlight counts only the former, the way the arrow keys do,
     * so a marked row in the middle of the results does not shift it.
     */
    protected rows(users: User[]): Mithril.Children;
    protected row(user: User, index: number): Mithril.Children;
    protected footer(): Mithril.Children;
    protected inner(): Mithril.Children;
    /**
     * Why a person cannot be picked, or null when they can.
     */
    protected statusOf(user: User): "you" | "member" | "invited" | null;
    protected isSelected(user: User): boolean;
    protected toggle(user: User): void;
    protected offered(): User[];
    protected onKey(e: KeyboardEvent): void;
    protected focusInput(): void;
    /**
     * A handful of people offered before any typing.
     *
     * The most recently seen when the actor may sort by that (core gates the
     * `lastSeenAt` sort behind `user.viewLastSeenAt`, which ordinary members
     * usually lack and which the API answers with a 400), the most active
     * posters otherwise. Fetched once per opening; the list is small and the
     * point is only to give the dialog something to open onto.
     */
    protected loadSuggestions(): Promise<void>;
    /** Debounced so typing does not issue a request per keystroke. */
    protected search(value: string): void;
    onsubmit(e: SubmitEvent): void;
}
