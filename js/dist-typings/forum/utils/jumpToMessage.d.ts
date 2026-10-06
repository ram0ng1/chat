import type ChatState from "../state/ChatState";
/**
 * Centra uma linha do stream e a destaca por alguns segundos.
 *
 * O alvo é procurado dentro do scroller que contém `from` — o painel de tópico e
 * o canal desenham a mesma `ChatMessage`, e uma busca no documento levaria a
 * rolar o stream errado quando os dois estão abertos.
 *
 * Devolve `false` quando a linha não está na janela carregada, para quem chamou
 * poder avisar em vez de engolir o clique.
 */
export declare function jumpToMessage(id: number | string, from?: HTMLElement | null): boolean;
/**
 * Salta para uma mensagem do canal, carregando antes o trecho do histórico que
 * a separa da janela quando ela é mais antiga do que o que já está na tela.
 *
 * O scroller é relido depois do carregamento porque o redraw pode tê-lo
 * recriado. Devolve `false` só quando a mensagem não pode estar no stream do
 * canal — resposta de tópico, apagada, ou além do limite de páginas.
 */
export declare function revealAndJump(state: ChatState, channelId: number, id: number | string, from?: HTMLElement | null): Promise<boolean>;
