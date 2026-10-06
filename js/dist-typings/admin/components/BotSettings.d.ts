import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type User from "flarum/common/models/User";
import type Mithril from "mithril";
/**
 * Who the chat posts as.
 *
 * Two mutually exclusive answers, which is why this is one component rather than
 * three independent settings rows: either a real account announces (and the chat's
 * own identity is irrelevant), or the bot does. Presenting the bot's name and
 * picture as editable while an account is selected would offer a choice that has no
 * effect, so the form collapses to whichever half is live.
 */
export default class BotSettings extends Component<ComponentAttrs> {
    private uploading;
    private searching;
    private query;
    private candidates;
    private searchTimeout?;
    private searchSequence;
    view(): Mithril.Children;
    /**
     * The announcement as it will land in a channel, from whichever half is live.
     */
    protected preview(announcer: User | null): Mithril.Children;
    /**
     * The bot's own identity. Only drawn when no account is announcing.
     */
    protected botForm(): Mithril.Children;
    /**
     * The account currently announcing, and the way back to the bot.
     */
    protected selectedUser(user: User): Mithril.Children;
    protected userPicker(): Mithril.Children;
    protected announcer(): User | null;
    protected setting(key: string): string;
    protected set(key: string, value: string | null): void;
    protected upload(e: Event): Promise<void>;
    protected removeUpload(): Promise<void>;
    protected search(value: string): void;
    protected chooseUser(user: User): void;
    protected clearUser(): void;
    /**
     * Folds a forum payload back into the admin's settings copy.
     *
     * The upload controller returns the forum resource, whose `ramon-chat.*`
     * attributes are the serialised settings — so the new avatar path can be read
     * straight off it instead of reloading the admin page.
     */
    protected applyForumPayload(payload: any): void;
}
