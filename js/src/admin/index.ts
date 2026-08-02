import app from "flarum/admin/app";

import Channel from "../common/models/Channel";
import Webhook from "../common/models/Webhook";
import WebhooksPage from "./components/WebhooksPage";

export { WebhooksPage, Webhook };

app.initializers.add("ramon-chat", () => {
  // The admin store is a separate instance from the forum's, so these have to be
  // registered here as well or the webhook page's payloads are dropped for want of
  // a model.
  app.store.models["chat-webhooks"] = Webhook;
  app.store.models["chat-channels"] = Channel;

  // Flarum 2 exposes the admin registration surface as `app.registry`
  // (AdminRegistry). `app.extensionData` was the Flarum 1 name and does not
  // exist here — reaching for it throws during initialize(), before the admin
  // app has mounted anything.
  app.registry
    .for("ramon-chat")

    // The extension page is replaced wholesale rather than adding webhooks and the
    // announcer as more settings rows: webhooks are records, not settings, and the
    // announcer is an exclusive choice — neither has a shape the settings grid can
    // express.
    //
    // Through `registerPage`, not `app.routes`. Core registers ONE admin route,
    // `extension: /extension/:id`, and picks the component through
    // ExtensionPageResolver, which asks the registry for a page registered under
    // the extension's id. Assigning `app.routes['extension.ramon-chat']` therefore
    // created a route that nothing ever matched: the default ExtensionPage kept
    // rendering, so the webhooks list and the announcer panel were simply absent
    // with no error to explain why.
    .registerPage(WebhooksPage)

    // ── Appearance ────────────────────────────────────────────────────────────
    .registerSetting({
      setting: "ramon-chat.title",
      type: "string",
      label: app.translator.trans("ramon-chat.admin.settings.title_label"),
      help: app.translator.trans("ramon-chat.admin.settings.title_help"),
      placeholder: "Chat",
    })
    .registerSetting({
      setting: "ramon-chat.icon",
      type: "string",
      label: app.translator.trans("ramon-chat.admin.settings.icon_label"),
      help: app.translator.trans("ramon-chat.admin.settings.icon_help"),
      placeholder: "fas fa-comments",
    })
    .registerSetting({
      setting: "ramon-chat.show_icon",
      type: "boolean",
      label: app.translator.trans("ramon-chat.admin.settings.show_icon_label"),
      help: app.translator.trans("ramon-chat.admin.settings.show_icon_help"),
    })

    // ── Limits and safety ─────────────────────────────────────────────────────
    .registerSetting({
      setting: "ramon-chat.max_messages_per_second",
      type: "number",
      label: app.translator.trans(
        "ramon-chat.admin.settings.max_messages_per_second",
      ),
      min: 0,
    })
    .registerSetting({
      setting: "ramon-chat.min_message_length",
      type: "number",
      label: app.translator.trans(
        "ramon-chat.admin.settings.min_message_length",
      ),
      min: 1,
    })
    .registerSetting({
      setting: "ramon-chat.max_message_length",
      type: "number",
      label: app.translator.trans(
        "ramon-chat.admin.settings.max_message_length",
      ),
      min: 1,
    })
    .registerSetting({
      setting: "ramon-chat.message_edit_window_minutes",
      type: "number",
      label: app.translator.trans(
        "ramon-chat.admin.settings.message_edit_window_minutes",
      ),
      help: app.translator.trans(
        "ramon-chat.admin.settings.message_edit_window_help",
      ),
      min: 0,
    })

    // ── Retention ─────────────────────────────────────────────────────────────
    .registerSetting({
      setting: "ramon-chat.channel_retention_days",
      type: "number",
      label: app.translator.trans(
        "ramon-chat.admin.settings.channel_retention_days",
      ),
      help: app.translator.trans(
        "ramon-chat.admin.settings.channel_retention_help",
      ),
      min: 0,
    })
    .registerSetting({
      setting: "ramon-chat.dm_retention_days",
      type: "number",
      label: app.translator.trans(
        "ramon-chat.admin.settings.dm_retention_days",
      ),
      help: app.translator.trans("ramon-chat.admin.settings.dm_retention_help"),
      min: 0,
    })

    // ── Uploads ───────────────────────────────────────────────────────────────
    .registerSetting({
      setting: "ramon-chat.allow_uploads",
      type: "boolean",
      label: app.translator.trans("ramon-chat.admin.settings.allow_uploads"),
    })
    .registerSetting({
      setting: "ramon-chat.max_upload_size",
      type: "number",
      label: app.translator.trans("ramon-chat.admin.settings.max_upload_size"),
      min: 0,
    })

    // ── Behaviour ─────────────────────────────────────────────────────────────
    .registerSetting({
      setting: "ramon-chat.threading_default",
      type: "boolean",
      label: app.translator.trans(
        "ramon-chat.admin.settings.threading_default",
      ),
    })
    .registerSetting({
      setting: "ramon-chat.allow_archiving_channels",
      type: "boolean",
      label: app.translator.trans(
        "ramon-chat.admin.settings.allow_archiving_channels",
      ),
    })
    .registerSetting({
      setting: "ramon-chat.send_with_ctrl_enter",
      type: "boolean",
      label: app.translator.trans(
        "ramon-chat.admin.settings.send_with_ctrl_enter",
      ),
      help: app.translator.trans(
        "ramon-chat.admin.settings.send_with_ctrl_enter_help",
      ),
    })

    // A select rather than a boolean plus a file field: the choice is between two
    // shipped sounds and silence, and 'none' is a value of the same setting rather
    // than a second switch that could contradict it.
    .registerSetting({
      setting: "ramon-chat.notification_sound",
      type: "select",
      options: {
        none: app.translator.trans(
          "ramon-chat.admin.settings.sound_none",
          {},
          true,
        ),
        chime: app.translator.trans(
          "ramon-chat.admin.settings.sound_chime",
          {},
          true,
        ),
        alert: app.translator.trans(
          "ramon-chat.admin.settings.sound_alert",
          {},
          true,
        ),
      },
      default: "chime",
      label: app.translator.trans("ramon-chat.admin.settings.sound"),
      help: app.translator.trans("ramon-chat.admin.settings.sound_help"),
    })

    // ── Permissions ───────────────────────────────────────────────────────────
    // Under "Read", not "Create": this one opens the chat rather than making
    // anything in it, and it is the gate every other permission here sits
    // behind — a group without it cannot see a channel, never mind start one.
    // Grouped with `viewForum`, which is the same kind of answer.
    //
    // High priority within that section because it is the master switch: an
    // admin closing the chat to a group looks for it first.
    .registerPermission(
      {
        icon: "fas fa-comments",
        label: app.translator.trans("ramon-chat.admin.permissions.use"),
        permission: "ramon-chat.use",
      },
      "view",
      95,
    )
    // The rest of this group does create something. Priorities descend so it
    // reads in order of how much it grants: starting DMs, then channels.
    .registerPermission(
      {
        icon: "fas fa-envelope",
        label: app.translator.trans(
          "ramon-chat.admin.permissions.start_direct",
        ),
        permission: "ramon-chat.startDirect",
      },
      "start",
      94,
    )
    .registerPermission(
      {
        icon: "fas fa-plus",
        label: app.translator.trans(
          "ramon-chat.admin.permissions.create_channel",
        ),
        permission: "ramon-chat.createChannel",
      },
      "start",
      93,
    )
    .registerPermission(
      {
        icon: "fas fa-pen-to-square",
        label: app.translator.trans(
          "ramon-chat.admin.permissions.edit_channel",
        ),
        permission: "ramon-chat.editChannel",
      },
      "start",
      92,
    )
    .registerPermission(
      {
        icon: "fas fa-note-sticky",
        label: app.translator.trans(
          "ramon-chat.admin.permissions.send_stickers",
        ),
        permission: "ramon-chat.sendStickers",
      },
      "start",
      61,
    )
    .registerPermission(
      {
        icon: "fas fa-paperclip",
        label: app.translator.trans("ramon-chat.admin.permissions.upload"),
        permission: "ramon-chat.upload",
      },
      "reply",
      95,
    )
    .registerPermission(
      {
        icon: "far fa-face-smile",
        label: app.translator.trans("ramon-chat.admin.permissions.react"),
        permission: "ramon-chat.react",
      },
      "reply",
      94,
    )
    .registerPermission(
      {
        icon: "fas fa-at",
        label: app.translator.trans(
          "ramon-chat.admin.permissions.mention_channel_wide",
        ),
        permission: "ramon-chat.mentionChannelWide",
      },
      "reply",
      93,
    )
    .registerPermission(
      {
        icon: "fas fa-comments",
        label: app.translator.trans(
          "ramon-chat.admin.permissions.create_thread",
        ),
        permission: "ramon-chat.createThread",
      },
      "reply",
      92,
    )
    // Not "reply": this one is about the channel's pace, not about what someone
    // may write. Grouped with moderation because that is who tends to hold it,
    // though the whole point of it being separate is that it need not be.
    .registerPermission(
      {
        icon: "fas fa-gauge-high",
        label: app.translator.trans(
          "ramon-chat.admin.permissions.bypass_slow_mode",
        ),
        permission: "ramon-chat.bypassSlowMode",
      },
      "moderate",
      94,
    )

    // Filing a report, not reading the queue. Under "reply" because it is
    // something an ordinary member does; reading what they filed is `moderate`.
    .registerPermission(
      {
        icon: "fas fa-flag",
        label: app.translator.trans(
          "ramon-chat.admin.permissions.flag_message",
        ),
        permission: "ramon-chat.flagMessage",
      },
      "reply",
      91,
    )
    .registerPermission(
      {
        icon: "fas fa-thumbtack",
        label: app.translator.trans("ramon-chat.admin.permissions.pin_message"),
        permission: "ramon-chat.pinMessage",
      },
      "moderate",
      96,
    )
    .registerPermission(
      {
        icon: "fas fa-shield-halved",
        label: app.translator.trans("ramon-chat.admin.permissions.moderate"),
        permission: "ramon-chat.moderate",
      },
      "moderate",
      95,
    );
});
