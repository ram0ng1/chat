import app from "flarum/forum/app";

import type Channel from "../../common/models/Channel";
import chatState from "../state/chat";

/**
 * Answering a channel invitation.
 *
 * One place for both answers because four surfaces offer them: the notification
 * row, the composer of an invited public channel, the browse card and the
 * channel's action menu. Each used to be a candidate for its own request and its
 * own idea of what to do with the result; here the result is always the same.
 *
 * Accepting goes through the invite route rather than `join`. A private channel
 * is not visible to someone who has not joined it, so the model-scoped join
 * endpoint could never find it; the invite route authorises on the invitation
 * and answers with the channel as the member now sees it.
 */

/**
 * Accepts, and returns the channel as the server now serialises it, or null
 * when the invitation was no longer open.
 *
 * The returned record already carries `canPostMessage` and the other
 * capability flags, which is what lets the composer appear at once instead of
 * after a reload.
 */
export async function acceptInvitation(
  channelId: number,
): Promise<Channel | null> {
  const payload = await app.request<any>({
    method: "POST",
    url: `${app.forum.attribute("apiUrl")}/chat/invites/${channelId}/accept`,
  });

  const channel = adoptChannelPayload(payload);

  if (channel) chatState.rememberChannel(channel);

  return channel;
}

export async function declineInvitation(channelId: number): Promise<void> {
  await app.request({
    method: "POST",
    url: `${app.forum.attribute("apiUrl")}/chat/invites/${channelId}/decline`,
  });

  const channel = chatState.channel(channelId);

  // A public channel stays visible after declining; its record just stops
  // saying an invitation is open.
  if (channel) {
    channel.pushAttributes({
      isInvited: false,
      invitedById: null,
      invitedByName: null,
    });
  }
}

/**
 * Pushes a JSON:API channel document into the store and returns the record.
 *
 * Shared by every endpoint that answers with a channel (join, accept, add
 * members), so the store is always the thing that holds the server's answer.
 */
export function adoptChannelPayload(payload: unknown): Channel | null {
  const document = payload as { data?: { id?: string } } | null;

  if (!document?.data?.id) return null;

  const pushed = app.store.pushPayload(document as any) as unknown as
    Channel | Channel[] | null;

  return (Array.isArray(pushed) ? pushed[0] : pushed) ?? null;
}

/**
 * The error text an invitation answer should show, from the server's reply when
 * it gave one and from the local fallback when it did not.
 */
export function invitationErrorText(error: any, fallbackKey: string): string {
  const detail = error?.response?.errors?.[0]?.detail;

  if (typeof detail === "string" && detail !== "") return detail;

  return app.translator.trans(fallbackKey, {}, true) as string;
}
