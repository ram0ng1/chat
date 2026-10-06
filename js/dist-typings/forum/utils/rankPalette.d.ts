/**
 * Mirror of Ramon\Chat\Rank\RankPalette, for the rank editor's preview.
 *
 * The server already sends the derived colours of every saved rank; this only
 * exists so the preview follows the colour while it is being picked, before
 * saving. Changed there, change here.
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
