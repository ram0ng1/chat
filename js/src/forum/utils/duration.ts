import app from "flarum/forum/app";

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
export function humanDuration(seconds: number): string {
  const total = Math.max(0, Math.round(seconds));

  if (total >= 3600 && total % 3600 === 0) {
    return app.translator.trans(
      "ramon-chat.forum.duration.hours",
      { count: total / 3600 },
      true,
    );
  }

  if (total >= 60 && total % 60 === 0) {
    return app.translator.trans(
      "ramon-chat.forum.duration.minutes",
      { count: total / 60 },
      true,
    );
  }

  return app.translator.trans(
    "ramon-chat.forum.duration.seconds",
    { count: total },
    true,
  );
}
