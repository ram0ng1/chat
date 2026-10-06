/**
 * Loaders for the chat's lazy chunks.
 *
 * Each `import()` sits alone on a short line: flarum-webpack-config's
 * autoChunkNameLoader only names and registers a dynamic import written on a
 * single line, and an import broken up by the formatter becomes an anonymous
 * chunk that Flarum cannot serve.
 */
declare const importChatUi: () => Promise<typeof import("../chatUi")>;
/**
 * Returns the interface chunk, requesting it only once. A failure forgets the
 * promise, so the next attempt retries the request.
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
