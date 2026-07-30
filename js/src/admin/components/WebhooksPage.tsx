import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import BotSettings from './BotSettings';
import Switch from 'flarum/common/components/Switch';
import type Mithril from 'mithril';

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
 * then never again — which is why the freshly minted URL is held in component state
 * and called out rather than listed with the rest of the row.
 */
export default class WebhooksPage extends ExtensionPage {
  private webhooks: WebhookModel[] = [];
  private channels: any[] = [];
  // `loading` is taken by ExtensionPage.
  private loadingHooks = true;

  /** URLs revealed this session, keyed by webhook id. Never re-fetchable. */
  private revealed: Record<string, string> = {};

  private draftName = '';
  private draftChannel = '';
  private working = false;

  oninit(vnode: Mithril.Vnode): void {
    super.oninit(vnode);

    this.load();
  }

  /**
   * `super.content()` first, and it is not optional.
   *
   * ExtensionPage.content() is where core renders the entire registered-settings
   * grid — every `registerSetting` call, plus the save and reset buttons. This
   * class overrode it without calling super, so none of those settings were ever
   * drawn: the extension page showed webhooks and nothing else.
   */
  content(vnode: Mithril.VnodeDOM<any, any>) {
    // A fragment, not a wrapper <div>.
    //
    // The base signature wants a single vnode, so the first attempt wrapped both
    // halves in a plain div — and that extra DOM node broke the layout the core
    // stylesheet builds around `.ExtensionPage-settings` being where it expects it,
    // leaving the webhook form as a column of stretched boxes. A fragment satisfies
    // the signature without adding an element, so the cascade is untouched.
    //
    // Both children are unkeyed, which matters: Mithril decides a fragment is keyed
    // from its first child and then demands every sibling be keyed too.
    return (
      <>
        {super.content(vnode)}

        <div className="ExtensionPage-settings">
          <div className="container">
            {/* Who the chat posts as. Its own component rather than settings rows
                because the choice is exclusive — an announcing account makes the
                bot's name and picture moot, so the form has to collapse rather
                than show controls that do nothing. */}
            <h3>{app.translator.trans('ramon-chat.admin.bot.title')}</h3>
            <BotSettings />

            <h3>{app.translator.trans('ramon-chat.admin.webhooks.title')}</h3>
            <p className="helpText">{app.translator.trans('ramon-chat.admin.webhooks.help')}</p>

            {this.loadingHooks ? <LoadingIndicator /> : [this.createForm(), this.list()]}
          </div>
        </div>
      </>
    );
  }

  protected createForm(): Mithril.Children {
    return (
      <div className="Form-group ChatWebhooks-create">
        <input
          className="FormControl"
          placeholder={app.translator.trans('ramon-chat.admin.webhooks.name', {}, true)}
          value={this.draftName}
          oninput={(e: Event) => {
            this.draftName = (e.target as HTMLInputElement).value;
          }}
        />

        <select
          className="FormControl"
          value={this.draftChannel}
          onchange={(e: Event) => {
            this.draftChannel = (e.target as HTMLSelectElement).value;
          }}
        >
          <option value="">{app.translator.trans('ramon-chat.admin.webhooks.pick_channel', {}, true)}</option>
          {this.channels.map((channel) => (
            <option key={channel.id()} value={String(channel.id())}>
              {channel.attribute('name') ?? channel.attribute('displayName')}
            </option>
          ))}
        </select>

        <Button
          className="Button Button--primary"
          loading={this.working}
          disabled={!this.draftName.trim() || !this.draftChannel}
          onclick={() => this.create()}
        >
          {app.translator.trans('ramon-chat.admin.webhooks.create')}
        </Button>
      </div>
    );
  }

  protected list(): Mithril.Children {
    if (this.webhooks.length === 0) {
      return <p className="helpText">{app.translator.trans('ramon-chat.admin.webhooks.empty')}</p>;
    }

    return (
      <div className="ChatWebhooks-list">
        {this.webhooks.map((webhook) => this.row(webhook))}
      </div>
    );
  }

