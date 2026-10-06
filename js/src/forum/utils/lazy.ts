/**
 * Loaders for the chat's lazy chunks.
 *
 * Each `import()` sits alone on a short line: flarum-webpack-config's
 * autoChunkNameLoader only names and registers a dynamic import written on a
 * single line, and an import broken up by the formatter becomes an anonymous
 * chunk that Flarum cannot serve.
 */

const importChatUi = () => import("../chatUi");

let chatUi: ReturnType<typeof importChatUi> | null = null;

/**
 * Returns the interface chunk, requesting it only once. A failure forgets the
 * promise, so the next attempt retries the request.
 */
export function loadChatUi(): ReturnType<typeof importChatUi> {
  chatUi ??= importChatUi().catch((error) => {
    chatUi = null;
    throw error;
  });

  return chatUi;
}

export const loadChannelFormModal = () =>
  import("../components/ChannelFormModal");
export const loadChannelInfoModal = () =>
  import("../components/ChannelInfoModal");
export const loadAddMembersModal = () =>
  import("../components/AddMembersModal");
export const loadFlagMessageModal = () =>
  import("../components/FlagMessageModal");
export const loadMessageTooLongModal = () =>
  import("../components/MessageTooLongModal");
export const loadImageLightbox = () => import("../components/ImageLightbox");
export const loadStickerPicker = () => import("../components/StickerPicker");
