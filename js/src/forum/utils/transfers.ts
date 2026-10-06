import app from "flarum/forum/app";

import type Channel from "../../common/models/Channel";
import { adoptChannelPayload } from "./invitations";

/**
 * Handing a channel to another member.
 *
 * Three steps on the server (see Service\OwnershipTransfers): the owner starts
 * it and is mailed a code, enters the code, and the member accepts or declines.
 * Every step answers with the channel and its members, pushed into the store
 * here, so whichever surface acted (the members tab, the notification row)
 * redraws from the server's answer rather than from a guess.
 *
 * The code is only ever typed by the owner and posted once; nothing here keeps
 * it, logs it or puts it in the store.
 */
type TransferStep = "" | "/confirm" | "/cancel" | "/accept" | "/decline";

async function transferRequest(
  channelId: number | string,
  step: TransferStep,
  attributes: Record<string, unknown> = {},
): Promise<Channel | null> {
  const payload = await app.request<any>({
    method: "POST",
    url: `${app.forum.attribute("apiUrl")}/chat-channels/${channelId}/transfer${step}`,
    body: { data: { attributes } },
    // Every caller shows the server's answer itself (a wrong code says how
    // many attempts remain); core's default alert on top would show it twice.
    errorHandler: () => undefined,
  });

  return adoptChannelPayload(payload);
}

export function startTransfer(
  channelId: number | string,
  userId: number,
): Promise<Channel | null> {
  return transferRequest(channelId, "", { userId });
}

export function confirmTransfer(
  channelId: number | string,
  code: string,
): Promise<Channel | null> {
  return transferRequest(channelId, "/confirm", { code });
}

export function cancelTransfer(
  channelId: number | string,
): Promise<Channel | null> {
  return transferRequest(channelId, "/cancel");
}

export function acceptTransfer(
  channelId: number | string,
): Promise<Channel | null> {
  return transferRequest(channelId, "/accept");
}

export function declineTransfer(
  channelId: number | string,
): Promise<Channel | null> {
  return transferRequest(channelId, "/decline");
}
