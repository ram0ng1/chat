/**
 * Espelho de Ramon\Chat\Rank\RankPalette, para a prévia do editor de cargos.
 *
 * O servidor já manda as cores derivadas de cada cargo salvo; isto só existe
 * para que a prévia acompanhe a cor enquanto ela é escolhida, antes de salvar.
 * Mudou lá, muda aqui.
 */
export declare const LIGHT_SURFACE = "#ffffff";
export declare const DARK_SURFACE = "#222226";
export interface RankColors {
    textColor: string | null;
    nameLight: string | null;
    nameDark: string | null;
}
export declare function isHex(value: unknown): value is string;
export declare function luminance(hex: string): number;
export declare function contrast(a: string, b: string): number;
export declare function deriveRankColors(hex: string | null): RankColors;
