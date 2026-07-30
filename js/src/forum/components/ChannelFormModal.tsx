import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import withAttr from 'flarum/common/utils/withAttr';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import chatState from '../state/chat';
import EmojiPicker from './EmojiPicker';

export interface ChannelFormModalAttrs extends IFormModalAttrs {
  /** Omit to create a channel; pass one to edit it. */
  channel?: Channel;
  /** Called with the saved channel. */
  onSaved?: (channel: Channel) => void;
}

/**
 * Creates or edits a category channel.
 *
 * One component for both because the field set is identical — Discourse shows the
 * same form for "New channel" and the channel's settings tab. Only the title, the
 * submit label and whether the record already exists differ.
 *
 * Extends FormModal, not Modal: `Modal.wrapper()` returns a bare fragment, so a
 * `type="submit"` button inside it has no form and onsubmit never fires.
 */
export default class ChannelFormModal extends FormModal<ChannelFormModalAttrs> {
  private name!: Stream<string>;
  private description!: Stream<string>;
  private emoji!: Stream<string>;
  private tagId!: Stream<string>;
  private threading!: Stream<boolean>;
  private autoJoin!: Stream<boolean>;
  private allowChannelWide!: Stream<boolean>;
  private autoJoinOnReply!: Stream<boolean>;

  oninit(vnode: Mithril.Vnode<ChannelFormModalAttrs>): void {
    super.oninit(vnode);

    const channel = this.attrs.channel;

    this.name = Stream(channel?.name() ?? '');
    this.description = Stream(channel?.description() ?? '');
    this.emoji = Stream(channel?.emoji() ?? '');
    this.tagId = Stream(channel?.tagId() ? String(channel.tagId()) : '');

    this.threading = Stream(
      channel ? Boolean(channel.threadingEnabled()) : Boolean(app.forum.attribute('ramon-chat.threadingDefault'))
    );
    this.autoJoin = Stream(channel ? Boolean(channel.autoJoin()) : false);
    this.allowChannelWide = Stream(channel ? channel.allowChannelWideMentions() !== false : true);
    this.autoJoinOnReply = Stream(channel ? Boolean(channel.autoJoinOnReply()) : false);
  }

  protected isEditing(): boolean {
    return Boolean(this.attrs.channel);
  }

  className(): string {
    return 'ChannelFormModal Modal--small';
  }

