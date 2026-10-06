import ExtensionPage from "flarum/admin/components/ExtensionPage";
import type Mithril from "mithril";
/**
 * One block of related settings on the page.
 *
 * `keys` are the setting keys registered in index.ts, in the order they are
 * drawn. The registry stays the single source of each field's type, label and
 * help; this only decides where on the page it goes.
 */
interface Section {
    key: string;
    icon: string;
    keys: readonly string[];
    /** Fields side by side, for short inputs like numbers. */
    columns?: 1 | 2;
    /** A translation key drawn as a warning under the fields. */
    warning?: string;
}
/** Where public attachments are stored: "local" or "fof-upload". */
export declare const STORAGE_SETTING = "ramon-chat.upload_storage";
/** The fof/upload adapter key; empty follows fof/upload's own mime mapping. */
export declare const ADAPTER_SETTING = "ramon-chat.fof_upload_adapter";
/**
 * The chat's admin page.
 *
 * Core's ExtensionPage draws every registered setting as one long column, in
 * registration order, with nothing between "who runs channels" and "maximum
 * upload size" to say they are different subjects. Here the same settings are
 * grouped into titled sections, each with a line saying what it governs. The
 * announcer identity and the incoming webhooks get cards of their own in the
 * same grid, above the save bar, so the page reads as one form from top to
 * bottom and the bar is the last thing on it.
 *
 * The settings are still read from the registry, so `registerSetting` in
 * index.ts remains the one place a field is defined; this page only lays them
 * out. A key registered there and not listed here is still drawn, in a final
 * catch-all section, so nothing can silently disappear from the admin.
 */
export default class ChatSettingsPage extends ExtensionPage {
    content(vnode: Mithril.VnodeDOM<any, any>): JSX.Element;
    protected section(section: Section, byKey: Map<string, Parameters<this["buildSettingComponent"]>[0]>): Mithril.Children;
    /**
     * The adapter only matters once fof/upload is the chosen storage, so it is
     * folded away otherwise. Read live from the form, like the webhooks switch,
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
    protected webhooksCard(entry: Parameters<this["buildSettingComponent"]>[0] | undefined): Mithril.Children;
    /**
     * A titled card. Title and help come from `admin.sections.<key>_title` and
     * `_help`, so every section says what it is for before showing its fields.
     */
    protected card(key: string, icon: string, body: Mithril.Children): Mithril.Children;
}
export {};
