import Model from "flarum/common/Model";
import type User from "flarum/common/models/User";
export default class Upload extends Model {
    fileName: () => string;
    mimeType: () => string | null;
    size: () => number;
    /**
     * Present for images so the client can reserve layout space and avoid a
     * reflow as attachments load.
     */
    width: () => number | null;
    height: () => number | null;
    messageId: () => number | null;
    url: () => string;
    isImage: () => boolean;
    createdAt: () => Date | null | undefined;
    user: () => false | User | null;
    /**
     * A pending upload belongs to a composer session that has not sent yet.
     */
    isPending(): boolean;
    humanSize(): string;
    apiEndpoint(): string;
}