  title(): Mithril.Children {
    return app.translator.trans(
      this.isEditing() ? 'ramon-chat.forum.edit_channel.title' : 'ramon-chat.forum.browse.new_channel'
    );
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label>{app.translator.trans('ramon-chat.forum.new_channel.name')}</label>
            <input
              className="FormControl"
              type="text"
              maxlength={100}
              bidi={this.name}
              placeholder={app.translator.trans('ramon-chat.forum.new_channel.name_placeholder', {}, true)}
              disabled={this.loading}
            />
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('ramon-chat.forum.new_channel.description')}</label>
            <textarea className="FormControl" rows={2} maxlength={1000} bidi={this.description} disabled={this.loading} />
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('ramon-chat.forum.new_channel.emoji')}</label>
            <EmojiPicker
              value={this.emoji()}
              onchange={(value: string | null) => this.emoji(value ?? '')}
              disabled={this.loading}
            />
          </div>

          {this.tagOptions()}

          <div className="Form-group">
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.threading()}
                onchange={withAttr('checked', this.threading)}
                disabled={this.loading}
              />
              {app.translator.trans('ramon-chat.forum.info.threading')}
            </label>

            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.allowChannelWide()}
                onchange={withAttr('checked', this.allowChannelWide)}
                disabled={this.loading}
              />
              {app.translator.trans('ramon-chat.forum.settings.channel_wide_mentions')}
            </label>

            {/* Only meaningful for a tag-bound channel: it keys off replies in
                that category. Shown regardless so the intent is discoverable, with
                the help text explaining the dependency. */}
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.autoJoinOnReply()}
                onchange={withAttr('checked', this.autoJoinOnReply)}
                disabled={this.loading}
              />
              {app.translator.trans('ramon-chat.forum.info.auto_join_on_reply')}
            </label>
            <div className="helpText">
              {app.translator.trans('ramon-chat.forum.info.auto_join_on_reply_help')}
            </div>

            {/* Auto-join is admin-only: it can add every account on the forum. */}
            {app.session.user?.attribute<boolean>('isAdmin') !== false ? (
              <>
                <label className="checkbox">
                  <input
                    type="checkbox"
                    checked={this.autoJoin()}
                    onchange={withAttr('checked', this.autoJoin)}
                    disabled={this.loading}
                  />
                  {app.translator.trans('ramon-chat.forum.info.auto_join')}
                </label>
                <div className="helpText">{app.translator.trans('ramon-chat.forum.info.auto_join_help')}</div>
              </>
            ) : null}
          </div>

          <div className="Form-group">
            <Button
              className="Button Button--primary Button--block"
              type="submit"
              loading={this.loading}
              disabled={this.loading || this.name().trim() === ''}
            >
              {app.translator.trans(
                this.isEditing() ? 'ramon-chat.forum.edit_channel.submit' : 'ramon-chat.forum.new_channel.submit'
              )}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  /**
   * Category picker, rendered only when flarum/tags is present. A tag-bound
   * channel inherits that tag's permissions, which is how a restricted category
   * yields a restricted channel.
   */
  protected tagOptions(): Mithril.Children {
    const tags = app.store.all('tags');

    if (!('flarum-tags' in (flarum.extensions ?? {})) || tags.length === 0) return null;

    return (
      <div className="Form-group">
        <label>{app.translator.trans('ramon-chat.forum.new_channel.category')}</label>

        {/* Deliberately not `bidi`. For a <select>, bidi walks node.children and
            reads `option.attrs.value` on each — so it requires flat, literal
            children. A mapped list arrives as a nested array whose entries have no
            `.attrs`, which throws. value + onchange is equivalent and safe. */}
        <select
          className="FormControl"
          value={this.tagId()}
          onchange={withAttr('value', this.tagId)}
          disabled={this.loading}
        >
          <option value="">{app.translator.trans('ramon-chat.forum.new_channel.no_category')}</option>
          {tags
            .filter((tag: any) => !tag.isChild?.())
            .map((tag: any) => (
              <option key={tag.id()} value={String(tag.id())}>
                {tag.name()}
              </option>
            ))}
        </select>

        <div className="helpText">{app.translator.trans('ramon-chat.forum.new_channel.category_help')}</div>
      </div>
    );
  }

  /**
   * Nothing awaits this method, so a rejection here would surface as an unhandled
   * promise rejection rather than as feedback. Every failure path is handled inline.
   */
  onsubmit(e: SubmitEvent): void {
    e.preventDefault();

    if (this.loading) return;

    this.loading = true;

    const editing = this.isEditing();

    const attributes: Record<string, unknown> = {
      name: this.name().trim(),
      description: this.description().trim() || null,
      emoji: this.emoji().trim() || null,
      threadingEnabled: this.threading(),
      allowChannelWideMentions: this.allowChannelWide(),
      autoJoin: this.autoJoin(),
      autoJoinOnReply: this.autoJoinOnReply(),
      tagId: this.tagId() ? Number(this.tagId()) : null,
    };

    // `type` is writable only on create; sending it on update would be rejected.
    if (!editing) {
      attributes.type = 'category';
    }

    const record = this.attrs.channel ?? app.store.createRecord<Channel>('chat-channels');

    record
      .save(attributes)
      .then((channel) => {
        if (!editing && !chatState.channels.some((c) => c.id() === channel.id())) {
          // The server subscribes the creator, so the channel belongs in the
          // sidebar straight away rather than after the next poll.
          chatState.channels.unshift(channel as Channel);
        }

        this.hide();

        if (editing) {
          app.alerts.show({ type: 'success' }, app.translator.trans('ramon-chat.forum.edit_channel.saved'));
        }

        this.attrs.onSaved?.(channel as Channel);
      })
      .catch((error: any) => {
        this.loading = false;

        // FormModal.onerror renders validation errors above the form and refocuses
        // the offending field; anything else gets an alert so it is never silent.
        if (error?.alert) {
          this.onerror(error);
        } else {
          app.alerts.show(
            { type: 'error' },
            error?.response?.errors?.[0]?.detail ??
              app.translator.trans(
                editing ? 'ramon-chat.forum.edit_channel.failed' : 'ramon-chat.forum.new_channel.failed'
              )
          );

          m.redraw();
        }
      });
  }
}
