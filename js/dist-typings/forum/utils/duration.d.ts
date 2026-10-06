/**
 * A span of seconds, said the way a person would.
 *
 * "30s", "5m", "1h" rather than "00:00:30" — this appears inline in a sentence
 * ("wait 30s") and beside a channel name, where a clock reading is both longer
 * and harder to skim.
 *
 * Whole units only. Slow mode is chosen from a fixed list of round values, so
 * there is never a 90-second step to render as "1m 30s", and inventing that case
 * would mean carrying it in three translations for nobody's benefit.
 */
export declare function humanDuration(seconds: number): string;
