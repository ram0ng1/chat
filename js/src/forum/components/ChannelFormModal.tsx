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
  private isPrivate!: Stream<boolean>;

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

    // Public by default: a channel nobody can find is the surprising outcome, so
    // it has to be chosen rather than fallen into.
    this.isPrivate = Stream(channel ? Boolean(channel.isPrivate()) : false);
  }

  protected isEditing(): boolean {
    return Boolean(this.attrs.channel);
  }

  className(): string {
    return 'ChatModal ChannelFormModal Modal--small';
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

          {this.visibility()}
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

          {this.lifecycle()}
        </div>
      </div>
    );
  }

  /**
   * Public or invitation-only.
   *
   * Radios rather than a checkbox: the two options are named, and "private" is a
   * decision about who can find the channel at all — not a toggle whose unchecked
   * state should be inferred from its label.
   */
  protected visibility(): Mithril.Children {
    return (
      <div className="Form-group">
        <label>{app.translator.trans('ramon-chat.forum.new_channel.visibility')}</label>

        <label className="checkbox">
          <input
            type="radio"
            name="ramon-chat-visibility"
            checked={!this.isPrivate()}
            onchange={() => this.isPrivate(false)}
            disabled={this.loading}
          />
          {app.translator.trans('ramon-chat.forum.new_channel.public')}
        </label>
        <div className="helpText">{app.translator.trans('ramon-chat.forum.new_channel.public_help')}</div>

        <label className="checkbox">
          <input
            type="radio"
            name="ramon-chat-visibility"
            checked={this.isPrivate()}
            onchange={() => this.isPrivate(true)}
            disabled={this.loading}
          />
          {app.translator.trans('ramon-chat.forum.new_channel.private')}
        </label>
        <div className="helpText">{app.translator.trans('ramon-chat.forum.new_channel.private_help')}</div>
      </div>
    );
  }

  /**
   * Close and archive, on an existing channel only.
   *
   * Neither means anything for a channel that does not exist yet, and both act
   * immediately rather than on submit — they are not settings being edited, they
   * are state changes, and mixing them into the form's save would make an
   * unsaved-and-abandoned form able to archive something.
   */
  protected lifecycle(): Mithril.Children {
    const channel = this.attrs.channel;

    if (!channel) return null;

    const closed = channel.status() === 'closed';
    const archived = Boolean(channel.archivedAt());
    const items: Mithril.Children[] = [];

    if (channel.canClose() && !archived) {
      items.push(
        <Button
          className="Button"
          icon={closed ? 'fas fa-lock-open' : 'fas fa-lock'}
          loading={this.loading}
          onclick={() => this.setStatus(closed ? 'open' : 'closed')}
        >
          {app.translator.trans(
            closed ? 'ramon-chat.forum.info.reopen_channel' : 'ramon-chat.forum.info.close_channel'
          )}
        </Button>
      );
    }

    if (channel.canArchive() && !archived) {
      items.push(
        <Button className="Button" icon="fas fa-box-archive" loading={this.loading} onclick={() => this.archive()}>
          {app.translator.trans('ramon-chat.forum.info.archive_channel')}
        </Button>
      );
    }

    if (items.length === 0) return null;

    return (
      <div className="Form-group ChannelFormModal-lifecycle">
        <label>{app.translator.trans('ramon-chat.forum.edit_channel.lifecycle')}</label>
        <div className="ChannelFormModal-lifecycleActions">{items}</div>
        <div className="helpText">{app.translator.trans('ramon-chat.forum.edit_channel.lifecycle_help')}</div>
      </div>
    );
  }

  protected async setStatus(status: 'open' | 'closed'): Promise<void> {
    await this.act(`/chat-channels/${this.attrs.channel!.id()}/status`, { status });
  }

  protected async archive(): Promise<void> {
    if (!confirm(app.translator.trans('ramon-chat.forum.edit_channel.archive_confirm', {}, true))) return;

    await this.act(`/chat-channels/${this.attrs.channel!.id()}/archive`, {});
  }

  protected async act(path: string, attributes: Record<string, unknown>): Promise<void> {
    this.loading = true;
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}${path}`,
        body: { data: { attributes } },
      });

      if (payload?.data) app.store.pushPayload(payload);

      this.attrs.onSaved?.(this.attrs.channel!);
      this.hide();
    } catch (e: any) {
      app.alerts.show(
        { type: 'error' },
        e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.edit_channel.failed')
      );
    } finally {
      this.loading = false;
      m.redraw();
    }
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
      isPrivate: this.isPrivate(),
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
