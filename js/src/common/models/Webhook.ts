import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
import type Channel from './Channel';

export default class Webhook extends Model {
  name = Model.attribute<string>('name');
  description = Model.attribute<string | null>('description');

  /** Overrides for how a delivered message is attributed in the stream. */
  username = Model.attribute<string | null>('username');
  emoji = Model.attribute<string | null>('emoji');

  active = Model.attribute<boolean>('active');
  channelId = Model.attribute<number>('channelId');

  deliveriesCount = Model.attribute<number>('deliveriesCount');
  lastDeliveredAt = Model.attribute('lastDeliveredAt', Model.transformDate);
  createdAt = Model.attribute('createdAt', Model.transformDate);

  /**
   * Populated only in the response that minted it — on create and on rotate. Every
   * other response returns null, so a key cannot be read back out of a listing.
   */
  key = Model.attribute<string | null>('key');
  url = Model.attribute<string | null>('url');

  channel = Model.hasOne<Channel | null>('channel');
  creator = Model.hasOne<User | null>('creator');

  apiEndpoint(): string {
    return '/chat-webhooks' + (this.exists ? '/' + this.id() : '');
  }
}
