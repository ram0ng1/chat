import app from 'flarum/admin/app';

import Channel from '../common/models/Channel';
import Webhook from '../common/models/Webhook';
import WebhooksPage from './components/WebhooksPage';

export { WebhooksPage, Webhook };

app.initializers.add('ramon-chat', () => {
  // The admin store is a separate instance from the forum's, so these have to be
  // registered here as well or the webhook page's payloads are dropped for want of
  // a model.
  app.store.models['chat-webhooks'] = Webhook;
  app.store.models['chat-channels'] = Channel;

  // The extension page is replaced wholesale rather than adding webhooks as one
  // more setting: webhook rows are records, not settings, and the settings grid
  // has no shape for a list with per-row actions. WebhooksPage extends
  // ExtensionPage, so the standard settings surface is still rendered above it.
  app.routes['extension.ramon-chat'] = { path: '/extension/ramon-chat', component: WebhooksPage };

  // Flarum 2 exposes the admin registration surface as `app.registry`
  // (AdminRegistry). `app.extensionData` was the Flarum 1 name and does not
  // exist here — reaching for it throws during initialize(), before the admin
  // app has mounted anything.
  app.registry
    .for('ramon-chat')

    // ── Appearance ────────────────────────────────────────────────────────────
    .registerSetting({
      setting: 'ramon-chat.title',
      type: 'string',
      label: app.translator.trans('ramon-chat.admin.settings.title_label'),
      help: app.translator.trans('ramon-chat.admin.settings.title_help'),
      placeholder: 'Chat',
    })
    .registerSetting({
      setting: 'ramon-chat.icon',
      type: 'string',
      label: app.translator.trans('ramon-chat.admin.settings.icon_label'),
      help: app.translator.trans('ramon-chat.admin.settings.icon_help'),
      placeholder: 'fas fa-comments',
    })
    .registerSetting({
      setting: 'ramon-chat.show_icon',
      type: 'boolean',
      label: app.translator.trans('ramon-chat.admin.settings.show_icon_label'),
      help: app.translator.trans('ramon-chat.admin.settings.show_icon_help'),
    })

    // ── Limits and safety ─────────────────────────────────────────────────────
    .registerSetting({
      setting: 'ramon-chat.max_messages_per_second',
      type: 'number',
      label: app.translator.trans('ramon-chat.admin.settings.max_messages_per_second'),
      min: 0,
    })
    .registerSetting({
      setting: 'ramon-chat.min_message_length',
      type: 'number',
      label: app.translator.trans('ramon-chat.admin.settings.min_message_length'),
      min: 1,
    })
    .registerSetting({
      setting: 'ramon-chat.max_message_length',
      type: 'number',
      label: app.translator.trans('ramon-chat.admin.settings.max_message_length'),
      min: 1,
    })
    .registerSetting({
      setting: 'ramon-chat.message_edit_window_minutes',
      type: 'number',
      label: app.translator.trans('ramon-chat.admin.settings.message_edit_window_minutes'),
      help: app.translator.trans('ramon-chat.admin.settings.message_edit_window_help'),
      min: 0,
    })

    // ── Retention ─────────────────────────────────────────────────────────────
    .registerSetting({
      setting: 'ramon-chat.channel_retention_days',
      type: 'number',
      label: app.translator.trans('ramon-chat.admin.settings.channel_retention_days'),
      help: app.translator.trans('ramon-chat.admin.settings.channel_retention_help'),
      min: 0,
    })
    .registerSetting({
      setting: 'ramon-chat.dm_retention_days',
      type: 'number',
      label: app.translator.trans('ramon-chat.admin.settings.dm_retention_days'),
      help: app.translator.trans('ramon-chat.admin.settings.dm_retention_help'),
      min: 0,
    })

    // ── Uploads ───────────────────────────────────────────────────────────────
    .registerSetting({
      setting: 'ramon-chat.allow_uploads',
      type: 'boolean',
      label: app.translator.trans('ramon-chat.admin.settings.allow_uploads'),
    })
    .registerSetting({
      setting: 'ramon-chat.max_upload_size',
      type: 'number',
      label: app.translator.trans('ramon-chat.admin.settings.max_upload_size'),
      min: 0,
    })

    // ── Behaviour ─────────────────────────────────────────────────────────────
    .registerSetting({
      setting: 'ramon-chat.default_reactions',
      type: 'string',
      label: app.translator.trans('ramon-chat.admin.settings.default_reactions'),
      help: app.translator.trans('ramon-chat.admin.settings.default_reactions_help'),
    })
    .registerSetting({
      setting: 'ramon-chat.threading_default',
      type: 'boolean',
      label: app.translator.trans('ramon-chat.admin.settings.threading_default'),
    })
    .registerSetting({
      setting: 'ramon-chat.allow_archiving_channels',
      type: 'boolean',
      label: app.translator.trans('ramon-chat.admin.settings.allow_archiving_channels'),
    })

    // ── Permissions ───────────────────────────────────────────────────────────
    // Priorities descend so the group reads in order of how much it grants:
    // using chat, then starting DMs, then creating channels.
    .registerPermission(
      {
        icon: 'fas fa-comments',
        label: app.translator.trans('ramon-chat.admin.permissions.use'),
        permission: 'ramon-chat.use',
      },
      'start',
      95
    )
    .registerPermission(
      {
        icon: 'fas fa-envelope',
        label: app.translator.trans('ramon-chat.admin.permissions.start_direct'),
        permission: 'ramon-chat.startDirect',
      },
      'start',
      94
    )
    .registerPermission(
      {
        icon: 'fas fa-plus',
        label: app.translator.trans('ramon-chat.admin.permissions.create_channel'),
        permission: 'ramon-chat.createChannel',
      },
      'start',
      93
    )
    .registerPermission(
      {
        icon: 'fas fa-pen-to-square',
        label: app.translator.trans('ramon-chat.admin.permissions.edit_channel'),
        permission: 'ramon-chat.editChannel',
      },
      'start',
      92
    )
    .registerPermission(
      {
        icon: 'fas fa-paperclip',
        label: app.translator.trans('ramon-chat.admin.permissions.upload'),
        permission: 'ramon-chat.upload',
      },
      'reply',
      95
    )
    .registerPermission(
      {
        icon: 'far fa-face-smile',
        label: app.translator.trans('ramon-chat.admin.permissions.react'),
        permission: 'ramon-chat.react',
      },
      'reply',
      94
    )
    .registerPermission(
      {
        icon: 'fas fa-at',
        label: app.translator.trans('ramon-chat.admin.permissions.mention_channel_wide'),
        permission: 'ramon-chat.mentionChannelWide',
      },
      'reply',
      93
    )
    .registerPermission(
      {
        icon: 'fas fa-comments',
        label: app.translator.trans('ramon-chat.admin.permissions.create_thread'),
        permission: 'ramon-chat.createThread',
      },
      'reply',
      92
    )
    .registerPermission(
      {
        icon: 'fas fa-thumbtack',
        label: app.translator.trans('ramon-chat.admin.permissions.pin_message'),
        permission: 'ramon-chat.pinMessage',
      },
      'moderate',
      96
    )
    .registerPermission(
      {
        icon: 'fas fa-shield-halved',
        label: app.translator.trans('ramon-chat.admin.permissions.moderate'),
        permission: 'ramon-chat.moderate',
      },
      'moderate',
      95
    );
});
