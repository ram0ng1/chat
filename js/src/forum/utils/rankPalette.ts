/**
 * Mirror of Ramon\Chat\Rank\RankPalette, for the rank editor's preview.
 *
 * The server already sends the derived colours of every saved rank; this only
 * exists so the preview follows the colour while it is being picked, before
 * saving. Changed there, change here.
 */

export const LIGHT_SURFACE = "#ffffff";
export const DARK_SURFACE = "#222226";
const LIGHT_TEXT = "#ffffff";
const DARK_TEXT = "#16161a";
const MIN_CONTRAST = 4.5;

export interface RankColors {
  textColor: string | null;
  nameLight: string | null;
  nameDark: string | null;
}

export function isHex(value: unknown): value is string {
  return typeof value === "string" && /^#[0-9a-fA-F]{6}$/.test(value);
}

function channels(hex: string): [number, number, number] {
  const h = hex.replace("#", "");

  return [
    parseInt(h.slice(0, 2), 16),
    parseInt(h.slice(2, 4), 16),
    parseInt(h.slice(4, 6), 16),
  ];
}

export function luminance(hex: string): number {
  const linear = (value: number) => {
    const c = value / 255;

    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  };
  const [r, g, b] = channels(hex);

  return 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
}

export function contrast(a: string, b: string): number {
  const la = luminance(a);
  const lb = luminance(b);

  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

function mix(from: string, to: string, amount: number): string {
  const a = channels(from);
  const b = channels(to);

  return (
    "#" +
    a
      .map((value, i) =>
        Math.round(value + (b[i] - value) * amount)
          .toString(16)
          .padStart(2, "0"),
      )
      .join("")
  );
}

function readableOn(hex: string, surface: string): string {
  if (contrast(hex, surface) >= MIN_CONTRAST) return hex;

  const target = luminance(surface) > 0.5 ? "#000000" : "#ffffff";

  for (let step = 1; step <= 20; step++) {
    const candidate = mix(hex, target, step / 20);

    if (contrast(candidate, surface) >= MIN_CONTRAST) return candidate;
  }

  return target;
}

export function deriveRankColors(hex: string | null): RankColors {
  if (!isHex(hex)) return { textColor: null, nameLight: null, nameDark: null };

  const color = hex.toLowerCase();

  return {
    textColor:
      contrast(color, LIGHT_TEXT) >= contrast(color, DARK_TEXT)
        ? LIGHT_TEXT
        : DARK_TEXT,
    nameLight: readableOn(color, LIGHT_SURFACE),
    nameDark: readableOn(color, DARK_SURFACE),
  };
}
