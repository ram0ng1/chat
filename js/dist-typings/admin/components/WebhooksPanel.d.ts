import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
interface WebhookModel {
    id(): string;
    attribute<T = unknown>(name: string): T;
    pushAttributes(attrs: Record<string, unknown>): void;
    save(attrs: Record<string, unknown>): Promise<unknown>;
    delete(): Promise<unknown>;
}
/**
 * Inbound webhook administration.
 *
 * Each webhook posts into one channel with a secret key in its URL. The key is
 * returned by the API only on create and on rotate, so it is shown once here and
 * then never again, which is why the freshly minted URL is held in component
 * state and called out rather than listed with the rest of the row.
 *
 * A component rather than part of the page: the page decides where the panel
 * sits, and the panel owns the records.
 */
export default class WebhooksPanel extends Component<ComponentAttrs> {
    private webhooks;
    private channels;
    /** Accounts a webhook may post as, besides the bot: administrators only. */
    private admins;
    private loading;
    /** URLs revealed this session, keyed by webhook id. Never re-fetchable. */
    private revealed;
    private draftName;
    private draftChannel;
    /** Empty posts as the bot; otherwise an admin's user id. */
    private draftAuthor;
    private working;
    oninit(vnode: Mithril.Vnode<ComponentAttrs, this>): void;
    view(): Mithril.Children;
    protected createForm(): Mithril.Children;
    /**
     * Who a webhook posts as: the bot, or one of the forum's administrators.
     * Without a choice the message has no author and the stream draws it as a
     * deleted account.
     */
    protected authorSelect(value: string, onchange: (value: string) => void): Mithril.Children;
    protected authorOf(webhook: WebhookModel): string;
    protected list(): Mithril.Children;
    protected row(webhook: WebhookModel): Mithril.Children;
    protected load(): Promise<void>;
    protected create(): Promise<void>;
    protected setActive(webhook: WebhookModel, active: boolean): Promise<void>;
    protected setAuthor(webhook: WebhookModel, value: string): Promise<void>;
    protected rotate(webhook: WebhookModel): Promise<void>;
    protected remove(webhook: WebhookModel): Promise<void>;
    protected remember(webhook: WebhookModel): void;
}
export {};
