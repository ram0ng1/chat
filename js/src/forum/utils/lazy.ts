/**
 * Carregadores dos chunks preguiçosos do chat.
 *
 * Cada `import()` fica sozinho numa linha curta: o autoChunkNameLoader do
 * flarum-webpack-config só nomeia e registra um import dinâmico escrito numa
 * linha só, e um import quebrado pelo formatador vira um chunk anônimo que o
 * Flarum não sabe servir.
 */

const importChatUi = () => import("../chatUi");

let chatUi: ReturnType<typeof importChatUi> | null = null;

/**
 * Devolve o chunk da interface, pedindo-o uma vez só. Uma falha esquece a
 * promessa, para que a próxima tentativa refaça o pedido.
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
