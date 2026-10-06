import type Channel from "../../common/models/Channel";
export declare function startTransfer(channelId: number | string, userId: number): Promise<Channel | null>;
export declare function confirmTransfer(channelId: number | string, code: string): Promise<Channel | null>;
export declare function cancelTransfer(channelId: number | string): Promise<Channel | null>;
export declare function acceptTransfer(channelId: number | string): Promise<Channel | null>;
export declare function declineTransfer(channelId: number | string): Promise<Channel | null>;
