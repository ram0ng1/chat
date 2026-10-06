/**
 * Metamorfose entre duas superfícies do chat (FLIP), no mesmo desenho do
 * compositor flutuante do tema Avocado: a superfície nova nasce do retângulo da
 * que a abriu — tamanho, cantos e fundo interpolados — e assenta com um leve
 * passar do ponto, enquanto o miolo entra em cascata no pouso.
 *
 * Quem sai grava a origem com `rememberOrigin`; quem chega a consome com
 * `morphIn`. A origem vale por pouco tempo e só para o destino nomeado, para que
 * uma navegação sem relação nunca herde uma animação velha.
 */
export type MorphTarget = "page" | "drawer";
export declare const reducedMotion: () => boolean;
/**
 * Grava o elemento que está abrindo `target` como ponto de partida. Um elemento
 * sem caixa (escondido, desmontado) não grava nada.
 */
export declare function rememberOrigin(el: Element | null | undefined, target: MorphTarget): void;
/**
 * Leva `el` do retângulo gravado até o lugar dele. Devolve se animou; sem origem
 * recente para este destino, ou com movimento reduzido, não faz nada.
 */
export declare function morphIn(el: Element | null | undefined, target: MorphTarget, contentDelay?: number): boolean;
/** O miolo entrando em cascata quando a superfície está quase pousada. */
export declare function cascadeIn(el: HTMLElement, delay?: number): void;
