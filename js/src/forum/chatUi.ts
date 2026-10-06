/**
 * Fachada do chunk preguiçoso com a interface do chat.
 *
 * Um ponto de entrada só para as três superfícies (página, painel do drawer,
 * navegação de canais), para que o webpack gere um chunk nomeado e registrado
 * em vez de chunks compartilhados numerados, que o Flarum não registra.
 */
export { default as ChatPage } from "./components/ChatPage";
export { default as ChatDrawerPanel } from "./components/ChatDrawerPanel";
export { default as BrowseChannelsPage } from "./components/BrowseChannelsPage";
export { default as ChatSidebar } from "./components/ChatSidebar";
export { default as ChannelView } from "./components/ChannelView";
export { default as ThreadPanel } from "./components/ThreadPanel";
export { default as PinnedPanel } from "./components/PinnedPanel";
export { default as ThreadsList } from "./components/ThreadsList";
export { default as ChatSearch } from "./components/ChatSearch";
export { default as ChatMessage } from "./components/ChatMessage";
export { default as ChatComposer } from "./components/ChatComposer";
export { default as ChatSelectionBar } from "./components/ChatSelectionBar";
export { default as ChatAutocomplete } from "./components/ChatAutocomplete";
export { default as FlaggedMessagesList } from "./components/FlaggedMessagesList";
