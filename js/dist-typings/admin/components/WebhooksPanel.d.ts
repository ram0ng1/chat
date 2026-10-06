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
    private loading;
    /** URLs revealed this session, keyed by webhook id. Never re-fetchable. */
    private revealed;
    private draftName;
    private draftChannel;
    private working;
    oninit(vnode: Mithril.Vnode<ComponentAttrs, this>): void;
    view(): Mithril.Children;
    protected createForm(): Mithril.Children;
    protected list(): Mithril.Children;
    protected row(webhook: WebhookModel): Mithril.Children;
    protected load(): Promise<void>;
    protected create(): Promise<void>;
    protected setActive(webhook: WebhookModel, active: boolean): Promise<void>;
    protected rotate(webhook: WebhookModel): Promise<void>;
    protected remove(webhook: WebhookModel): Promise<void>;
    protected remember(webhook: WebhookModel): void;
}
export {};
