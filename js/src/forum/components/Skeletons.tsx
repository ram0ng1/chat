import type Mithril from "mithril";

/**
 * Loading placeholders shaped like the content that replaces them.
 *
 * A spinner tells you something is happening; a skeleton tells you *what* is
 * coming, and — because it occupies the same space — the interface does not jump
 * when the data lands. Each function here mirrors one real surface, so a change to
 * that surface's layout should be mirrored here too; a skeleton that no longer
 * matches is worse than none, because it promises the wrong shape.
 *
 * All of them are plain functions rather than components: they hold no state, and
 * a component would only add a lifecycle nobody needs.
 */

/** Repeats a builder `count` times with a key, which every fragment here needs. */
function repeat(
  count: number,
  build: (index: number) => Mithril.Children,
): Mithril.Children[] {
  return Array.from({ length: count }, (_, index) => build(index));
}

/**
 * Widths are varied per row so a list does not read as a striped block. Derived
 * from the index rather than random, so the layout is stable across redraws.
 */
function width(index: number, steps: number[]): string {
  return steps[index % steps.length] + "%";
}

// ── Message stream ───────────────────────────────────────────────────────────

/**
 * The channel, thread and pinned streams: avatar, author line, message line.
 */
export function MessageStreamSkeleton(rows = 6): Mithril.Children {
  return (
    <div className="ChatSkeleton" aria-hidden="true">
      {repeat(rows, (i) => (
        <div className="ChatSkeleton-row" key={i}>
          <div className="ChatSkeleton-avatar" />
          <div className="ChatSkeleton-lines">
            <div
              className="ChatSkeleton-line"
              style={{ width: width(i, [22, 30, 26]) }}
            />
            <div
              className="ChatSkeleton-line"
              style={{ width: width(i, [70, 45, 85, 55]) }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

// ── Full channel pane ────────────────────────────────────────────────────────

/**
 * What the main pane looks like before a channel is ready: the header bar, the
 * stream and the composer, in their final positions.
 *
 * This is the one that matters most — it is the whole right-hand side of the page
 * on first load, and a lone spinner there leaves the layout to snap into place a
 * second later.
 */
export function ChannelSkeleton(): Mithril.Children {
  return (
    <div className="ChatChannel ChatSkeleton-channel" aria-hidden="true">
      <div className="ChatChannel-header">
        <div className="ChatSkeleton-avatar ChatSkeleton-avatar--small" />
        <div className="ChatSkeleton-line" style={{ width: "140px" }} />
      </div>

      <div className="ChatSkeleton-channelStream">
        {MessageStreamSkeleton(7)}
      </div>

      <div className="ChatSkeleton-composer" />
    </div>
  );
}

// ── Sidebar ──────────────────────────────────────────────────────────────────

/**
 * The channel list: quick links, a section heading, then rows with an icon and a
 * name.
 */
export function SidebarSkeleton(): Mithril.Children {
  return (
    <div className="ChatSkeleton-sidebar" aria-hidden="true">
      {repeat(2, (i) => (
        <div className="ChatSkeleton-quickLink" key={"q" + i}>
          <div className="ChatSkeleton-avatar ChatSkeleton-avatar--tiny" />
          <div
            className="ChatSkeleton-line"
            style={{ width: width(i, [40, 30]) }}
          />
        </div>
      ))}

      <div className="ChatSkeleton-heading" />

      {repeat(5, (i) => (
        <div className="ChatSkeleton-channelRow" key={"c" + i}>
          <div className="ChatSkeleton-avatar ChatSkeleton-avatar--tiny" />
          <div
            className="ChatSkeleton-line"
            style={{ width: width(i, [55, 40, 65, 35, 50]) }}
          />
        </div>
      ))}
    </div>
  );
}

// ── Browse channels ──────────────────────────────────────────────────────────

/**
 * Cards with the icon, title, description and footer.
 *
 * Mirrors the real card's three bands rather than approximating them: the card
 * is a column, and a skeleton laid out as a row makes the list visibly jump into
 * place when the data arrives.
 */
export function BrowseSkeleton(cards = 6): Mithril.Children {
  return (
    <div className="ChatBrowse-list" aria-hidden="true">
      {repeat(cards, (i) => (
        <div className="ChatBrowseCard ChatSkeleton" key={i}>
          <div className="ChatBrowseCard-head">
            <div className="ChatSkeleton-avatar ChatSkeleton-avatar--large" />

            <div className="ChatSkeleton-lines">
              <div
                className="ChatSkeleton-line ChatSkeleton-line--title"
                style={{ width: width(i, [55, 40, 65, 45, 60, 35]) }}
              />
              <div
                className="ChatSkeleton-line ChatSkeleton-line--meta"
                style={{ width: width(i, [30, 22, 38, 26, 34, 20]) }}
              />
            </div>
          </div>

          <div className="ChatSkeleton-lines">
            <div className="ChatSkeleton-line" style={{ width: "92%" }} />
            <div
              className="ChatSkeleton-line"
              style={{ width: width(i, [70, 55, 80, 45, 65, 50]) }}
            />
          </div>

          <div className="ChatBrowseCard-footer">
            <div
              className="ChatSkeleton-line ChatSkeleton-line--meta"
              style={{ width: "45%" }}
            />
            <div className="ChatSkeleton-button" />
          </div>
        </div>
      ))}
    </div>
  );
}

// ── Search results ───────────────────────────────────────────────────────────

export function SearchResultsSkeleton(rows = 5): Mithril.Children {
  return (
    <div className="ChatSkeleton ChatSkeleton--flush" aria-hidden="true">
      {repeat(rows, (i) => (
        <div className="ChatSkeleton-row ChatSkeleton-row--tight" key={i}>
          <div className="ChatSkeleton-avatar ChatSkeleton-avatar--small" />
          <div className="ChatSkeleton-lines">
            <div
              className="ChatSkeleton-line ChatSkeleton-line--meta"
              style={{ width: width(i, [40, 32, 45]) }}
            />
            <div
              className="ChatSkeleton-line"
              style={{ width: width(i, [75, 60, 85, 50]) }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

// ── My threads ───────────────────────────────────────────────────────────────

export function ThreadsSkeleton(rows = 5): Mithril.Children {
  return (
    <div className="ChatSkeleton ChatSkeleton--flush" aria-hidden="true">
      {repeat(rows, (i) => (
        <div className="ChatSkeleton-row ChatSkeleton-row--tight" key={i}>
          <div className="ChatSkeleton-avatar ChatSkeleton-avatar--tiny" />
          <div className="ChatSkeleton-lines">
            <div
              className="ChatSkeleton-line"
              style={{ width: width(i, [50, 65, 40, 58]) }}
            />
            <div
              className="ChatSkeleton-line ChatSkeleton-line--meta"
              style={{ width: "30%" }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

// ── Member list ──────────────────────────────────────────────────────────────

export function MembersSkeleton(rows = 6): Mithril.Children {
  return (
    <div className="ChatSkeleton ChatSkeleton--flush" aria-hidden="true">
      {repeat(rows, (i) => (
        <div className="ChatSkeleton-memberRow" key={i}>
          <div className="ChatSkeleton-avatar ChatSkeleton-avatar--small" />
          <div
            className="ChatSkeleton-line"
            style={{ width: width(i, [45, 32, 55, 38]) }}
          />
        </div>
      ))}
    </div>
  );
}

// ── Edit history ─────────────────────────────────────────────────────────────

export function RevisionsSkeleton(rows = 3): Mithril.Children {
  return (
    <div className="ChatRevisions-list" aria-hidden="true">
      {repeat(rows, (i) => (
        <div className="ChatRevisions-entry ChatSkeleton" key={i}>
          <div
            className="ChatSkeleton-line ChatSkeleton-line--meta"
            style={{ width: width(i, [35, 28, 42]) }}
          />
          <div
            className="ChatSkeleton-line"
            style={{ width: width(i, [80, 60, 90]) }}
          />
        </div>
      ))}
    </div>
  );
}
