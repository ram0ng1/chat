import app from "flarum/admin/app";
import ExtensionPage from "flarum/admin/components/ExtensionPage";
import Form from "flarum/common/components/Form";
import type Mithril from "mithril";

import BotSettings from "./BotSettings";
import WebhooksPanel from "./WebhooksPanel";

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

const SECTIONS: readonly Section[] = [
  {
    key: "channels",
    icon: "fas fa-people-group",
    keys: [
      "ramon-chat.channel_ownership",
      "ramon-chat.allow_archiving_channels",
      "ramon-chat.threading_default",
    ],
  },
  {
    key: "appearance",
    icon: "fas fa-palette",
    keys: ["ramon-chat.title", "ramon-chat.icon", "ramon-chat.show_icon"],
    columns: 2,
  },
  {
    key: "messages",
    icon: "fas fa-message",
    keys: [
      "ramon-chat.max_message_length",
      "ramon-chat.min_message_length",
      "ramon-chat.max_messages_per_second",
      "ramon-chat.message_edit_window_minutes",
    ],
    columns: 2,
  },
  {
    key: "uploads",
    icon: "fas fa-paperclip",
    keys: ["ramon-chat.allow_uploads", "ramon-chat.max_upload_size"],
    columns: 2,
  },
  {
    key: "retention",
    icon: "fas fa-broom",
    keys: ["ramon-chat.channel_retention_days", "ramon-chat.dm_retention_days"],
    columns: 2,
    warning: "ramon-chat.admin.settings.retention_warning",
  },
  {
    key: "realtime",
    icon: "fas fa-bolt",
    keys: ["ramon-chat.queue_realtime", "ramon-chat.notification_sound"],
  },
];

/** The switch at the top of the webhooks card; a registered setting like the rest. */
const WEBHOOKS_SWITCH = "ramon-chat.webhooks_enabled";

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
  content(vnode: Mithril.VnodeDOM<any, any>) {
    const entries = (app.registry.getSettings(this.extension.id) ?? []).filter(
      (entry): entry is Exclude<typeof entry, () => Mithril.Children> =>
        typeof entry !== "function",
    );

    const byKey = new Map(entries.map((entry) => [entry.setting, entry]));
    const placed = new Set([
      ...SECTIONS.flatMap((section) => section.keys),
      WEBHOOKS_SWITCH,
    ]);
    const leftover = entries.filter((entry) => !placed.has(entry.setting));

    return (
      <div className="ExtensionPage-settings ChatAdmin">
        <div className="container">
          <Form>
            <div className="ChatAdmin-grid">
              {SECTIONS.map((section) => this.section(section, byKey))}

              {leftover.length > 0
                ? this.card(
                    "other",
                    "fas fa-sliders",
                    leftover.map((entry) => this.buildSettingComponent(entry)),
                  )
                : null}

              {this.card("announcer", "fas fa-robot", <BotSettings />)}
              {this.webhooksCard(byKey.get(WEBHOOKS_SWITCH))}
            </div>

            <div className="Form-group Form-controls ChatAdmin-controls">
              {this.submitButton()}
              {this.resetButton(
                entries.map((entry) => ({
                  key: entry.setting,
                  label: entry.label,
                })),
                app.translator.trans(
                  "core.admin.extension.reset_settings.title_extension",
                  {
                    extensionTitle:
                      this.extension.extra["flarum-extension"].title,
                  },
                  true,
                ),
                this.extension.id,
              )}
            </div>
          </Form>
        </div>
      </div>
    );
  }

  protected section(
    section: Section,
    byKey: Map<string, Parameters<this["buildSettingComponent"]>[0]>,
  ): Mithril.Children {
    const fields = section.keys
      .map((key) => byKey.get(key))
      .filter((entry): entry is NonNullable<typeof entry> => Boolean(entry))
      .map((entry) => this.buildSettingComponent(entry));

    if (fields.length === 0) return null;

    return this.card(
      section.key,
      section.icon,
      <>
        <div
          className={
            "ChatAdmin-fields" +
            (section.columns === 2 ? " ChatAdmin-fields--two" : "")
          }
        >
          {fields}
        </div>

        {section.warning ? (
          <p className="ChatAdmin-warning">
            <i className="fas fa-triangle-exclamation" aria-hidden="true" />
            {app.translator.trans(section.warning)}
          </p>
        ) : null}
      </>,
    );
  }

  /**
   * The webhooks card: the feature switch first, then the records it governs.
   *
   * The switch is read live from the form's own state, so turning it off folds
   * the panel away before the change is even saved, and the note in its place
   * says what saving will do. The switch is saved by the bar below, with the
   * rest of the page.
   */
  protected webhooksCard(
    entry: Parameters<this["buildSettingComponent"]>[0] | undefined,
  ): Mithril.Children {
    const raw = this.setting(WEBHOOKS_SWITCH)();
    const enabled = raw === "1" || raw === "true" || raw === true;

    return this.card(
      "webhooks",
      "fas fa-plug",
      <>
        {entry ? (
          <div className="ChatAdmin-fields ChatAdmin-switch">
            {this.buildSettingComponent(entry)}
          </div>
        ) : null}

        {enabled ? (
          <WebhooksPanel />
        ) : (
          <p className="ChatAdmin-muted">
            <i className="fas fa-plug-circle-xmark" aria-hidden="true" />
            {app.translator.trans("ramon-chat.admin.webhooks.disabled")}
          </p>
        )}
      </>,
    );
  }

  /**
   * A titled card. Title and help come from `admin.sections.<key>_title` and
   * `_help`, so every section says what it is for before showing its fields.
   */
  protected card(
    key: string,
    icon: string,
    body: Mithril.Children,
  ): Mithril.Children {
    return (
      <section className={"ChatAdmin-section ChatAdmin-section--" + key}>
        <header className="ChatAdmin-sectionHeader">
          <span className="ChatAdmin-sectionIcon" aria-hidden="true">
            <i className={icon} />
          </span>
          <div>
            <h3 className="ChatAdmin-sectionTitle">
              {app.translator.trans(`ramon-chat.admin.sections.${key}_title`)}
            </h3>
            <p className="ChatAdmin-sectionHelp helpText">
              {app.translator.trans(`ramon-chat.admin.sections.${key}_help`)}
            </p>
          </div>
        </header>

        <div className="ChatAdmin-sectionBody">{body}</div>
      </section>
    );
  }
}
