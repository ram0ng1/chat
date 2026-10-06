import ExtensionPage from "flarum/admin/components/ExtensionPage";
import type Mithril from "mithril";
/** Where public attachments are stored: "local" or "fof-upload". */
export declare const STORAGE_SETTING = "ramon-chat.upload_storage";
/** The fof/upload adapter key; empty follows fof/upload's own mime mapping. */
export declare const ADAPTER_SETTING = "ramon-chat.fof_upload_adapter";
type GroupKey = "general" | "messages" | "delivery" | "integrations";
/**
 * One block of related settings on the page.
 *
 * `keys` are the setting keys registered in index.ts, in the order they are
 * drawn. The registry stays the single source of each field's type, label and
 * help; this only decides where on the page it goes.
 */
interface Section {
    key: string;
    group: GroupKey;
    icon: string;
    keys: readonly string[];
    /** Fields side by side, for short inputs like numbers. */
    columns?: 1 | 2;
    /** A translation key drawn as a warning under the fields. */
    warning?: string;
}
/** A card as the page draws it, whichever kind of body it carries. */
interface Card {
    key: string;
    group: GroupKey;
    icon: string;
    /** Lower-cased text the search box matches against. */
    haystack: string;
    body: () => Mithril.Children;
}
type SettingEntry = Parameters<ExtensionPage["buildSettingComponent"]>[0] extends infer E ? Exclude<E, (...args: any[]) => any> : never;
/**
 * The chat's admin page.
 *
 * Laid out the way the Avocado theme lays out its own: a sticky bar of tabs,
 * each a group of titled cards, with a search box that reaches across every
 * tab at once. What differs is the save model. Avocado's controls save on
 * change; the chat's are registered settings saved together by the bar at the
 * foot of the form, which stays pinned to the bottom of the window and says
 * how many changes are waiting.
 *
 * The settings are still read from the registry, so `registerSetting` in
 * index.ts remains the one place a field is defined; this page only lays them
 * out. A key registered there and not listed here is still drawn, in a final
 * catch-all card, so nothing can silently disappear from the admin.
 */
export default class ChatSettingsPage extends ExtensionPage {
    content(vnode: Mithril.VnodeDOM<any, any>): JSX.Element;
    /**
     * Every card on the page, in tab order. Built on each draw because the
     * fields shown in a card follow the live form (the fof/upload adapter
     * appears once fof/upload is the chosen storage).
     */
    protected cards(entries: SettingEntry[]): Card[];
    /**
     * What the search box matches for a card: its title and description, and
     * the label and help of every field in it.
     */
    protected haystack(key: string, fields: SettingEntry[], extra?: string): string;
    protected sectionText(key: string, part: "title" | "help"): string;
    /**
     * The tab bar and the search box. Sticky, so changing tabs from the foot of
     * a long card does not mean scrolling back to the top first.
     */
    protected toolbar(cards: Card[], searching: boolean): Mithril.Children;
    protected selectGroup(key: GroupKey): void;
    /** Arrow keys, Home and End move between tabs, as a tab list should. */
    protected onTabKey(e: KeyboardEvent): void;
    protected sectionBody(section: Section, fields: SettingEntry[]): Mithril.Children;
    /**
     * A boolean setting as the form holds it: "1"/"0" once loaded, a real
     * boolean once toggled, nothing at all before it was ever saved.
     */
    protected isOn(raw: unknown, fallback: boolean): boolean;
    /**
     * The queue switch only means something on a continuously worked queue (see
     * queueKind), so it is not offered elsewhere. The adapter only matters once
     * fof/upload is the chosen storage, so it is folded away otherwise. Read live from the form, like the webhooks switch,
     * so it appears the moment the storage is switched rather than after saving.
     */
    protected fieldShown(key: string): boolean;
    /**
     * fof/upload's `local` adapter writes into the forum's own public directory,
     * so it saves nothing unless fof/upload's CDN URL puts a CDN in front of it.
     * Following fof/upload's mime mapping often lands there too: its default
     * rule for images names the local adapter. Said where the choice is made,
     * because nothing else would tell.
     */
    protected storesOnFofLocal(): boolean;
    /**
     * The webhooks card: the feature switch first, then the records it governs.
     *
     * The switch is read live from the form's own state, so turning it off folds
     * the panel away before the change is even saved, and the note in its place
     * says what saving will do. The switch is saved by the bar below, with the
     * rest of the page.
     */
    protected webhooksBody(entry: SettingEntry | undefined): Mithril.Children;
    /**
     * A titled card. Title and help come from `admin.sections.<key>_title` and
     * `_help`, so every card says what it is for before showing its fields.
     * While searching, the card also names the tab it lives in.
     */
    protected card(card: Card, group: GroupKey | null): Mithril.Children;
}
export {};
