import Model from "flarum/common/Model";
import type User from "flarum/common/models/User";

export default class Upload extends Model {
  fileName = Model.attribute<string>("fileName");
  mimeType = Model.attribute<string | null>("mimeType");
  size = Model.attribute<number>("size");

  /**
   * Present for images so the client can reserve layout space and avoid a
   * reflow as attachments load.
   */
  width = Model.attribute<number | null>("width");
  height = Model.attribute<number | null>("height");

  messageId = Model.attribute<number | null>("messageId");
  url = Model.attribute<string>("url");
  isImage = Model.attribute<boolean>("isImage");
  createdAt = Model.attribute("createdAt", Model.transformDate);

  user = Model.hasOne<User | null>("user");

  /**
   * A pending upload belongs to a composer session that has not sent yet.
   */
  isPending(): boolean {
    return this.messageId() === null;
  }

  humanSize(): string {
    const bytes = this.size() ?? 0;
    const units = ["B", "KB", "MB", "GB"];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
      value /= 1024;
      unit++;
    }

    return (unit === 0 ? value : value.toFixed(1)) + " " + units[unit];
  }

  apiEndpoint(): string {
    return "/chat-uploads" + (this.exists ? "/" + this.id() : "");
  }
}
