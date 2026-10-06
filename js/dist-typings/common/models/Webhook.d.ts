import Model from "flarum/common/Model";
import type User from "flarum/common/models/User";
import type Channel from "./Channel";
export default class Webhook extends Model {
    name: () => string;
    description: () => string | null;
    /** Overrides for how a delivered message is attributed in the stream. */
    username: () => string | null;
    emoji: () => string | null;
    active: () => boolean;
    channelId: () => number;
    deliveriesCount: () => number;
    lastDeliveredAt: () => Date | null | undefined;
    createdAt: () => Date | null | undefined;
    /**
     * Populated only in the response that minted it — on create and on rotate. Every
     * other response returns null, so a key cannot be read back out of a listing.
     */
    key: () => string | null;
    url: () => string | null;
    channel: () => false | Channel | null;
    creator: () => false | User | null;
    apiEndpoint(): string;
}