  protected row(webhook: WebhookModel): Mithril.Children {
    const id = webhook.id();
    const url = this.revealed[id];

    return (
      <div className="ChatWebhooks-row" key={id}>
        <div className="ChatWebhooks-row-main">
          <strong>{webhook.attribute<string>('name')}</strong>

          <span className="ChatWebhooks-row-meta">
            {app.translator.trans('ramon-chat.admin.webhooks.deliveries', {
              count: webhook.attribute<number>('deliveriesCount') ?? 0,
            })}
          </span>
        </div>

        {url ? (
          <div className="ChatWebhooks-row-url">
            <p className="helpText">{app.translator.trans('ramon-chat.admin.webhooks.url_once')}</p>
            <input className="FormControl" readonly value={url} onclick={(e: Event) => (e.target as HTMLInputElement).select()} />
          </div>
        ) : null}

        <div className="ChatWebhooks-row-actions">
          <Switch
            state={Boolean(webhook.attribute<boolean>('active'))}
            onchange={(value: boolean) => this.setActive(webhook, value)}
          >
            {app.translator.trans('ramon-chat.admin.webhooks.active')}
          </Switch>

          <Button className="Button Button--text" icon="fas fa-rotate" onclick={() => this.rotate(webhook)}>
            {app.translator.trans('ramon-chat.admin.webhooks.rotate')}
          </Button>

          <Button className="Button Button--text" icon="fas fa-trash" onclick={() => this.remove(webhook)}>
            {app.translator.trans('ramon-chat.admin.webhooks.delete')}
          </Button>
        </div>
      </div>
    );
  }

  // ── Data ───────────────────────────────────────────────────────────────────

  protected async load(): Promise<void> {
    try {
      const [webhooks, channels] = await Promise.all([
        app.store.find('chat-webhooks', { include: 'channel' }),
        app.store.find('chat-channels', { filter: { type: 'category' }, page: { limit: 100 } }),
      ]);

      this.webhooks = (Array.isArray(webhooks) ? webhooks : []) as unknown as WebhookModel[];
      this.channels = Array.isArray(channels) ? channels : [];
    } catch {
      this.webhooks = [];
      this.channels = [];
    } finally {
      this.loadingHooks = false;
      m.redraw();
    }
  }

  protected async create(): Promise<void> {
    this.working = true;

    try {
      const webhook = (await app.store.createRecord('chat-webhooks').save({
        name: this.draftName.trim(),
        channelId: Number(this.draftChannel),
      })) as unknown as WebhookModel;

      this.webhooks = [webhook, ...this.webhooks];
      this.remember(webhook);

      this.draftName = '';
      this.draftChannel = '';
    } catch (e: any) {
      app.alerts.show(
        { type: 'error' },
        e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.admin.webhooks.failed')
      );
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected async setActive(webhook: WebhookModel, active: boolean): Promise<void> {
    webhook.pushAttributes({ active });
    m.redraw();

    try {
      await webhook.save({ active });
    } catch {
      webhook.pushAttributes({ active: !active });
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.admin.webhooks.failed'));
    } finally {
      m.redraw();
    }
  }

  protected async rotate(webhook: WebhookModel): Promise<void> {
    if (!confirm(app.translator.trans('ramon-chat.admin.webhooks.rotate_confirm', {}, true))) return;

    try {
      const payload = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-webhooks/${webhook.id()}/rotate`,
        body: { data: { attributes: {} } },
      });

      app.store.pushPayload(payload);

      // Read from the raw payload, not the model: `url` is null in every other
      // response, so pushing it would immediately overwrite what we just showed.
      const url = payload?.data?.attributes?.url;

      if (url) this.revealed[webhook.id()] = url;
    } catch {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.admin.webhooks.failed'));
    } finally {
      m.redraw();
    }
  }

  protected async remove(webhook: WebhookModel): Promise<void> {
    if (!confirm(app.translator.trans('ramon-chat.admin.webhooks.delete_confirm', {}, true))) return;

    try {
      await webhook.delete();

      this.webhooks = this.webhooks.filter((row) => row.id() !== webhook.id());
    } catch {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.admin.webhooks.failed'));
    } finally {
      m.redraw();
    }
  }

  protected remember(webhook: WebhookModel): void {
    const url = webhook.attribute<string | null>('url');

    if (url) this.revealed[webhook.id()] = url;
  }
}
