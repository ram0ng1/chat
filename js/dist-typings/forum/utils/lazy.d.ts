/**
 * Carregadores dos chunks preguiçosos do chat.
 *
 * Cada `import()` fica sozinho numa linha curta: o autoChunkNameLoader do
 * flarum-webpack-config só nomeia e registra um import dinâmico escrito numa
 * linha só, e um import quebrado pelo formatador vira um chunk anônimo que o
 * Flarum não sabe servir.
 */
declare const importChatUi: () => Promise<typeof import("../chatUi")>;
/**
 * Devolve o chunk da interface, pedindo-o uma vez só. Uma falha esquece a
 * promessa, para que a próxima tentativa refaça o pedido.
 */
export declare function loadChatUi(): ReturnType<typeof importChatUi>;
export declare const loadChannelFormModal: () => Promise<typeof import("../components/ChannelFormModal")>;
export declare const loadChannelInfoModal: () => Promise<typeof import("../components/ChannelInfoModal")>;
export declare const loadAddMembersModal: () => Promise<typeof import("../components/AddMembersModal")>;
export declare const loadFlagMessageModal: () => Promise<typeof import("../components/FlagMessageModal")>;
export declare const loadMessageTooLongModal: () => Promise<typeof import("../components/MessageTooLongModal")>;
export declare const loadImageLightbox: () => Promise<typeof import("../components/ImageLightbox")>;
export declare const loadStickerPicker: () => Promise<typeof import("../components/StickerPicker")>;
export {};
