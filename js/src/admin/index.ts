import app from "flarum/admin/app";
import { extend } from "flarum/common/extend";
import PermissionGrid from "flarum/admin/components/PermissionGrid";
import type { PermissionConfig } from "flarum/admin/components/PermissionGrid";
import type Mithril from "mithril";

import Channel from "../common/models/Channel";
import Webhook from "../common/models/Webhook";
import ChatSettingsPage from "./components/ChatSettingsPage";
import WebhooksPanel from "./components/WebhooksPanel";
import BotSettings from "./components/BotSettings";

export { ChatSettingsPage, WebhooksPanel, BotSettings, Webhook };

app.initializers.add("ramon-chat", () => {
  // The admin store is a separate instance from the forum's, so these have to be
  // registered here as well or the webhook page's payloads are dropped for want of
  // a model.
  app.store.models["chat-webhooks"] = Webhook;
  app.store.models["chat-channels"] = Channel;

  const trans = (key: string) =>
    app.translator.trans(`ramon-chat.admin.${key}`);
  const text = (key: string) =>
    app.translator.trans(`ramon-chat.admin.${key}`, {}, true) as string;

  // Flarum 2 exposes the admin registration surface as `app.registry`
  // (AdminRegistry). `app.extensionData` was the Flarum 1 name and does not
  // exist here; reaching for it throws during initialize(), before the admin
  // app has mounted anything.
  //
  // The settings below are registered the ordinary way, so the registry stays
  // the one place each field is defined. What changes is the page: core's
  // ExtensionPage would draw them as one undifferentiated column, and
  // ChatSettingsPage lays the same fields out in titled sections instead.
  // Through `registerPage`, not `app.routes`: core registers ONE admin route
  // and picks the component through ExtensionPageResolver, which asks the
  // registry for a page registered under the extension's id.
  app.registry
    .for("ramon-chat")
    .registerPage(ChatSettingsPage)

    // ── Channels ──────────────────────────────────────────────────────────────
    // Who creates and runs channels is the decision the permission grid depends
    // on: in "administrators" mode nobody else creates a channel, whatever the
    // grid says; in "members" mode whoever may create a channel runs the ones
    // they created, and the grid grows a second block for exactly those rows.
    .registerSetting({
      setting: "ramon-chat.channel_ownership",
      type: "select",
      options: {
        admin: text("settings.channel_ownership_admin"),
        members: text("settings.channel_ownership_members"),
      },
      default: "admin",
      label: trans("settings.channel_ownership"),
      help: trans("settings.channel_ownership_help"),
    })
    .registerSetting({
      setting: "ramon-chat.allow_archiving_channels",
      type: "boolean",
      label: trans("settings.allow_archiving_channels"),
      help: trans("settings.allow_archiving_channels_help"),
    })
    .registerSetting({
      setting: "ramon-chat.threading_default",
      type: "boolean",
      label: trans("settings.threading_default"),
      help: trans("settings.threading_default_help"),
    })

    // ── Appearance ────────────────────────────────────────────────────────────
    .registerSetting({
      setting: "ramon-chat.title",
      type: "string",
      label: trans("settings.title_label"),
      help: trans("settings.title_help"),
      placeholder: "Chat",
    })
    .registerSetting({
      setting: "ramon-chat.icon",
      type: "string",
      label: trans("settings.icon_label"),
      help: trans("settings.icon_help"),
      placeholder: "fas fa-comments",
    })
    .registerSetting({
      setting: "ramon-chat.show_icon",
      type: "boolean",
      label: trans("settings.show_icon_label"),
      help: trans("settings.show_icon_help"),
    })

    // ── Messages ──────────────────────────────────────────────────────────────
    .registerSetting({
      setting: "ramon-chat.max_message_length",
      type: "number",
      label: trans("settings.max_message_length"),
      help: trans("settings.max_message_length_help"),
      min: 1,
    })
    .registerSetting({
      setting: "ramon-chat.min_message_length",
      type: "number",
      label: trans("settings.min_message_length"),
      min: 1,
    })
    .registerSetting({
      setting: "ramon-chat.max_messages_per_second",
      type: "number",
      label: trans("settings.max_messages_per_second"),
      help: trans("settings.max_messages_per_second_help"),
      min: 0,
    })
    .registerSetting({
      setting: "ramon-chat.message_edit_window_minutes",
      type: "number",
      label: trans("settings.message_edit_window_minutes"),
      help: trans("settings.message_edit_window_help"),
      min: 0,
    })

    // ── Uploads ───────────────────────────────────────────────────────────────
    .registerSetting({
      setting: "ramon-chat.allow_uploads",
      type: "boolean",
      label: trans("settings.allow_uploads"),
      help: trans("settings.allow_uploads_help"),
    })
    .registerSetting({
      setting: "ramon-chat.max_upload_size",
      type: "number",
      label: trans("settings.max_upload_size"),
      help: trans("settings.max_upload_size_help"),
      min: 0,
    })

    // ── Retention ─────────────────────────────────────────────────────────────
    .registerSetting({
      setting: "ramon-chat.channel_retention_days",
      type: "number",
      label: trans("settings.channel_retention_days"),
      help: trans("settings.channel_retention_help"),
      min: 0,
    })
    .registerSetting({
      setting: "ramon-chat.dm_retention_days",
      type: "number",
      label: trans("settings.dm_retention_days"),
      help: trans("settings.dm_retention_help"),
      min: 0,
    })

    // ── Realtime and sound ────────────────────────────────────────────────────
    .registerSetting({
      setting: "ramon-chat.queue_realtime",
      type: "boolean",
      label: trans("settings.queue_realtime"),
      help: trans("settings.queue_realtime_help"),
    })

    // A select rather than a boolean plus a file field: the choice is between two
    // shipped sounds and silence, and 'none' is a value of the same setting rather
    // than a second switch that could contradict it.
    .registerSetting({
      setting: "ramon-chat.notification_sound",
      type: "select",
      options: {
        none: text("settings.sound_none"),
        chime: text("settings.sound_chime"),
        alert: text("settings.sound_alert"),
      },
      default: "chime",
      label: trans("settings.sound"),
      help: trans("settings.sound_help"),
    })

    // ── Webhooks ──────────────────────────────────────────────────────────────
    // The switch for the feature. Drawn at the top of the webhooks card, so the
    // records under it are read as "what this switch turns on".
    .registerSetting({
      setting: "ramon-chat.webhooks_enabled",
      type: "boolean",
      label: trans("settings.webhooks_enabled"),
      help: trans("settings.webhooks_enabled_help"),
    })

    // ── Permissions ───────────────────────────────────────────────────────────
    // Registered under core sections like every other row, so the registry
    // knows the extension has permissions at all (the extension page draws its
    // grid only then). Where they are actually shown is decided below, in the
    // permissionItems extension.
    .registerPermission(
      {
        icon: "fas fa-comments",
        label: trans("permissions.use"),
        permission: "ramon-chat.use",
      },
      "view",
      95,
    )
    .registerPermission(
      {
        icon: "fas fa-lock-open",
        label: trans("permissions.access_private_channels"),
        permission: "ramon-chat.accessPrivateChannels",
      },
      "view",
      94,
    )
    .registerPermission(
      {
        icon: "fas fa-envelope",
        label: trans("permissions.start_direct"),
        permission: "ramon-chat.startDirect",
      },
      "start",
      94,
    )
    .registerPermission(
      {
        icon: "fas fa-note-sticky",
        label: trans("permissions.send_stickers"),
        permission: "ramon-chat.sendStickers",
      },
      "start",
      61,
    )
    .registerPermission(
      {
        icon: "fas fa-paperclip",
        label: trans("permissions.upload"),
        permission: "ramon-chat.upload",
      },
      "reply",
      95,
    )
    .registerPermission(
      {
        icon: "far fa-face-smile",
        label: trans("permissions.react"),
        permission: "ramon-chat.react",
      },
      "reply",
      94,
    )
    .registerPermission(
      {
        icon: "fas fa-at",
        label: trans("permissions.mention_channel_wide"),
        permission: "ramon-chat.mentionChannelWide",
      },
      "reply",
      93,
    )
    .registerPermission(
      {
        icon: "fas fa-comments",
        label: trans("permissions.create_thread"),
        permission: "ramon-chat.createThread",
      },
      "reply",
      92,
    )
    .registerPermission(
      {
        icon: "fas fa-gauge-high",
        label: trans("permissions.bypass_slow_mode"),
        permission: "ramon-chat.bypassSlowMode",
      },
      "moderate",
      94,
    )
    .registerPermission(
      {
        icon: "fas fa-flag",
        label: trans("permissions.flag_message"),
        permission: "ramon-chat.flagMessage",
      },
      "reply",
      91,
    )
    .registerPermission(
      {
        icon: "fas fa-thumbtack",
        label: trans("permissions.pin_message"),
        permission: "ramon-chat.pinMessage",
      },
      "moderate",
      96,
    )
    .registerPermission(
      {
        icon: "fas fa-shield-halved",
        label: trans("permissions.moderate"),
        permission: "ramon-chat.moderate",
      },
      "moderate",
      95,
    )
    // Observing a room unnoticed: no place in the member list, no arrival or
    // departure announced, no notification written. Separate from `moderate`
    // so an administration or audit group can hold it on its own.
    .registerPermission(
      {
        icon: "fas fa-user-secret",
        label: trans("permissions.inspect_channels"),
        permission: "ramon-chat.inspectChannels",
      },
      "moderate",
      92,
    )
    .registerPermission(
      {
        icon: "fas fa-plus",
        label: trans("permissions.create_channel"),
        permission: "ramon-chat.createChannel",
      },
      "start",
      93,
    )
    .registerPermission(
      {
        icon: "fas fa-user-shield",
        label: trans("permissions.manage_own_channels"),
        permission: "ramon-chat.manageOwnChannels",
      },
      "start",
      92,
    )
    .registerPermission(
      {
        icon: "fas fa-user-pen",
        label: trans("permissions.edit_own_channels"),
        permission: "ramon-chat.editOwnChannels",
      },
      "start",
      91,
    )
    .registerPermission(
      {
        icon: "fas fa-pen-to-square",
        label: trans("permissions.edit_channel"),
        permission: "ramon-chat.editChannel",
      },
      "moderate",
      93,
    );

  // ── How the grid is organised ─────────────────────────────────────────────
  // Core sorts every extension's rows into Read / Create / Participate /
  // Moderate. For the chat that hides the one distinction an operator needs:
  // what applies to the whole chat, and what members get in the channels they
  // create and run. So the chat's rows are lifted out of the core sections and
  // laid out as two blocks of its own, "Chat: global" first and "Chat: channels
  // run by members" below it. Each block's heading says in plain words when it
  // is in force. The second block exists only while channels are in members'
  // hands (the ownership setting): in "administrators" mode nothing in it would
  // do anything, so it is not shown, and the participation rows it would hold
  // sit in the global block instead.
  //
  // Done in permissionItems() rather than through registerPermission(): the
  // registry only knows the four core sections, and the extension page's grid
  // calls this same method, so both grids get the same layout.
  extend(PermissionGrid.prototype, "permissionItems", function (items) {
    const membersMode =
      app.data.settings["ramon-chat.channel_ownership"] === "members";

    // Lift every chat row out of the core sections, keeping its config.
    const ours = new Map<string, PermissionConfig>();

    for (const type of ["view", "start", "reply", "moderate"] as const) {
      if (!items.has(type)) continue;

      const section = items.get(type);

      section.children = section.children.filter((entry) => {
        const permission =
          "permission" in entry ? (entry.permission ?? "") : "";

        if (!permission.startsWith("ramon-chat.")) return true;

        ours.set(permission, entry as PermissionConfig);

        return false;
      });
    }

    const pick = (keys: readonly string[]): PermissionConfig[] =>
      keys
        .map((key) => ours.get(key))
        .filter((entry): entry is PermissionConfig => Boolean(entry));

    const global = pick(
      membersMode
        ? GLOBAL_PERMISSIONS
        : [...GLOBAL_PERMISSIONS, ...PARTICIPATION_PERMISSIONS],
    );

    if (global.length > 0) {
      items.add(
        "ramon-chat-global",
        {
          label: heading(
            "permissions.global_heading",
            "permissions.global_heading_help",
          ),
          children: global,
        },
        60,
      );
    }

    if (!membersMode) return;

    const members = pick([
      ...OWN_CHANNEL_PERMISSIONS,
      ...PARTICIPATION_PERMISSIONS,
    ]);

    if (members.length > 0) {
      items.add(
        "ramon-chat-members",
        {
          label: heading(
            "permissions.members_heading",
            "permissions.members_heading_help",
          ),
          children: members,
        },
        55,
      );
    }
  });

  /**
   * A section heading with a second line saying when the block is in force.
   *
   * Hyperscript rather than JSX because this file is plain TypeScript; the
   * grid accepts any vnode as a label.
   */
  function heading(titleKey: string, helpKey: string): Mithril.Children {
    return m("span.ChatPermissionHeading", [
      m("span.ChatPermissionHeading-title", trans(titleKey)),
      m("span.ChatPermissionHeading-help", trans(helpKey)),
    ]);
  }
});

/**
 * Applies to the whole chat, in every channel, whatever the ownership mode.
 * Listed top to bottom in the order the grid shows them: reading, then
 * taking part, then moderating.
 */
const GLOBAL_PERMISSIONS = [
  "ramon-chat.use",
  "ramon-chat.accessPrivateChannels",
  "ramon-chat.startDirect",
  "ramon-chat.mentionChannelWide",
  "ramon-chat.flagMessage",
  "ramon-chat.bypassSlowMode",
  "ramon-chat.pinMessage",
  "ramon-chat.moderate",
  "ramon-chat.inspectChannels",
  "ramon-chat.editChannel",
] as const;

/**
 * What members do inside channels: files, stickers, reactions, threads. Global
 * while administrators run the channels; part of the members block once
 * members do.
 */
const PARTICIPATION_PERMISSIONS = [
  "ramon-chat.upload",
  "ramon-chat.sendStickers",
  "ramon-chat.react",
  "ramon-chat.createThread",
] as const;

/** Creating and running one's own channels. Members mode only. */
const OWN_CHANNEL_PERMISSIONS = [
  "ramon-chat.createChannel",
  "ramon-chat.manageOwnChannels",
  "ramon-chat.editOwnChannels",
] as const;
